<?php

use App\Domains\Conversation\Jobs\SendAutoReplyJob;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Mailbox\Models\Mailbox;
use App\Domains\Mailbox\Sync\Fake\FakeMailSource;
use App\Domains\Mailbox\Sync\Jobs\IngestMailMessageJob;
use App\Domains\Mailbox\Sync\MailSyncService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * Auto-reply loop protection, subject cleanup, CC and reply threading on the
 * mail-ledger path (emails:fetch -> MailSyncService -> IngestMailMessageJob).
 * Ported from the tests of the removed ProcessInboundEmailJob.
 */

/** @param array<string, string> $headers */
function ifTRaw(array $headers = [], string $body = 'Hello, I need help.'): string
{
    $headers = array_merge([
        'From' => 'A Customer <customer@example.com>',
        'To' => 'Support <support@souldial.test>',
        'Subject' => 'Help needed',
        'Date' => 'Mon, 3 Nov 2025 09:15:00 +0000',
        'Message-ID' => '<msg-'.uniqid().'@mail.example.com>',
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset="UTF-8"',
    ], $headers);

    $lines = [];
    foreach ($headers as $name => $value) {
        $lines[] = "{$name}: {$value}";
    }

    return implode("\r\n", $lines)."\r\n\r\n".$body."\r\n";
}

/** Adds the messages to a fake Gmail mailbox and runs sync + ingest inline. */
function ifTDeliver(Mailbox $mailbox, string ...$raws): void
{
    static $uid = 1000;
    $source = new FakeMailSource;
    app()->instance(MailSyncService::class, (new MailSyncService($source))->configure(initialLookbackDays: 100_000));
    foreach ($raws as $raw) {
        $uid++;
        $source->addMessage($raw, $uid, (string) (1847650000000000000 + $uid), (string) (1847650000000000000 + $uid), ['\\Inbox']);
    }

    $report = app(MailSyncService::class)->syncNew($mailbox);
    foreach ($report->newMessageIds as $id) {
        app()->call([new IngestMailMessageJob($id), 'handle']);
    }
}

beforeEach(function () {
    Queue::fake();
    Storage::fake();
    $this->mailbox = Mailbox::factory()->create(['email' => 'support@souldial.test']);
});

test('a normal email creates a conversation and thread', function () {
    ifTDeliver($this->mailbox, ifTRaw());

    expect(Conversation::where('mailbox_id', $this->mailbox->id)->count())->toBe(1)
        ->and(Thread::count())->toBe(1);
});

test('automated mail never becomes a conversation or gets an auto-reply', function (array $headers) {
    ifTDeliver($this->mailbox, ifTRaw($headers));

    expect(Conversation::where('mailbox_id', $this->mailbox->id)->count())->toBe(0);
    Queue::assertNotPushed(SendAutoReplyJob::class);
})->with([
    'Auto-Submitted: auto-replied' => [['Auto-Submitted' => 'auto-replied']],
    'Auto-Submitted: auto-generated' => [['Auto-Submitted' => 'auto-generated']],
    'our own X-FusterAI-AutoReply' => [['X-FusterAI-AutoReply' => 'true']],
    'Precedence: bulk' => [['Precedence' => 'bulk']],
    'Precedence: junk' => [['Precedence' => 'junk']],
    'out-of-office subject' => [['Subject' => 'Out of Office: back Monday']],
    'automatic reply subject' => [['Subject' => 'Automatic reply: Help needed']],
]);

test('Auto-Submitted: no is treated as a normal email', function () {
    ifTDeliver($this->mailbox, ifTRaw(['Auto-Submitted' => 'no']));

    expect(Conversation::where('mailbox_id', $this->mailbox->id)->count())->toBe(1);
    Queue::assertPushed(SendAutoReplyJob::class);
});

test('other CC recipients are stored in thread meta, without us or the customer', function () {
    ifTDeliver($this->mailbox, ifTRaw(['Cc' => 'Colleague <colleague@example.com>, support@souldial.test']));

    expect(Thread::sole()->meta['cc'])->toEqual([['email' => 'colleague@example.com', 'name' => 'Colleague']]);
});

test('thread meta cc is an empty array when nobody else was copied', function () {
    ifTDeliver($this->mailbox, ifTRaw());

    expect(Thread::sole()->meta['cc'])->toBe([]);
});

test('forward and reply prefixes are stripped from a new conversation subject', function (string $subject, string $expected) {
    ifTDeliver($this->mailbox, ifTRaw(['Subject' => $subject]));

    expect(Conversation::sole()->subject)->toBe($expected);
})->with([
    'Fwd:' => ['Fwd: Order issue', 'Order issue'],
    'Fw:' => ['Fw: Order issue', 'Order issue'],
    'several prefixes' => ['Fwd: Fw: Re: Order issue', 'Order issue'],
    'no prefix' => ['Order issue', 'Order issue'],
]);

test('a reply with In-Reply-To is added to the existing conversation', function () {
    $first = ifTRaw(['Message-ID' => '<first-1@mail.example.com>']);
    $reply = ifTRaw([
        'Message-ID' => '<reply-1@mail.example.com>',
        'Subject' => 'Re: Help needed',
        'Date' => 'Mon, 3 Nov 2025 11:00:00 +0000',
        'In-Reply-To' => '<first-1@mail.example.com>',
        'References' => '<first-1@mail.example.com>',
    ], 'Any news?');
    ifTDeliver($this->mailbox, $first, $reply);

    expect(Conversation::count())->toBe(1)
        ->and(Thread::count())->toBe(2);
});

test('an unknown In-Reply-To starts a new conversation', function () {
    ifTDeliver($this->mailbox, ifTRaw(['In-Reply-To' => '<nobody-knows@mail.example.com>', 'Subject' => 'Re: something']));

    expect(Conversation::count())->toBe(1)
        ->and(Conversation::sole()->subject)->toBe('something');
});
