<?php

use App\Domains\AI\Jobs\CategorizeConversationJob;
use App\Domains\AI\Jobs\GenerateReplySuggestionJob;
use App\Domains\Automation\Jobs\RunAutomationRulesJob;
use App\Domains\Conversation\Jobs\SendAutoReplyJob;
use App\Domains\Conversation\Jobs\SendReplyJob;
use App\Domains\Conversation\Models\Attachment;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Customer\Models\Customer;
use App\Domains\Mailbox\Models\Mailbox;
use App\Domains\Mailbox\Sync\Backfill\BackfillAlreadyRunning;
use App\Domains\Mailbox\Sync\Backfill\BackfillRunner;
use App\Domains\Mailbox\Sync\Backfill\BackfillService;
use App\Domains\Mailbox\Sync\Backfill\Jobs\RunMailBackfillJob;
use App\Domains\Mailbox\Sync\Backfill\MailBackfillRun;
use App\Domains\Mailbox\Sync\Backfill\MailBackfillThread;
use App\Domains\Mailbox\Sync\Fake\FakeMailSource;
use App\Domains\Mailbox\Sync\IngestResult;
use App\Domains\Mailbox\Sync\Jobs\IngestMailMessageJob;
use App\Domains\Mailbox\Sync\MailIngestService;
use App\Domains\Mailbox\Sync\MailSyncLock;
use App\Domains\Mailbox\Sync\MailSyncService;
use App\Domains\Mailbox\Sync\Models\MailMessage;
use App\Domains\Mailbox\Sync\Models\MailSyncState;
use App\Enums\ConversationStatus;
use App\Events\ConversationUpdated;
use App\Events\NewThreadReceived;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Hooks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

const BF_EMMA_THREAD = '1847650000000000101';

function bfSource(bool $fixtures = true): FakeMailSource
{
    $source = $fixtures ? FakeMailSource::fromFixtures(base_path('tests/Fixtures/mail')) : new FakeMailSource;
    if ($fixtures) {
        // 08-plain-text-quoted.eml carries legacy fusterai ids (<conversation-31@fusterai>,
        // thread-58) that would match whatever row has that id in a long test run.
        $raw = str_replace(['conversation-31@fusterai', 'thread-58-'], ['conversation-999999@fusterai', 'thread-999999-'], (string) file_get_contents(base_path('tests/Fixtures/mail/08-plain-text-quoted.eml')));
        $source->removeMessage(109)->addMessage($raw, 109, '18000000000000000109', '18000000000000000109', ['\\Inbox'], '2025-11-06T20:30:13Z');
    }
    app()->instance(MailSyncService::class, new MailSyncService($source));

    return $source;
}

function bfMailbox(): Mailbox
{
    return Mailbox::factory()->create([
        'email' => 'support@souldial.test',
        'active' => true,
        'imap_config' => ['host' => 'imap.gmail.com', 'port' => 993, 'encryption' => 'ssl', 'username' => 'support@souldial.test', 'password' => 'secret'],
    ]);
}

function bfStart(Mailbox $mailbox, bool $dryRun = false, bool $includeUnanswered = false): MailBackfillRun
{
    return app(BackfillService::class)->start($mailbox, months: 18, dryRun: $dryRun, includeUnanswered: $includeUnanswered, dispatch: false);
}

/** Run steps inline until the chain stops (or $maxSteps). */
function bfRun(MailBackfillRun $run, int $maxSteps = 200): MailBackfillRun
{
    $runner = app(BackfillRunner::class);
    for ($i = 0; $i < $maxSteps; $i++) {
        if ($runner->runStep($run->id, $run->fresh()->job_token) === null) {
            break;
        }
    }

    return $run->fresh();
}

/** One slice at a time until the run reaches $stage. */
function bfRunUntilStage(MailBackfillRun $run, string $stage): MailBackfillRun
{
    config(['mailsync.backfill.max_slices_per_step' => 1]);
    $runner = app(BackfillRunner::class);
    for ($i = 0; $i < 100 && $run->fresh()->stage !== $stage; $i++) {
        $runner->runStep($run->id, $run->fresh()->job_token);
    }
    expect($run->fresh()->stage)->toBe($stage);

    return $run->fresh();
}

/** A reply from Emma in her Gmail thread, sent at $date. */
function bfEmmaReply(string $messageId, string $date, string $text = 'Any news on my order?'): string
{
    return implode("\r\n", [
        'Date: '.Carbon::parse($date)->toRfc2822String(),
        "Message-ID: <{$messageId}>",
        'In-Reply-To: <CAEmma004.p5o4@mail.gmail.com>',
        'References: <CAEmma001.k3j2@mail.gmail.com> <CAEmma004.p5o4@mail.gmail.com>',
        'Subject: Re: Where is my order #1042?',
        'From: Emma Clarke <emma.clarke@example.net>',
        'To: Soul Dial Support <support@souldial.test>',
        'Content-Type: text/plain; charset=UTF-8',
        '',
        $text,
        '',
    ]);
}

function bfEmma(): Conversation
{
    return Conversation::whereHas('customer', fn ($q) => $q->where('email', 'emma.clarke@example.net'))->sole();
}

beforeEach(function () {
    Carbon::setTestNow('2026-11-26 12:00:00');
    Storage::fake();
    config([
        'mailsync.initial_lookback_days' => 0,
        'mailsync.backfill.range_size' => 3,
        'mailsync.backfill.ranges_per_slice' => 1,
        'mailsync.backfill.bodies_per_slice' => 2,
        'mailsync.backfill.threads_per_slice' => 1,
        'mailsync.backfill.connection' => 'sync',
    ]);
    $this->mailbox = bfMailbox();
});

afterEach(function () {
    Carbon::setTestNow();
    Hooks::reset();
});

// ── Dry run ──────────────────────────────────────────────────────────────────

test('a dry run reads headers only and counts what would be imported and why the rest is left out', function () {
    $source = bfSource();

    $run = bfRun(bfStart($this->mailbox, dryRun: true));

    expect($run->status)->toBe(MailBackfillRun::STATUS_COMPLETED)
        ->and($run->stage)->toBe(MailBackfillRun::STAGE_DONE)
        ->and($run->counters['envelopes_fetched'])->toBe(9)
        ->and($run->counters['threads_total'])->toBe(5)
        ->and($run->counters['threads_kept'])->toBe(1)
        ->and($run->counters['messages_to_import'])->toBe(4)
        ->and($run->counters['kept_reasons'])->toBe(['two_way' => 1])
        ->and(collect($run->counters['dropped_reasons'])->sortKeys()->all())->toBe(['auto_reply' => 1, 'bulk' => 1, 'unanswered' => 2])
        ->and(MailMessage::count())->toBe(9)
        ->and(MailMessage::whereNotNull('body_fetched_at')->count())->toBe(0)
        ->and(Conversation::count())->toBe(0)
        ->and($source->calls['fetchBodies'] ?? 0)->toBe(0)
        ->and($source->calls['fetchTextBodies'] ?? 0)->toBe(0);

    $kept = MailBackfillThread::where('decision', 'kept')->sole();
    expect($kept->gm_thrid)->toBe(BF_EMMA_THREAD)
        ->and($kept->inbound_count)->toBe(3)
        ->and($kept->outbound_count)->toBe(1)
        ->and($kept->active)->toBeFalse();
});

test('mail:backfill --dry-run prints the counts', function () {
    bfSource();

    $this->artisan('mail:backfill', ['mailbox' => $this->mailbox->id, '--dry-run' => true, '--months' => 18])
        ->expectsOutputToContain('Dry run #')
        ->expectsOutputToContain('Threads to import')
        ->expectsOutputToContain('unanswered')
        ->assertSuccessful();

    expect(Conversation::count())->toBe(0)
        ->and(MailBackfillRun::sole()->status)->toBe(MailBackfillRun::STATUS_COMPLETED);
});

// ── Full import ──────────────────────────────────────────────────────────────

test('history becomes closed imported conversations in true order with real dates, staff mail attributed', function () {
    $source = bfSource();
    $staff = User::factory()->create(['workspace_id' => $this->mailbox->workspace_id, 'email' => 'support@souldial.test']);

    $run = bfRun(bfStart($this->mailbox));

    expect($run->status)->toBe(MailBackfillRun::STATUS_COMPLETED)
        ->and($run->counters['conversations_created'])->toBe(1)
        ->and($run->counters['messages_ingested'])->toBe(4)
        ->and($run->counters['bodies_fetched'])->toBe(4);

    $emma = bfEmma();
    $threads = $emma->threads()->get();
    expect($emma->status)->toBe(ConversationStatus::Closed)
        ->and($emma->imported)->toBeTrue()
        ->and($emma->backfill_run_id)->toBe($run->id)
        ->and($emma->created_at->eq(Carbon::parse('2025-11-03 09:15:00', 'UTC')))->toBeTrue()
        ->and($emma->last_reply_at->eq(Carbon::parse('2025-11-05 09:12:44', 'UTC')))->toBeTrue()
        ->and($threads->pluck('mailMessage.uid')->all())->toBe([101, 102, 106, 107])
        ->and($threads->pluck('origin')->unique()->all())->toBe([Thread::ORIGIN_IMPORT])
        ->and($threads[1]->user_id)->toBe($staff->id)
        ->and($threads[1]->customer_id)->toBeNull()
        ->and($threads[0]->customer_id)->toBe($emma->customer_id)
        ->and($threads[2]->body_plain)->toContain('glass shade is cracked')
        ->and($threads[3]->created_at->eq(Carbon::parse('2025-11-05 09:12:44', 'UTC')))->toBeTrue()
        ->and(Customer::where('email', 'emma.clarke@example.net')->sole()->created_at->toDateString())->toBe('2025-11-03');

    // Attachments: described, never downloaded or stored.
    expect(Attachment::count())->toBe(0)
        ->and(collect($threads[3]->meta['attachments_not_imported'])->pluck('name')->sort()->values()->all())->toBe(['invoice-1042.pdf', 'shade.png'])
        ->and($source->calls['fetchBodies'] ?? 0)->toBe(0)
        ->and($source->calls['fetchTextBodies'])->toBeGreaterThan(0);
    Storage::assertDirectoryEmpty('/');
});

test('including unanswered threads imports them, one conversation per customer of a shared Gmail thread', function () {
    bfSource();

    $run = bfRun(bfStart($this->mailbox, includeUnanswered: true));

    expect($run->counters['threads_kept'])->toBe(3)
        ->and($run->counters['conversations_created'])->toBe(4)
        ->and(Conversation::where('imported', true)->where('status', 'closed')->count())->toBe(4)
        ->and(Conversation::where('gm_thrid', '1847650000000000103')->count())->toBe(2);
});

test('historical import fires no hooks, notifications, broadcasts, jobs or activity log', function () {
    Queue::fake();
    Notification::fake();
    Event::fake([NewThreadReceived::class, ConversationUpdated::class]);
    $hooks = new ArrayObject;
    foreach (['conversation.created', 'thread.created', 'conversation.updated', 'conversation.closed'] as $hook) {
        Hooks::addAction($hook, fn () => $hooks->append($hook));
    }
    $agent = agentUser(Workspace::find($this->mailbox->workspace_id));
    $activityBefore = DB::table('activity_log')->count();
    bfSource();

    $run = bfRun(bfStart($this->mailbox, includeUnanswered: true));

    expect($run->status)->toBe(MailBackfillRun::STATUS_COMPLETED)
        ->and(Conversation::count())->toBe(4)
        ->and($hooks->getArrayCopy())->toBe([])
        ->and(DB::table('activity_log')->count())->toBe($activityBefore)
        ->and($agent->notifications()->count())->toBe(0);
    foreach ([RunAutomationRulesJob::class, GenerateReplySuggestionJob::class, CategorizeConversationJob::class, SendAutoReplyJob::class, SendReplyJob::class, IngestMailMessageJob::class] as $job) {
        Queue::assertNotPushed($job);
    }
    Notification::assertNothingSent();
    Event::assertNotDispatched(NewThreadReceived::class);
    Event::assertNotDispatched(ConversationUpdated::class);
});

test('a thread still waiting on us is imported open, not closed', function () {
    $source = bfSource();
    $source->addMessage(bfEmmaReply('CAEmma-recent@mail.gmail.com', '2026-11-20 08:00:00'), 100, '1847650000000000100', BF_EMMA_THREAD, ['\\Inbox'], '2026-11-20T08:00:00Z');

    $run = bfRun(bfStart($this->mailbox));

    $emma = bfEmma();
    expect($run->counters['threads_active'])->toBe(1)
        ->and($emma->status)->toBe(ConversationStatus::Open)
        ->and($emma->imported)->toBeFalse()
        ->and($emma->backfill_run_id)->toBe($run->id)
        ->and($emma->threads()->count())->toBe(5);
});

test('drafts are never imported', function () {
    $source = bfSource();
    $source->addMessage(str_replace(
        ['<CAAgent002.q9w8@mail.gmail.com>', 'Sorry for the wait'],
        ['<CADraft@mail.gmail.com>', 'DRAFT TEXT'],
        (string) file_get_contents(base_path('tests/Fixtures/mail/02-agent-reply-from-gmail.eml')),
    ), 99, '1847650000000000099', BF_EMMA_THREAD, ['\\Draft']);

    bfRun(bfStart($this->mailbox));

    expect(bfEmma()->threads()->count())->toBe(4)
        ->and(Thread::where('body_plain', 'like', '%DRAFT TEXT%')->exists())->toBeFalse()
        ->and(MailMessage::where('uid', 99)->value('ingested_at'))->toBeNull();
});

// ── Resume ───────────────────────────────────────────────────────────────────

dataset('failing stages', [
    'metadata sweep' => [MailBackfillRun::STAGE_METADATA, fn (FakeMailSource $s) => $s->failOn('fetchEnvelopes')],
    'body fetch' => [MailBackfillRun::STAGE_BODIES, fn (FakeMailSource $s) => $s->failOn('fetchTextBodies')],
    'materialise' => [MailBackfillRun::STAGE_MATERIALISE, function (FakeMailSource $s) {
        // A body in the next thread went missing: the ingest has to fetch it and the server fails.
        MailMessage::where('uid', 104)->update(['body_fetched_at' => null, 'body_html' => null, 'body_text' => null, 'body_clean' => null]);
        $s->failOn('fetchTextBodies');
    }],
]);

test('a failure in a stage is recorded, backed off and resumed without redoing finished work', function (string $stage, Closure $inject) {
    $source = bfSource();
    $run = bfStart($this->mailbox, includeUnanswered: true);
    $run = bfRunUntilStage($run, $stage);
    if ($stage === MailBackfillRun::STAGE_MATERIALISE) {
        // Get one thread done first, so resume has to skip it.
        app(BackfillRunner::class)->runStep($run->id, $run->job_token);
        expect(MailBackfillThread::whereNotNull('materialised_at')->count())->toBe(1);
    }
    $inject($source);

    $delay = app(BackfillRunner::class)->runStep($run->id, $run->fresh()->job_token);

    $failed = $run->fresh();
    expect($delay)->toBe(30)
        ->and($failed->status)->toBe(MailBackfillRun::STATUS_RUNNING)
        ->and($failed->stage)->toBe($stage)
        ->and($failed->consecutive_errors)->toBe(1)
        ->and($failed->last_error)->toContain('Simulated failure');

    config(['mailsync.backfill.max_slices_per_step' => 500]);
    $done = bfRun($failed);

    expect($done->status)->toBe(MailBackfillRun::STATUS_COMPLETED)
        ->and($done->consecutive_errors)->toBe(0)
        ->and($done->error_count)->toBe(1)
        ->and(Conversation::count())->toBe(4)
        ->and(Thread::count())->toBe(7)
        ->and(MailMessage::count())->toBe(9)
        ->and(bfEmma()->threads()->get()->pluck('mailMessage.uid')->all())->toBe([101, 102, 106, 107]);
})->with('failing stages');

test('an interrupted thread filter is recomputed from scratch, not duplicated', function () {
    bfSource();
    $run = bfRunUntilStage(bfStart($this->mailbox), MailBackfillRun::STAGE_BODIES);
    $first = MailBackfillThread::where('run_id', $run->id)->orderBy('thread_key')->get(['thread_key', 'decision', 'reason'])->toArray();

    $run->forceFill(['stage' => MailBackfillRun::STAGE_FILTER])->save();
    bfRunUntilStage($run, MailBackfillRun::STAGE_BODIES);

    expect(MailBackfillThread::where('run_id', $run->id)->orderBy('thread_key')->get(['thread_key', 'decision', 'reason'])->toArray())->toBe($first);
});

test('after too many errors in a row the run fails, and resume carries on', function () {
    config(['mailsync.backfill.max_consecutive_errors' => 2]);
    $source = bfSource();
    $run = bfStart($this->mailbox);
    $runner = app(BackfillRunner::class);

    $source->failOn('fetchEnvelopes');
    expect($runner->runStep($run->id, $run->job_token))->toBe(30);
    $source->failOn('fetchEnvelopes');
    expect($runner->runStep($run->id, $run->job_token))->toBeNull()
        ->and($run->fresh()->status)->toBe(MailBackfillRun::STATUS_FAILED);

    Queue::fake();
    expect(app(BackfillService::class)->resume($run->fresh()))->toBeTrue();
    Queue::assertPushed(RunMailBackfillJob::class, 1);

    $done = bfRun($run->fresh());
    expect($done->status)->toBe(MailBackfillRun::STATUS_COMPLETED)
        ->and(Conversation::count())->toBe(1);
});

// ── Re-runs, live sync ───────────────────────────────────────────────────────

test('re-running a completed backfill creates nothing', function () {
    bfSource();
    bfRun(bfStart($this->mailbox, includeUnanswered: true));
    $counts = [Conversation::count(), Thread::count(), Customer::count(), MailMessage::count()];

    $again = bfRun(bfStart($this->mailbox, includeUnanswered: true));

    expect($again->status)->toBe(MailBackfillRun::STATUS_COMPLETED)
        ->and($again->counters['conversations_created'])->toBe(0)
        ->and($again->counters['threads_kept'])->toBe(0)
        ->and($again->counters['skipped_reasons'])->toBe(['already_imported' => 3])
        ->and([Conversation::count(), Thread::count(), Customer::count(), MailMessage::count()])->toBe($counts);
});

test('live sync keeps working during a backfill and the two share the cursor safely', function () {
    $source = bfSource();
    // Live sync has run before: everything so far is history.
    app(MailSyncService::class)->syncNew($this->mailbox);
    $run = bfStart($this->mailbox);
    $runner = app(BackfillRunner::class);
    config(['mailsync.backfill.max_slices_per_step' => 1]);
    $runner->runStep($run->id, $run->job_token); // first metadata range

    // While emails:fetch holds the lock, the sweep waits instead of racing it.
    $lock = MailSyncLock::for($this->mailbox->id);
    $lock->get();
    $before = $run->fresh()->counters['envelopes_fetched'];
    expect($runner->runStep($run->id, $run->job_token))->toBe(BackfillRunner::LOCK_WAIT_SECONDS)
        ->and($run->fresh()->counters['envelopes_fetched'])->toBe($before);
    $lock->release();

    // New mail from Emma arrives and the live path handles it, mid-backfill.
    $source->addMessage(bfEmmaReply('CAEmma-live@mail.gmail.com', '2026-11-26 11:00:00'), 110, '1847650000000000110', BF_EMMA_THREAD, ['\\Inbox'], '2026-11-26T11:00:00Z');
    Queue::fake();
    $this->artisan('emails:fetch')->assertSuccessful();
    Queue::assertPushed(IngestMailMessageJob::class, 1);
    $live = Queue::pushed(IngestMailMessageJob::class)->first();
    $live->handle(app(MailIngestService::class));
    $conversation = bfEmma();
    expect($conversation->status)->toBe(ConversationStatus::Open)
        ->and($conversation->imported)->toBeFalse();

    config(['mailsync.backfill.max_slices_per_step' => 500]);
    $done = bfRun($run);

    $state = MailSyncState::where('mailbox_id', $this->mailbox->id)->sole();
    expect($done->status)->toBe(MailBackfillRun::STATUS_COMPLETED)
        ->and($state->last_uid)->toBe(110)
        ->and($state->live_from_uid)->toBe(110)
        ->and($state->backfill_completed_at)->not->toBeNull()
        ->and(Conversation::count())->toBe(1)
        ->and(bfEmma()->id)->toBe($conversation->id)
        ->and(bfEmma()->threads()->get()->pluck('mailMessage.uid')->all())->toBe([101, 102, 106, 107, 110])
        ->and(bfEmma()->status)->toBe(ConversationStatus::Open)
        ->and(bfEmma()->imported)->toBeFalse()
        ->and(bfEmma()->backfill_run_id)->toBeNull();
});

test('a conversation that already exists from live sync is not duplicated or reopened', function () {
    Queue::fake();
    $source = bfSource();
    app(MailSyncService::class)->syncNew($this->mailbox);
    $source->addMessage(bfEmmaReply('CAEmma-live@mail.gmail.com', '2026-11-25 11:00:00'), 110, '1847650000000000110', BF_EMMA_THREAD, ['\\Inbox'], '2026-11-25T11:00:00Z');
    $report = app(MailSyncService::class)->syncNew($this->mailbox);
    app(MailIngestService::class)->ingest($report->newMessageIds[0]);
    $live = bfEmma();
    $live->update(['status' => ConversationStatus::Closed]);

    bfRun(bfStart($this->mailbox));

    expect(Conversation::count())->toBe(1)
        ->and($live->fresh()->status)->toBe(ConversationStatus::Closed)
        ->and($live->fresh()->imported)->toBeFalse()
        ->and($live->threads()->count())->toBe(5);
});

// ── Lifecycle ────────────────────────────────────────────────────────────────

test('start queues the chain on ai-bulk / redis-long and refuses a second backfill for the mailbox', function () {
    config(['mailsync.backfill.connection' => 'redis-long']);
    Queue::fake();
    bfSource();

    $run = app(BackfillService::class)->start($this->mailbox);

    Queue::assertPushed(RunMailBackfillJob::class, fn (RunMailBackfillJob $job) => $job->connection === 'redis-long'
        && $job->queue === 'ai-bulk' && $job->runId === $run->id && $job->token === $run->job_token);
    expect(fn () => app(BackfillService::class)->start($this->mailbox))->toThrow(BackfillAlreadyRunning::class);
});

test('pause stops the chain, resume continues it under a new token, cancel ends it', function () {
    Queue::fake();
    bfSource();
    $service = app(BackfillService::class);
    $runner = app(BackfillRunner::class);
    $run = bfStart($this->mailbox);
    $oldToken = $run->job_token;
    config(['mailsync.backfill.max_slices_per_step' => 1]);
    $runner->runStep($run->id, $oldToken);

    expect($service->pause($run))->toBeTrue();
    $fetched = $run->fresh()->counters['envelopes_fetched'];
    expect($runner->runStep($run->id, $oldToken))->toBeNull()
        ->and($run->fresh()->counters['envelopes_fetched'])->toBe($fetched);

    expect($service->resume($run))->toBeTrue();
    Queue::assertPushed(RunMailBackfillJob::class, fn ($job) => $job->token === $run->fresh()->job_token);
    expect($run->fresh()->job_token)->not->toBe($oldToken)
        // A step queued before the pause is superseded.
        ->and($runner->runStep($run->id, $oldToken))->toBeNull()
        ->and($runner->runStep($run->id, $run->fresh()->job_token))->not->toBeNull()
        ->and($run->fresh()->counters['envelopes_fetched'])->toBeGreaterThan($fetched);

    expect($service->cancel($run))->toBeTrue()
        ->and($run->fresh()->status)->toBe(MailBackfillRun::STATUS_CANCELLED)
        ->and($runner->runStep($run->id, $run->fresh()->job_token))->toBeNull()
        ->and($service->resume($run->fresh()))->toBeFalse();

    // A cancelled run no longer blocks a new one.
    expect(bfStart($this->mailbox)->status)->toBe(MailBackfillRun::STATUS_QUEUED);
});

test('the queued job chain runs a whole import', function () {
    bfSource();
    config(['mailsync.backfill.max_slices_per_step' => 2]);

    $run = app(BackfillService::class)->start($this->mailbox);

    expect($run->fresh()->status)->toBe(MailBackfillRun::STATUS_COMPLETED)
        ->and(bfEmma()->threads()->count())->toBe(4);
});

test('the watchdog re-queues a run that stopped making progress', function () {
    Queue::fake();
    bfSource();
    $run = bfStart($this->mailbox);
    $run->forceFill(['status' => MailBackfillRun::STATUS_RUNNING, 'heartbeat_at' => now()->subMinutes(30)])->save();
    $other = Mailbox::factory()->create(['imap_config' => ['host' => 'imap.example.com', 'username' => 'help@example.com', 'password' => 'x']]);
    $fresh = bfStart($other);
    $fresh->forceFill(['status' => MailBackfillRun::STATUS_RUNNING, 'heartbeat_at' => now()->subMinute()])->save();

    $this->artisan('mail:backfill', ['--recover' => true])->expectsOutputToContain('Re-queued 1')->assertSuccessful();

    Queue::assertPushed(RunMailBackfillJob::class, 1);
    Queue::assertPushed(RunMailBackfillJob::class, fn ($job) => $job->runId === $run->id && $job->token === $run->fresh()->job_token);
});

// ── Undo ─────────────────────────────────────────────────────────────────────

test('undo removes only what that run created and leaves live conversations alone', function () {
    Queue::fake();
    $source = bfSource();
    $source->removeMessage(109); // Emma's thread and the contact form are enough here
    app(MailSyncService::class)->syncNew($this->mailbox);
    $source->addMessage(bfEmmaReply('CAEmma-live@mail.gmail.com', '2026-11-25 11:00:00'), 110, '1847650000000000110', BF_EMMA_THREAD, ['\\Inbox'], '2026-11-25T11:00:00Z');
    $report = app(MailSyncService::class)->syncNew($this->mailbox);
    app(MailIngestService::class)->ingest($report->newMessageIds[0]);
    $live = bfEmma();
    $liveThread = $live->threads()->sole();
    $unrelated = Conversation::factory()->create(['workspace_id' => $this->mailbox->workspace_id, 'mailbox_id' => $this->mailbox->id]);

    $run = bfRun(bfStart($this->mailbox, includeUnanswered: true));
    expect(Conversation::count())->toBe(4)
        ->and($live->threads()->count())->toBe(5);

    expect(app(BackfillService::class)->undo($run->fresh(), dispatch: false))->toBeTrue();
    $undone = bfRun($run);

    expect($undone->status)->toBe(MailBackfillRun::STATUS_UNDONE)
        ->and(Conversation::withTrashed()->pluck('id')->sort()->values()->all())->toBe(collect([$live->id, $unrelated->id])->sort()->values()->all())
        ->and($live->threads()->pluck('id')->all())->toBe([$liveThread->id])
        ->and($live->fresh()->last_reply_at->eq(Carbon::parse('2026-11-25 11:00:00')))->toBeTrue()
        ->and(MailMessage::whereNotNull('backfill_run_id')->count())->toBe(0)
        ->and(MailMessage::where('uid', '<', 110)->whereNotNull('ingested_at')->count())->toBe(0)
        ->and(MailMessage::where('uid', 110)->value('thread_id'))->toBe($liveThread->id);

    // History can be imported again afterwards.
    $again = bfRun(bfStart($this->mailbox, includeUnanswered: true));
    expect($again->counters['conversations_created'])->toBe(2)
        ->and($live->threads()->count())->toBe(5);
});

test('undo keeps an imported conversation that has since received live mail, minus the history', function () {
    Queue::fake();
    $source = bfSource();
    app(MailSyncService::class)->syncNew($this->mailbox);
    $run = bfRun(bfStart($this->mailbox));
    $imported = bfEmma();

    // Emma writes again: the live path reopens the imported conversation.
    $source->addMessage(bfEmmaReply('CAEmma-again@mail.gmail.com', '2026-11-26 09:00:00'), 110, '1847650000000000110', BF_EMMA_THREAD, ['\\Inbox'], '2026-11-26T09:00:00Z');
    $report = app(MailSyncService::class)->syncNew($this->mailbox);
    $result = app(MailIngestService::class)->ingest($report->newMessageIds[0]);
    expect($result->outcome)->toBe(IngestResult::CREATED)
        ->and($result->conversation->id)->toBe($imported->id)
        ->and($imported->fresh()->status)->toBe(ConversationStatus::Open)
        ->and($imported->fresh()->imported)->toBeFalse();

    app(BackfillService::class)->undo($run->fresh(), dispatch: false);
    bfRun($run);

    expect(Conversation::count())->toBe(1)
        ->and($imported->fresh()->threads()->get()->pluck('mailMessage.uid')->all())->toBe([110]);
});
