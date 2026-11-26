<?php

use App\Domains\AI\Jobs\CategorizeConversationJob;
use App\Domains\AI\Jobs\GenerateReplySuggestionJob;
use App\Domains\Automation\Jobs\RunAutomationRulesJob;
use App\Domains\Automation\Models\AutomationRule;
use App\Domains\Conversation\Jobs\SendAutoReplyJob;
use App\Domains\Conversation\Jobs\SendReplyJob;
use App\Domains\Conversation\Models\Attachment;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Customer\Models\Customer;
use App\Domains\Mailbox\Models\Mailbox;
use App\Domains\Mailbox\Sync\Fake\FakeMailSource;
use App\Domains\Mailbox\Sync\IngestResult;
use App\Domains\Mailbox\Sync\Jobs\IngestMailMessageJob;
use App\Domains\Mailbox\Sync\MailSyncService;
use App\Domains\Mailbox\Sync\Models\MailMessage;
use App\Domains\Mailbox\Sync\SyncReport;
use App\Enums\ConversationStatus;
use App\Events\ConversationUpdated;
use App\Events\NewThreadReceived;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Hooks;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

const MI_EMMA_FIRST = 'CAEmma001.k3j2@mail.gmail.com';

/** Fake Gmail source bound into the container, so FetchEmails / the ingest job's re-fetch use it. */
function miSource(bool $all = false): FakeMailSource
{
    $source = $all ? FakeMailSource::fromFixtures(base_path('tests/Fixtures/mail')) : new FakeMailSource;
    app()->instance(MailSyncService::class, (new MailSyncService($source))->configure(initialLookbackDays: 100_000));

    return $source;
}

function miAdd(FakeMailSource $source, string $file): void
{
    static $manifest = null;
    $manifest ??= collect(json_decode((string) file_get_contents(base_path('tests/Fixtures/mail/manifest.json')), true))->keyBy('file')->all();
    $e = $manifest[$file];

    $source->addMessage(
        (string) file_get_contents(base_path('tests/Fixtures/mail/'.$file)),
        (int) $e['uid'], $e['gm_msgid'], $e['gm_thrid'], $e['labels'], $e['internal_date'],
    );
}

/** @param list<int> $ids */
function miIngest(array $ids, bool $historical = false): void
{
    foreach ($ids as $id) {
        app()->call([new IngestMailMessageJob($id, $historical), 'handle']);
    }
}

/** What emails:fetch + the email-inbound worker do, inline. */
function miSync(Mailbox $mailbox, bool $historical = false): SyncReport
{
    $report = app(MailSyncService::class)->syncNew($mailbox);
    miIngest($report->newMessageIds, $historical);

    return $report;
}

/** A raw message from a fixture with some headers replaced. */
function miRaw(string $file, array $replace): string
{
    return str_replace(array_keys($replace), array_values($replace), (string) file_get_contents(base_path('tests/Fixtures/mail/'.$file)));
}

function miEmma(): Conversation
{
    return Conversation::whereHas('customer', fn ($q) => $q->where('email', 'emma.clarke@example.net'))->sole();
}

beforeEach(function () {
    Storage::fake();
    $this->mailbox = Mailbox::factory()->create(['email' => 'support@souldial.test']);
    $this->workspace = Workspace::find($this->mailbox->workspace_id);
});

// ── Threading ────────────────────────────────────────────────────────────────

test('the fixture mailbox becomes the right conversations, with Gmail replies as agent messages', function () {
    Queue::fake();
    miSource(all: true);

    miSync($this->mailbox);

    $emma = miEmma();
    $threads = $emma->threads()->get();
    expect($threads->pluck('mailMessage.message_id')->all())->toBe([
        MI_EMMA_FIRST, 'CAAgent002.q9w8@mail.gmail.com', 'CAEmma003.z7x6@mail.gmail.com', 'CAEmma004.p5o4@mail.gmail.com',
    ])
        ->and($threads[1]->origin)->toBe(Thread::ORIGIN_GMAIL)
        ->and($threads[1]->customer_id)->toBeNull()
        ->and($threads[1]->user_id)->toBeNull()
        ->and($threads[0]->customer_id)->toBe($emma->customer_id)
        ->and($emma->gm_thrid)->toBe('1847650000000000101');

    // Emma, Liam (contact form), Aiko (contact form), the plain-text sender.
    expect(Conversation::count())->toBe(4)
        ->and(Customer::where('email', 'mailer@shopify.com')->exists())->toBeFalse();

    // Out-of-office and newsletter: ledger rows kept, no conversation.
    $skipped = MailMessage::whereIn('uid', [105, 108])->get();
    expect($skipped->pluck('ingest_outcome')->unique()->all())->toBe([IngestResult::SKIPPED_AUTOMATED])
        ->and($skipped->pluck('thread_id')->filter()->all())->toBe([])
        ->and(MailMessage::whereNull('ingested_at')->count())->toBe(0);
});

test('thread bodies keep the raw HTML, plain text is the cleaned reply, and times are the real send times', function () {
    Queue::fake();
    miSource(all: true);

    miSync($this->mailbox);

    $emma = miEmma();
    $followUp = $emma->threads()->get()[2];
    expect($followUp->body)->toContain('gmail_quote')
        ->and($followUp->body_plain)->toContain('glass shade is cracked')
        ->and($followUp->body_plain)->not->toContain('Sorry for the wait')
        ->and($followUp->sent_at->eq(Carbon::parse('2025-11-04 18:03:41', 'UTC')))->toBeTrue()
        ->and($followUp->created_at->eq(Carbon::parse('2025-11-04 18:03:41', 'UTC')))->toBeTrue()
        ->and($emma->last_reply_at->eq(Carbon::parse('2025-11-05 09:12:44', 'UTC')))->toBeTrue();
});

test('a customer reply to an app reply stays in the conversation; the app reply threads under the customer message and its Sent copy is not duplicated', function () {
    Queue::fake();
    $source = miSource();
    miAdd($source, '01-customer-enquiry.eml');
    miSync($this->mailbox);
    $conversation = miEmma();

    // Agent replies from fusterai.
    config(['mail.mailers.smtp' => ['transport' => 'array']]);
    app('mail.manager')->purge('smtp');
    $agent = agentUser($this->workspace);
    $reply = $conversation->threads()->create([
        'user_id' => $agent->id, 'type' => 'message', 'body' => '<p>It is on its way.</p>', 'body_plain' => 'It is on its way.', 'source' => 'web',
    ]);
    (new SendReplyJob($reply, $conversation))->handle();

    $sent = app('mail.manager')->mailer('smtp')->getSymfonyTransport()->messages()->sole();
    $headers = $sent->getOriginalMessage()->getHeaders();
    $reply->refresh();
    expect($headers->get('In-Reply-To')->getBodyAsString())->toBe('<'.MI_EMMA_FIRST.'>')
        ->and($headers->get('References')->getBodyAsString())->toBe('<'.MI_EMMA_FIRST.'>')
        ->and($headers->get('X-FusterAI-Thread')->getBodyAsString())->toBe((string) $reply->id)
        ->and($reply->message_id)->toStartWith('thread-'.$reply->id.'-')
        ->and($reply->message_id)->toEndWith('@fusterai')
        ->and($headers->get('Message-ID')->getBodyAsString())->toBe('<'.$reply->message_id.'>');

    // Gmail's Sent copy of that reply shows up in All Mail.
    $source->addMessage($sent->toString(), 201, '1847650000000000201', '1847650000000000101', ['\\Sent']);
    miSync($this->mailbox);

    $sentRow = MailMessage::where('uid', 201)->sole();
    expect($sentRow->ingest_outcome)->toBe(IngestResult::LINKED)
        ->and($sentRow->thread_id)->toBe($reply->id)
        ->and($conversation->threads()->where('type', 'message')->count())->toBe(2);

    // The customer answers it (different Gmail thread id: only References connect it).
    $answer = miRaw('03-customer-followup-top-posted.eml', [
        "References: <CAEmma001.k3j2@mail.gmail.com>\n <CAAgent002.q9w8@mail.gmail.com>" => 'References: <'.MI_EMMA_FIRST.'> <'.$reply->message_id.'>',
        'In-Reply-To: <CAAgent002.q9w8@mail.gmail.com>' => 'In-Reply-To: <'.$reply->message_id.'>',
    ]);
    $source->addMessage($answer, 202, '1847650000000000202', '1847650000000000999', ['\\Inbox']);
    miSync($this->mailbox);

    expect(Conversation::count())->toBe(1)
        ->and($conversation->threads()->where('type', 'message')->count())->toBe(3)
        ->and(MailMessage::where('uid', 202)->value('conversation_id'))->toBe($conversation->id);
});

test('the Sent copy is linked by X-FusterAI-Thread even when Gmail rewrote the Message-ID, and the real id is stored', function () {
    Queue::fake();
    $source = miSource();
    miAdd($source, '01-customer-enquiry.eml');
    miSync($this->mailbox);
    $conversation = miEmma();
    $reply = $conversation->threads()->create([
        'user_id' => agentUser($this->workspace)->id, 'type' => 'message', 'body' => '<p>Hi</p>', 'source' => 'web',
        'message_id' => 'thread-1-abc@fusterai',
    ]);

    $copy = miRaw('02-agent-reply-from-gmail.eml', [
        'Message-ID: <CAAgent002.q9w8@mail.gmail.com>' => "Message-ID: <rewritten-by-gmail@mail.gmail.com>\nX-FusterAI-Thread: {$reply->id}",
    ]);
    $source->addMessage($copy, 201, '1847650000000000201', '1847650000000000101', ['\\Sent']);
    miSync($this->mailbox);

    expect(Thread::where('type', 'message')->count())->toBe(2)
        ->and($reply->fresh()->message_id)->toBe('rewritten-by-gmail@mail.gmail.com')
        ->and($reply->fresh()->mail_message_id)->toBe(MailMessage::where('uid', 201)->value('id'));
});

test('a reply written in Gmail is an agent message in the right conversation and does not reopen it or start AI work', function () {
    Queue::fake();
    $source = miSource();
    miAdd($source, '01-customer-enquiry.eml');
    miSync($this->mailbox);
    $conversation = miEmma();
    $conversation->update(['status' => ConversationStatus::Closed]);

    Queue::fake();
    miAdd($source, '02-agent-reply-from-gmail.eml');
    miSync($this->mailbox);

    $thread = Thread::where('origin', Thread::ORIGIN_GMAIL)->sole();
    expect($thread->conversation_id)->toBe($conversation->id)
        ->and($thread->customer_id)->toBeNull()
        ->and($thread->meta['from_email'])->toBe('support@souldial.test')
        ->and($thread->body_plain)->toContain('Sorry for the wait')
        ->and($thread->body_plain)->not->toContain('wrote:')
        ->and($conversation->fresh()->status)->toBe(ConversationStatus::Closed);

    Queue::assertNotPushed(GenerateReplySuggestionJob::class);
    Queue::assertNotPushed(CategorizeConversationJob::class);
    Queue::assertNotPushed(SendAutoReplyJob::class);
    Queue::assertNotPushed(RunAutomationRulesJob::class);
});

test('a Gmail reply sent as a staff alias is attributed to that user', function () {
    Queue::fake();
    $this->mailbox->update(['aliases' => ['sam@souldial.test']]);
    $sam = User::factory()->create(['workspace_id' => $this->workspace->id, 'email' => 'Sam@souldial.test']);
    $source = miSource();
    miAdd($source, '01-customer-enquiry.eml');
    miSync($this->mailbox);

    $source->addMessage(miRaw('02-agent-reply-from-gmail.eml', [
        'From: Soul Dial Support <support@souldial.test>' => 'From: Sam <sam@souldial.test>',
    ]), 102, '1847650000000000102', '1847650000000000101', ['\\Sent']);
    miSync($this->mailbox);

    $thread = Thread::where('origin', Thread::ORIGIN_GMAIL)->sole();
    expect($thread->user_id)->toBe($sam->id)
        ->and($thread->customer_id)->toBeNull()
        ->and(Customer::where('email', 'sam@souldial.test')->exists())->toBeFalse();
});

test('a new conversation started from Gmail gets the recipient as customer and waits on them', function () {
    Queue::fake();
    $source = miSource();
    $source->addMessage(implode("\n", [
        'Date: Mon, 10 Nov 2025 10:00:00 +0000',
        'Message-ID: <CAOutreach01@mail.gmail.com>',
        'Subject: Your replacement shade',
        'From: Soul Dial Support <support@souldial.test>',
        'To: Noah Berg <noah@example.com>',
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'Hi Noah, your replacement is ready.',
    ]), 300, '1847650000000000300', '1847650000000000300', ['\\Sent']);

    miSync($this->mailbox);

    $conversation = Conversation::sole();
    expect($conversation->customer->email)->toBe('noah@example.com')
        ->and($conversation->status)->toBe(ConversationStatus::Pending)
        ->and($conversation->threads()->sole()->origin)->toBe(Thread::ORIGIN_GMAIL)
        ->and($conversation->threads()->sole()->customer_id)->toBeNull();
    Queue::assertNotPushed(SendAutoReplyJob::class);
    Queue::assertNotPushed(GenerateReplySuggestionJob::class);
});

// ── Contact forms ────────────────────────────────────────────────────────────

test('contact-form mail maps to the Reply-To customer and two customers in one Gmail thread stay apart', function () {
    Queue::fake();
    $source = miSource();
    miAdd($source, '04-shopify-contact-form.eml');
    miAdd($source, '04b-shopify-contact-form-other-customer.eml');

    miSync($this->mailbox);

    $conversations = Conversation::with('customer')->orderBy('id')->get();
    expect($conversations)->toHaveCount(2)
        ->and($conversations->pluck('customer.email')->all())->toBe(['liam.patel@example.org', 'aiko.tanaka@example.jp'])
        ->and($conversations->pluck('gm_thrid')->unique()->all())->toBe(['1847650000000000103'])
        ->and(Customer::where('email', 'mailer@shopify.com')->exists())->toBeFalse()
        ->and(Customer::where('email', 'support@souldial.test')->exists())->toBeFalse();
});

// ── Idempotency ──────────────────────────────────────────────────────────────

test('running sync and ingest twice creates nothing new', function () {
    Queue::fake();
    miSource(all: true);
    miSync($this->mailbox);
    $counts = [Conversation::count(), Thread::count(), Attachment::count(), MailMessage::count()];

    $again = miSync($this->mailbox);
    miIngest(MailMessage::pluck('id')->all());

    expect($again->inserted)->toBe(0)
        ->and([Conversation::count(), Thread::count(), Attachment::count(), MailMessage::count()])->toBe($counts);
});

test('a dispatch that loses the race to another worker for the same ledger row creates nothing', function () {
    Queue::fake();
    $source = miSource();
    miAdd($source, '01-customer-enquiry.eml');
    $report = app(MailSyncService::class)->syncNew($this->mailbox);
    $id = $report->newMessageIds[0];

    // Worker B finishes the whole ingest right after worker A's first
    // (unlocked) read, so A holds a stale row when it takes the lock.
    $raced = false;
    MailMessage::retrieved(function (MailMessage $row) use (&$raced, $id) {
        if (! $raced && $row->id === $id) {
            $raced = true;
            miIngest([$id]);
        }
    });

    miIngest([$id]);

    expect($raced)->toBeTrue()
        ->and(Thread::count())->toBe(1)
        ->and(Conversation::count())->toBe(1)
        ->and(MailMessage::find($id)->thread_id)->toBe(Thread::sole()->id);
    Queue::assertPushed(SendAutoReplyJob::class, 1);
});

test('mail the old fetcher already imported before the cutover is linked, not duplicated', function () {
    Queue::fake();
    $customer = Customer::factory()->create(['workspace_id' => $this->workspace->id, 'email' => 'emma.clarke@example.net']);
    $legacy = Conversation::factory()->create(['workspace_id' => $this->workspace->id, 'mailbox_id' => $this->mailbox->id, 'customer_id' => $customer->id]);
    $legacyThread = $legacy->threads()->create([
        'customer_id' => $customer->id, 'type' => 'message', 'body' => 'Hi there', 'source' => 'email',
        'meta' => ['message_id' => '<'.MI_EMMA_FIRST.'>'],
    ]);
    $source = miSource();
    miAdd($source, '01-customer-enquiry.eml');
    miAdd($source, '03-customer-followup-top-posted.eml');

    miSync($this->mailbox);

    expect(Conversation::count())->toBe(1)
        ->and(MailMessage::where('uid', 101)->value('thread_id'))->toBe($legacyThread->id)
        ->and($legacyThread->fresh()->message_id)->toBe(MI_EMMA_FIRST)
        ->and($legacy->threads()->count())->toBe(2);
});

// ── Inbound side effects ─────────────────────────────────────────────────────

test('a customer reply reopens the conversation and clears its snooze', function () {
    Queue::fake();
    $source = miSource();
    miAdd($source, '01-customer-enquiry.eml');
    miSync($this->mailbox);
    $conversation = miEmma();
    $conversation->update(['status' => ConversationStatus::Pending, 'snoozed_until' => now()->addDays(3)]);

    miAdd($source, '03-customer-followup-top-posted.eml');
    miSync($this->mailbox);

    $conversation->refresh();
    expect($conversation->status)->toBe(ConversationStatus::Open)
        ->and($conversation->snoozed_until)->toBeNull();
});

test('a blocked customer gets spam status and no AI job, automation or auto-reply', function () {
    Queue::fake();
    Customer::factory()->create(['workspace_id' => $this->workspace->id, 'email' => 'emma.clarke@example.net', 'is_blocked' => true]);
    $source = miSource();
    miAdd($source, '01-customer-enquiry.eml');

    miSync($this->mailbox);

    expect(miEmma()->status)->toBe(ConversationStatus::Spam);
    Queue::assertNotPushed(GenerateReplySuggestionJob::class);
    Queue::assertNotPushed(CategorizeConversationJob::class);
    Queue::assertNotPushed(RunAutomationRulesJob::class);
    Queue::assertNotPushed(SendAutoReplyJob::class);
});

test('a normal customer message queues automation, AI and the auto-reply', function () {
    Queue::fake();
    $source = miSource();
    miAdd($source, '01-customer-enquiry.eml');

    miSync($this->mailbox);

    Queue::assertPushed(RunAutomationRulesJob::class, fn ($job) => $job->trigger === 'conversation.created');
    Queue::assertPushed(GenerateReplySuggestionJob::class, 1);
    Queue::assertPushed(CategorizeConversationJob::class, 1);
    Queue::assertPushedOn('email-outbound', SendAutoReplyJob::class);
});

test('automation rules run on inbound mail, including the conversation.replied trigger', function () {
    Queue::fake();
    AutomationRule::factory()->create([
        'workspace_id' => $this->workspace->id,
        'trigger' => 'conversation.replied',
        'actions' => [['type' => 'set_priority', 'value' => 'high']],
    ]);
    $source = miSource();
    miAdd($source, '01-customer-enquiry.eml');
    miSync($this->mailbox);

    miAdd($source, '03-customer-followup-top-posted.eml');
    miSync($this->mailbox);

    $replied = Queue::pushed(RunAutomationRulesJob::class, fn ($job) => $job->trigger === 'conversation.replied');
    expect($replied)->toHaveCount(1);
    $replied->first()->handle();

    expect(miEmma()->priority->value)->toBe('high');
    // An auto-reply goes out for the first message only.
    Queue::assertPushed(SendAutoReplyJob::class, 1);
});

test('an X-Auto-Response-Suppress sender gets a conversation but no auto-reply', function () {
    Queue::fake();
    $source = miSource();
    $source->addMessage(miRaw('01-customer-enquiry.eml', [
        'MIME-Version: 1.0' => "MIME-Version: 1.0\nX-Auto-Response-Suppress: All",
    ]), 101, '1847650000000000101', '1847650000000000101', ['\\Inbox']);

    miSync($this->mailbox);

    expect(Conversation::count())->toBe(1);
    Queue::assertNotPushed(SendAutoReplyJob::class);
});

// ── Attachments ──────────────────────────────────────────────────────────────

test('attachments are stored and inline images are rewritten to the attachment URL', function () {
    Queue::fake();
    miSource(all: true);

    miSync($this->mailbox);

    $thread = Thread::whereHas('mailMessage', fn ($q) => $q->where('uid', 107))->sole();
    $png = $thread->attachments()->where('mime_type', 'image/png')->sole();
    $pdf = $thread->attachments()->where('mime_type', 'application/pdf')->sole();

    expect($png->inline)->toBeTrue()
        ->and($pdf->inline)->toBeFalse()
        ->and($pdf->filename)->toBe('invoice-1042.pdf')
        ->and($thread->body)->toContain('src="/attachments/'.$png->id.'"')
        ->and($thread->body)->not->toContain('cid:')
        ->and(collect($thread->meta['cc'])->pluck('email')->all())->toBe(['tom.clarke@example.net']);
    Storage::assertExists($png->path);
    Storage::assertExists($pdf->path);
});

test('attachments above the configured cap are skipped and listed in the thread meta', function () {
    Queue::fake();
    config(['mailsync.max_attachment_bytes' => 10]);
    miSource(all: true);

    miSync($this->mailbox);

    $thread = Thread::whereHas('mailMessage', fn ($q) => $q->where('uid', 107))->sole();
    expect($thread->attachments()->count())->toBe(0)
        ->and(collect($thread->meta['skipped_attachments'])->pluck('name')->sort()->values()->all())->toBe(['invoice-1042.pdf', 'shade.png']);
});

test('the attachment route serves images inline to staff of the workspace only', function () {
    Queue::fake();
    miSource(all: true);
    miSync($this->mailbox);
    $png = Attachment::where('mime_type', 'image/png')->sole();
    $pdf = Attachment::where('mime_type', 'application/pdf')->sole();

    $this->actingAs(agentUser($this->workspace))->get($png->url)
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Type', 'image/png');
    expect($this->actingAs(agentUser($this->workspace))->get($pdf->url)->headers->get('Content-Disposition'))->toStartWith('attachment');

    $this->actingAs(agentUser())->get($png->url)->assertForbidden();
});

// ── Historical mode ──────────────────────────────────────────────────────────

test('historical mode imports closed conversations without any side effect', function () {
    Queue::fake();
    Notification::fake();
    Event::fake([NewThreadReceived::class, ConversationUpdated::class]);
    $hooks = new ArrayObject;
    Hooks::addAction('conversation.created', fn () => $hooks->append('conversation.created'));
    Hooks::addAction('thread.created', fn () => $hooks->append('thread.created'));
    $agent = agentUser($this->workspace);
    miSource(all: true);

    miSync($this->mailbox, historical: true);

    $conversations = Conversation::all();
    expect($conversations)->toHaveCount(4)
        ->and($conversations->every(fn (Conversation $c) => $c->imported && $c->status === ConversationStatus::Closed))->toBeTrue()
        ->and(Thread::where('origin', '!=', Thread::ORIGIN_IMPORT)->count())->toBe(0)
        ->and($hooks->getArrayCopy())->toBe([]);
    foreach ([RunAutomationRulesJob::class, GenerateReplySuggestionJob::class, CategorizeConversationJob::class, SendAutoReplyJob::class, SendReplyJob::class] as $job) {
        Queue::assertNotPushed($job);
    }
    Notification::assertNothingSent();
    Event::assertNotDispatched(NewThreadReceived::class);
    Event::assertNotDispatched(ConversationUpdated::class);
    expect($agent->notifications()->count())->toBe(0);
});

// ── Outbound headers ─────────────────────────────────────────────────────────

test('a reply on a conversation with no inbound ledger message falls back to the legacy conversation id', function () {
    config(['mail.mailers.smtp' => ['transport' => 'array']]);
    app('mail.manager')->purge('smtp');
    $customer = Customer::factory()->create(['workspace_id' => $this->workspace->id]);
    $conversation = Conversation::factory()->create([
        'workspace_id' => $this->workspace->id, 'mailbox_id' => $this->mailbox->id, 'customer_id' => $customer->id, 'channel_type' => 'email',
    ]);
    $thread = $conversation->threads()->create(['user_id' => agentUser($this->workspace)->id, 'type' => 'message', 'body' => '<p>Hello</p>', 'source' => 'web']);

    (new SendReplyJob($thread, $conversation))->handle();

    $headers = app('mail.manager')->mailer('smtp')->getSymfonyTransport()->messages()->sole()->getOriginalMessage()->getHeaders();
    expect($headers->get('In-Reply-To')->getBodyAsString())->toBe("<conversation-{$conversation->id}@fusterai>")
        ->and($headers->get('References')->getBodyAsString())->toBe("<conversation-{$conversation->id}@fusterai>");
});

test('the reply References carry the customer message chain plus its id', function () {
    Queue::fake();
    $source = miSource();
    miAdd($source, '01-customer-enquiry.eml');
    miAdd($source, '02-agent-reply-from-gmail.eml');
    miAdd($source, '03-customer-followup-top-posted.eml');
    miSync($this->mailbox);
    $conversation = miEmma();

    config(['mail.mailers.smtp' => ['transport' => 'array']]);
    app('mail.manager')->purge('smtp');
    $thread = $conversation->threads()->create(['user_id' => agentUser($this->workspace)->id, 'type' => 'message', 'body' => '<p>Sending one now</p>', 'source' => 'web']);
    (new SendReplyJob($thread, $conversation))->handle();

    $headers = app('mail.manager')->mailer('smtp')->getSymfonyTransport()->messages()->sole()->getOriginalMessage()->getHeaders();
    expect($headers->get('In-Reply-To')->getBodyAsString())->toBe('<CAEmma003.z7x6@mail.gmail.com>')
        ->and($headers->get('References')->getBodyAsString())->toBe('<CAEmma001.k3j2@mail.gmail.com> <CAAgent002.q9w8@mail.gmail.com> <CAEmma003.z7x6@mail.gmail.com>');
});

test('the auto-reply threads under the customer message', function () {
    config(['mail.default' => 'array']);
    Queue::fake();
    $this->mailbox->update(['auto_reply_config' => ['enabled' => true, 'subject' => 'Got it', 'body' => 'Thanks']]);
    $source = miSource();
    miAdd($source, '01-customer-enquiry.eml');
    miSync($this->mailbox);

    Mail::swap($manager = new MailManager(app()));
    (new SendAutoReplyJob(miEmma()))->handle();

    $headers = $manager->mailer('array')->getSymfonyTransport()->messages()->sole()->getOriginalMessage()->getHeaders();
    expect($headers->get('In-Reply-To')->getBodyAsString())->toBe('<'.MI_EMMA_FIRST.'>')
        ->and($headers->get('Auto-Submitted')->getBodyAsString())->toBe('auto-replied');
});
