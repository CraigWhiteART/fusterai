<?php

use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Mailbox\Models\Mailbox;
use App\Domains\Mailbox\Sync\Fake\FakeMailSource;
use App\Domains\Mailbox\Sync\Jobs\IngestMailMessageJob;
use App\Domains\Mailbox\Sync\MailSyncService;
use App\Domains\Mailbox\Sync\Models\MailMessage;
use App\Domains\Mailbox\Sync\Models\MailSyncState;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

test('emails:fetch reports when no imap mailboxes exist', function () {
    $this->artisan('emails:fetch')
        ->expectsOutput('No active IMAP mailboxes found.')
        ->assertSuccessful();
});

test('emails:fetch skips mailboxes with a blank imap host', function () {
    $workspace = Workspace::factory()->create();
    Mailbox::factory()->create([
        'workspace_id' => $workspace->id,
        'email' => 'support@example.com',
        'active' => true,
        'imap_config' => [
            'host' => '',
            'port' => '993',
            'encryption' => 'ssl',
            'username' => 'support@example.com',
            'password' => 'app-password-here',
        ],
    ]);

    $this->artisan('emails:fetch')
        ->expectsOutputToContain('IMAP host/username missing')
        ->assertSuccessful();
});

// ── Ledger sync (MailSyncService) ─────────────────────────────────────────────

function fetchMailbox(array $overrides = []): Mailbox
{
    return Mailbox::factory()->create(array_merge([
        'email' => 'support@souldial.test',
        'active' => true,
        'imap_config' => ['host' => 'imap.gmail.com', 'port' => 993, 'encryption' => 'ssl', 'username' => 'support@souldial.test', 'password' => 'secret'],
    ], $overrides));
}

function fetchFake(): FakeMailSource
{
    $source = FakeMailSource::fromFixtures(base_path('tests/Fixtures/mail'));
    app()->instance(MailSyncService::class, new MailSyncService($source));

    return $source;
}

test('emails:fetch syncs the ledger and queues one ingest job per new message on email-inbound', function () {
    Queue::fake();
    config(['mailsync.initial_lookback_days' => 100_000]);
    fetchFake();
    $mailbox = fetchMailbox();

    $this->artisan('emails:fetch')->assertSuccessful();

    $rows = MailMessage::where('mailbox_id', $mailbox->id)->orderBy('id')->pluck('id')->all();
    expect($rows)->toHaveCount(9);
    Queue::assertPushed(IngestMailMessageJob::class, 9);
    Queue::assertPushedOn('email-inbound', IngestMailMessageJob::class);
    expect(Queue::pushed(IngestMailMessageJob::class)->map->mailMessageId->sort()->values()->all())->toBe($rows);

    // Nothing new on the next run.
    Queue::fake();
    $this->artisan('emails:fetch')->assertSuccessful();
    Queue::assertNotPushed(IngestMailMessageJob::class);
});

test('emails:fetch starts at the top of the mailbox when no lookback is configured', function () {
    Queue::fake();
    config(['mailsync.initial_lookback_days' => 0]);
    fetchFake();
    fetchMailbox();

    $this->artisan('emails:fetch')->assertSuccessful();

    expect(MailMessage::count())->toBe(0)
        ->and(MailSyncState::sole()->last_uid)->toBe(109);
    Queue::assertNothingPushed();
});

test('emails:fetch end to end: inbound mail becomes conversations and the server is only read', function () {
    Queue::fakeExcept([IngestMailMessageJob::class]);
    config(['mailsync.initial_lookback_days' => 100_000]);
    Storage::fake();
    $source = fetchFake();
    fetchMailbox();

    $this->artisan('emails:fetch')->assertSuccessful();

    // Emma (4 messages), Liam, Aiko, Jürgen; out-of-office and newsletter skipped.
    expect(Conversation::count())->toBe(4)
        ->and(Thread::where('type', 'message')->count())->toBe(7);
    // The MailSource contract has no write operation; these are all the calls made.
    expect(array_keys($source->calls))->each->toBeIn(['syncFolders', 'folderState', 'fetchEnvelopes', 'fetchBodies', 'firstUidOnOrAfter']);
});

test('emails:fetch --mailbox limits the run to the given mailboxes', function () {
    Queue::fake();
    config(['mailsync.initial_lookback_days' => 100_000]);
    fetchFake();
    $one = fetchMailbox();
    $two = fetchMailbox(['email' => 'other@souldial.test']);

    $this->artisan('emails:fetch', ['--mailbox' => [$two->id]])->assertSuccessful();

    expect(MailMessage::where('mailbox_id', $one->id)->count())->toBe(0)
        ->and(MailMessage::where('mailbox_id', $two->id)->count())->toBe(9);
});

test('a failing mailbox does not stop the others', function () {
    Queue::fake();
    config(['mailsync.initial_lookback_days' => 100_000]);
    $source = fetchFake();
    $broken = fetchMailbox();
    $fine = fetchMailbox(['email' => 'other@souldial.test']);
    $source->failOn('syncFolders', 1);

    $this->artisan('emails:fetch')
        ->expectsOutputToContain('Error syncing support@souldial.test')
        ->assertFailed();

    expect(MailMessage::where('mailbox_id', $broken->id)->count())->toBe(0)
        ->and(MailMessage::where('mailbox_id', $fine->id)->count())->toBe(9);
});

test('emails:fetch skips a mailbox whose sync is already running', function () {
    Queue::fake();
    fetchFake();
    $mailbox = fetchMailbox();
    $lock = Cache::lock('emails:fetch:mailbox:'.$mailbox->id, 60);
    $lock->get();

    $this->artisan('emails:fetch')
        ->expectsOutputToContain('another sync is running')
        ->assertSuccessful();

    expect(MailSyncState::count())->toBe(0);
    $lock->release();
});

test('live ledger rows that never got ingested are queued again', function () {
    Queue::fake();
    config(['mailsync.initial_lookback_days' => 100_000]);
    fetchFake();
    fetchMailbox();
    $this->artisan('emails:fetch')->assertSuccessful();

    // Row 0 was ingested, rows 1-7 are stranded (job lost), row 8 is too fresh to retry.
    $rows = MailMessage::orderBy('id')->get();
    MailMessage::query()->update(['created_at' => now()->subMinutes(30)]);
    $rows[0]->forceFill(['ingested_at' => now(), 'ingest_outcome' => 'created'])->save();
    MailMessage::whereKey($rows[8]->id)->update(['created_at' => now()]);

    Queue::fake();
    $this->artisan('emails:fetch')->assertSuccessful();

    $requeued = Queue::pushed(IngestMailMessageJob::class)->map->mailMessageId->sort()->values()->all();
    expect($requeued)->toBe($rows->slice(1, 7)->pluck('id')->values()->all());
});

test('emails:fetch uses the mail ledger, not the old INBOX UNSEEN fetch', function () {
    $src = file_get_contents(app_path('Console/Commands/FetchEmails.php'));

    expect($src)->toContain('syncNew(')
        ->and($src)->not->toContain('ImapProcessedUid')
        ->and($src)->not->toContain('ProcessInboundEmailJob')
        ->and($src)->not->toContain('unseen()');
});
