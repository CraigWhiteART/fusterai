<?php

use App\Domains\Automation\Jobs\RunAutomationRulesJob;
use App\Domains\Conversation\Jobs\SendAutoReplyJob;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Customer\Models\Customer;
use App\Domains\Mailbox\Models\Mailbox;
use App\Domains\Mailbox\Sync\Fake\FakeMailSource;
use App\Domains\Mailbox\Sync\InboxReconciler;
use App\Domains\Mailbox\Sync\Jobs\IngestMailMessageJob;
use App\Domains\Mailbox\Sync\MailSyncService;
use App\Domains\Mailbox\Sync\Models\MailMessage;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * Mail sent from one of the mailbox's own addresses that is not (yet) in its
 * aliases, the Shopify staff order notification, and mail too old to act on.
 */

/** @param array<string, string> $headers */
function roaRaw(array $headers = [], string $body = 'Hello there.'): string
{
    $headers = array_merge([
        'From' => 'A Customer <customer@example.com>',
        'To' => 'Support <support@souldial.test>',
        'Subject' => 'Help needed',
        'Date' => 'Mon, 3 Nov 2025 09:15:00 +0000',
        'Message-ID' => '<roa-'.uniqid().'@mail.example.com>',
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset="UTF-8"',
    ], $headers);

    $lines = [];
    foreach ($headers as $name => $value) {
        $lines[] = "{$name}: {$value}";
    }

    return implode("\r\n", $lines)."\r\n\r\n".$body."\r\n";
}

/** @param list<array{0: string, 1: string}> $messages raw message, gmail thread id */
function roaDeliver(Mailbox $mailbox, array $messages): void
{
    static $uid = 5000;
    $source = new FakeMailSource;
    app()->instance(MailSyncService::class, (new MailSyncService($source))->configure(initialLookbackDays: 100_000));
    foreach ($messages as [$raw, $thrid]) {
        $uid++;
        $source->addMessage($raw, $uid, (string) (1900000000000000000 + $uid), $thrid, ['\\Inbox']);
    }

    foreach (app(MailSyncService::class)->syncNew($mailbox)->newMessageIds as $id) {
        app()->call([new IngestMailMessageJob($id), 'handle']);
    }
}

beforeEach(function () {
    Queue::fake();
    Storage::fake();
    $this->mailbox = Mailbox::factory()->create(['email' => 'support@souldial.test']);
});

test('a Shopify staff order notification never becomes a conversation', function () {
    roaDeliver($this->mailbox, [
        [roaRaw(['From' => 'Soul Dial <hello@shop.test>', 'To' => 'hello@shop.test', 'Subject' => '[Soul Dial] Order #SD1234 placed by Jane Doe']), '1'],
        [roaRaw(['Subject' => 'Re: [Soul Dial] Order #SD1234 placed by Jane Doe']), '2'],
    ]);

    expect(Conversation::count())->toBe(1)
        ->and(Conversation::sole()->customer->email)->toBe('customer@example.com');
});

test('mail too old to act on becomes a conversation without automation or auto-reply', function () {
    config(['mailsync.live_effects_max_age_hours' => 24]);
    roaDeliver($this->mailbox, [[roaRaw(), '1']]);

    expect(Conversation::count())->toBe(1);
    Queue::assertNotPushed(RunAutomationRulesJob::class);
    Queue::assertNotPushed(SendAutoReplyJob::class);

    roaDeliver($this->mailbox, [[roaRaw(['From' => 'New <new@example.com>', 'Date' => now()->subHour()->toRfc2822String()]), '2']]);

    expect(Conversation::count())->toBe(2);
    Queue::assertPushed(RunAutomationRulesJob::class, 1);
    Queue::assertPushed(SendAutoReplyJob::class, 1);
});

test('mail:repair-own-address undoes conversations created from an unlisted own address', function () {
    roaDeliver($this->mailbox, [
        // The customer writes; staff answer from an alias fusterai does not know.
        [roaRaw(['Subject' => 'Where is my order', 'Message-ID' => '<q1@mail.example.com>']), '10'],
        [roaRaw([
            'From' => 'Soul Dial <hello@shop.test>', 'To' => 'customer@example.com', 'Subject' => 'Re: Where is my order',
            'Date' => 'Mon, 3 Nov 2025 10:15:00 +0000', 'In-Reply-To' => '<q1@mail.example.com>', 'References' => '<q1@mail.example.com>',
        ]), '10'],
        // A self-addressed memo.
        [roaRaw(['From' => 'Soul Dial <hello@shop.test>', 'To' => 'hello@shop.test', 'Subject' => 'Stock count']), '11'],
    ]);

    // Wrong: the alias is a customer, with its own conversation.
    expect(Customer::where('email', 'hello@shop.test')->exists())->toBeTrue()
        ->and(Conversation::count())->toBe(2);

    $this->artisan('mail:repair-own-address', ['address' => ['hello@shop.test'], '--mailbox' => $this->mailbox->id])->assertSuccessful();
    expect(Conversation::count())->toBe(2); // dry run

    $this->artisan('mail:repair-own-address', ['address' => ['Hello@Shop.test'], '--mailbox' => $this->mailbox->id, '--apply' => true])->assertSuccessful();

    $conversation = Conversation::sole();
    $threads = $conversation->threads()->orderBy('created_at')->get();

    expect($conversation->customer->email)->toBe('customer@example.com')
        ->and($threads)->toHaveCount(2)
        ->and($threads[0]->customer_id)->toBe($conversation->customer_id)
        ->and($threads[1]->customer_id)->toBeNull()
        ->and(Customer::where('email', 'hello@shop.test')->exists())->toBeFalse()
        ->and($this->mailbox->refresh()->aliases)->toBe(['hello@shop.test'])
        ->and(MailMessage::where('from_email', 'hello@shop.test')->pluck('direction')->map->value->sort()->values()->all())->toBe(['internal', 'outbound'])
        ->and(MailMessage::where('ingest_outcome', 'skipped_internal')->count())->toBe(1);

    // Running it again changes nothing.
    $this->artisan('mail:repair-own-address', ['address' => ['hello@shop.test'], '--mailbox' => $this->mailbox->id, '--apply' => true])->assertSuccessful();
    expect(Conversation::count())->toBe(1)
        ->and(Conversation::sole()->threads()->count())->toBe(2);
});

test('customer mail already archived in Gmail is closed history, not work', function () {
    $source = new FakeMailSource;
    app()->instance(MailSyncService::class, (new MailSyncService($source))->configure(initialLookbackDays: 100_000));
    $source->addMessage(roaRaw(['From' => 'Done <done@example.com>']), 7001, '1900000000000007001', '71', []);
    $source->addMessage(roaRaw(['From' => 'Waiting <waiting@example.com>']), 7002, '1900000000000007002', '72', ['\Inbox']);

    foreach (app(MailSyncService::class)->syncNew($this->mailbox)->newMessageIds as $id) {
        app()->call([new IngestMailMessageJob($id), 'handle']);
    }

    $done = Conversation::whereHas('customer', fn ($q) => $q->where('email', 'done@example.com'))->sole();
    $waiting = Conversation::whereHas('customer', fn ($q) => $q->where('email', 'waiting@example.com'))->sole();

    expect($done->status->value)->toBe('closed')
        ->and($done->imported)->toBeTrue()
        ->and($waiting->status->value)->toBe('open')
        ->and($waiting->imported)->toBeFalse();
    Queue::assertPushed(RunAutomationRulesJob::class, 1);
});

/** Sync, ingest, then the Inbox check, as emails:fetch and its worker do. */
function roaFetch(Mailbox $mailbox, ?int $maxClose = 25): array
{
    foreach (app(MailSyncService::class)->syncNew($mailbox)->newMessageIds as $id) {
        app()->call([new IngestMailMessageJob($id), 'handle']);
    }

    return app(InboxReconciler::class)->reconcile($mailbox, maxClose: $maxClose);
}

function roaSource(): FakeMailSource
{
    $source = new FakeMailSource;
    app()->instance(MailSyncService::class, (new MailSyncService($source))->configure(initialLookbackDays: 100_000));

    return $source;
}

test('archiving in Gmail closes the conversation; a new customer message reopens it', function () {
    $source = roaSource();
    $source->addMessage(roaRaw(['From' => 'One <one@example.com>', 'Message-ID' => '<one-1@mail.example.com>']), 8001, '1900000000000008001', '81', ['\\Inbox']);
    $source->addMessage(roaRaw(['From' => 'Two <two@example.com>']), 8002, '1900000000000008002', '82', ['\\Inbox']);

    roaFetch($this->mailbox);
    $one = Conversation::whereHas('customer', fn ($q) => $q->where('email', 'one@example.com'))->sole();
    expect(Conversation::where('status', 'open')->count())->toBe(2);

    // Mail that has only just been synced is never closed.
    $source->setLabels(8001, []);
    roaFetch($this->mailbox);
    expect($one->refresh()->status->value)->toBe('open');

    MailMessage::query()->update(['created_at' => now()->subHour()]);
    $this->artisan('mail:close-archived', ['--mailbox' => [$this->mailbox->id]])->assertSuccessful();
    expect($one->refresh()->status->value)->toBe('open'); // dry run

    $report = roaFetch($this->mailbox);

    expect($report['closed'])->toBe(1)
        ->and($one->refresh()->status->value)->toBe('closed')
        ->and(Conversation::where('status', 'open')->count())->toBe(1)
        ->and(MailMessage::where('gm_msgid', '1900000000000008001')->sole()->hasLabel('\\Inbox'))->toBeFalse();

    // The customer writes again: back to open.
    $source->addMessage(roaRaw([
        'From' => 'One <one@example.com>', 'Subject' => 'Re: Help needed', 'Date' => now()->subMinute()->toRfc2822String(),
        'In-Reply-To' => '<one-1@mail.example.com>', 'References' => '<one-1@mail.example.com>',
    ]), 8003, '1900000000000008003', '81', ['\\Inbox']);
    roaFetch($this->mailbox);

    expect($one->refresh()->status->value)->toBe('open')
        ->and($one->threads()->count())->toBe(2);
});

test('the scheduled Inbox check refuses to close more conversations than its limit', function () {
    $source = roaSource();
    $source->addMessage(roaRaw(['From' => 'One <one@example.com>']), 8101, '1900000000000008101', '91', ['\\Inbox']);
    $source->addMessage(roaRaw(['From' => 'Two <two@example.com>']), 8102, '1900000000000008102', '92', ['\\Inbox']);
    roaFetch($this->mailbox);
    MailMessage::query()->update(['created_at' => now()->subHour()]);
    $source->setLabels(8101, [])->setLabels(8102, []);

    $report = roaFetch($this->mailbox, maxClose: 1);
    expect($report['capped'])->toBeTrue()
        ->and(Conversation::where('status', 'open')->count())->toBe(2);

    $this->artisan('mail:close-archived', ['--mailbox' => [$this->mailbox->id], '--apply' => true])->assertSuccessful();
    expect(Conversation::where('status', 'open')->count())->toBe(0);
});

test('mail:close-archived --include-unsynced keeps pre-ledger conversations that are still in the Inbox', function () {
    $source = roaSource();
    $source->addMessage(roaRaw(['Message-ID' => '<still-here@mail.example.com>']), 8201, '1900000000000008201', '95', ['\\Inbox']);

    $make = function (string $messageId) {
        $conversation = Conversation::factory()->create(['mailbox_id' => $this->mailbox->id, 'workspace_id' => $this->mailbox->workspace_id, 'status' => 'open']);
        $thread = $conversation->threads()->create(['type' => 'message', 'body' => 'x', 'source' => 'email', 'meta' => ['message_id' => '<'.$messageId.'>']]);
        $thread->forceFill(['created_at' => now()->subDays(5)])->save();

        return $conversation;
    };
    $kept = $make('still-here@mail.example.com');
    $gone = $make('archived-long-ago@mail.example.com');

    $this->artisan('mail:close-archived', ['--mailbox' => [$this->mailbox->id], '--apply' => true])->assertSuccessful();
    expect($gone->refresh()->status->value)->toBe('open');

    $this->artisan('mail:close-archived', ['--mailbox' => [$this->mailbox->id], '--include-unsynced' => true, '--apply' => true])->assertSuccessful();

    expect($kept->refresh()->status->value)->toBe('open')
        ->and($gone->refresh()->status->value)->toBe('closed');
});
