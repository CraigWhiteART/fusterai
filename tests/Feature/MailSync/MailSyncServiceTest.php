<?php

use App\Domains\Conversation\Models\Conversation;
use App\Domains\Mailbox\Models\Mailbox;
use App\Domains\Mailbox\Sync\Data\FolderRef;
use App\Domains\Mailbox\Sync\Enums\MailClassification;
use App\Domains\Mailbox\Sync\Enums\MailDirection;
use App\Domains\Mailbox\Sync\Exceptions\MailSourceException;
use App\Domains\Mailbox\Sync\Exceptions\UidValidityChanged;
use App\Domains\Mailbox\Sync\Fake\FakeMailSource;
use App\Domains\Mailbox\Sync\MailSyncService;
use App\Domains\Mailbox\Sync\Models\MailMessage;
use App\Domains\Mailbox\Sync\Models\MailSyncState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

function syncSource(bool $gmail = true): FakeMailSource
{
    return FakeMailSource::fromFixtures(base_path('tests/Fixtures/mail'), $gmail);
}

function syncService(FakeMailSource $source, int $rangeSize = 3): MailSyncService
{
    return (new MailSyncService($source))->configure(rangeSize: $rangeSize);
}

/** A brand-new message (fresh Message-ID) based on a fixture. */
function syncNewRaw(string $messageId, string $subject = 'Another question'): string
{
    $raw = (string) file_get_contents(base_path('tests/Fixtures/mail/01-customer-enquiry.eml'));

    return str_replace(
        ['<CAEmma001.k3j2@mail.gmail.com>', 'Subject: Where is my order #1042?'],
        ["<{$messageId}>", "Subject: {$subject}"],
        $raw,
    );
}

beforeEach(function () {
    $this->mailbox = Mailbox::factory()->create(['email' => 'support@souldial.test']);
});

test('first syncNew starts at the top of All Mail and then picks up only new mail, with bodies', function () {
    $source = syncSource();
    $service = syncService($source);

    $first = $service->syncNew($this->mailbox);

    $state = MailSyncState::where('mailbox_id', $this->mailbox->id)->sole();
    expect($first->inserted)->toBe(0)
        ->and(MailMessage::count())->toBe(0)
        ->and($state->folder)->toBe(FakeMailSource::ALL_MAIL)
        ->and($state->last_uid)->toBe(109)
        ->and($state->live_from_uid)->toBe(110)
        ->and($state->backfill_lowest_uid)->toBe(110)
        ->and($state->uidvalidity)->toBe(1)
        ->and($source->closed)->toBeTrue();

    $source->addMessage(syncNewRaw('CAEmma-new-1@mail.gmail.com'), 110, '1847650000000000110', '1847650000000000110', ['\\Inbox'], '2025-11-07T08:00:00Z');
    $second = $service->syncNew($this->mailbox);

    $row = MailMessage::sole();
    expect($second->inserted)->toBe(1)
        ->and($second->newMessageIds)->toBe([$row->id])
        ->and($second->bodiesFetched)->toBe(1)
        ->and($row->uid)->toBe(110)
        ->and($row->message_id)->toBe('CAEmma-new-1@mail.gmail.com')
        ->and($row->body_fetched_at)->not->toBeNull()
        ->and($row->body_clean)->toContain('walnut dial lamp')
        ->and($row->direction)->toBe(MailDirection::Inbound);
});

test('syncNew with a lookback imports every fixture into the ledger with the right fields', function () {
    Queue::fake();
    $source = syncSource();
    $service = syncService($source)->configure(initialLookbackDays: 100_000);

    $report = $service->syncNew($this->mailbox);

    expect($report->inserted)->toBe(9)
        ->and($report->ranges)->toBe(3)
        ->and($report->bodiesFetched)->toBe(9)
        ->and(MailMessage::count())->toBe(9);

    $byUid = MailMessage::all()->keyBy('uid');

    // 20-digit Gmail ids survive numeric(20) round trip exactly.
    expect($byUid[109]->gm_msgid)->toBe('18000000000000000109')
        ->and($byUid[109]->gm_thrid)->toBe('18000000000000000109')
        ->and($byUid[101]->gm_thrid)->toBe('1847650000000000101');

    expect($byUid[102]->direction)->toBe(MailDirection::Outbound)
        ->and($byUid[102]->customer_email)->toBe('emma.clarke@example.net')
        ->and($byUid[102]->labels)->toBe(['\\Sent'])
        ->and($byUid[102]->in_reply_to)->toBe('CAEmma001.k3j2@mail.gmail.com');

    // jsonb normalises object key order, hence toEqual for address lists.
    expect($byUid[103]->customer_email)->toBe('liam.patel@example.org')
        ->and($byUid[103]->from_email)->toBe('mailer@shopify.com')
        ->and($byUid[103]->reply_to)->toEqual([['email' => 'liam.patel@example.org', 'name' => 'Liam Patel']])
        ->and($byUid[103]->classification)->toBe(MailClassification::Support)
        ->and($byUid[103]->sent_at->format('Y-m-d H:i:s'))->toBe('2025-11-03 16:02:09');

    expect($byUid[105]->classification)->toBe(MailClassification::Auto)
        ->and($byUid[105]->headers['auto-submitted'])->toBe('auto-replied')
        ->and($byUid[108]->classification)->toBe(MailClassification::Bulk)
        ->and($byUid[106]->references)->toBe(['CAEmma001.k3j2@mail.gmail.com', 'CAAgent002.q9w8@mail.gmail.com']);

    expect($byUid[107]->has_attachments)->toBeTrue()
        ->and($byUid[107]->labels)->toBe(['\\Inbox', 'Kunden/Rückfragen'])
        ->and($byUid[107]->cc)->toEqual([['email' => 'tom.clarke@example.net', 'name' => 'Clarke, Tom']])
        ->and($byUid[101]->has_attachments)->toBeFalse()
        ->and($byUid[101]->internal_date->format('Y-m-d H:i:s'))->toBe('2025-11-03 09:15:02');

    // Ledger only: no conversations, no jobs.
    expect(Conversation::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('running syncNew twice, or again from a wiped cursor, creates nothing new', function () {
    $source = syncSource();
    $service = syncService($source)->configure(initialLookbackDays: 100_000);

    $service->syncNew($this->mailbox);
    $again = $service->syncNew($this->mailbox);

    expect($again->inserted)->toBe(0)
        ->and($again->fetched)->toBe(0)
        ->and(MailMessage::count())->toBe(9);

    MailSyncState::query()->delete();
    $fromScratch = $service->syncNew($this->mailbox);

    expect($fromScratch->fetched)->toBe(9)
        ->and($fromScratch->inserted)->toBe(0)
        ->and($fromScratch->updated)->toBe(0)
        ->and(MailMessage::count())->toBe(9);
});

test('backfillMetadata walks down to the since date, envelopes only, and is idempotent', function () {
    $source = syncSource();
    $service = syncService($source);
    $service->syncNew($this->mailbox); // live sync starts at the top

    $report = $service->backfillMetadata($this->mailbox, Carbon::parse('2025-11-01'));

    $state = MailSyncState::sole();
    expect($report->inserted)->toBe(9)
        ->and($report->completed)->toBeTrue()
        ->and($report->ranges)->toBe(3)
        ->and($state->backfill_stop_uid)->toBe(101)
        ->and($state->backfill_lowest_uid)->toBe(101)
        ->and($state->backfill_completed_at)->not->toBeNull()
        ->and($state->backfill_since->toDateString())->toBe('2025-11-01')
        ->and(MailMessage::whereNotNull('body_fetched_at')->count())->toBe(0)
        ->and($source->calls['fetchBodies'] ?? 0)->toBe(0);

    $again = $service->backfillMetadata($this->mailbox, Carbon::parse('2025-11-01'));

    expect($again->inserted)->toBe(0)
        ->and($again->ranges)->toBe(0)
        ->and(MailMessage::count())->toBe(9);
});

test('backfillMetadata stops at the first UID on or after the date', function () {
    $service = syncService(syncSource());

    $report = $service->backfillMetadata($this->mailbox, Carbon::parse('2025-11-05'));

    expect($report->inserted)->toBe(3)
        ->and(MailMessage::orderBy('uid')->pluck('uid')->all())->toBe([107, 108, 109])
        ->and(MailSyncState::sole()->backfill_stop_uid)->toBe(107);
});

test('backfill resumes from its checkpoint after a mid-run failure', function () {
    $source = syncSource();
    $service = syncService($source, rangeSize: 2);
    $source->failOn('fetchEnvelopes', 3);

    expect(fn () => $service->backfillMetadata($this->mailbox, Carbon::parse('2025-11-01')))
        ->toThrow(MailSourceException::class, 'Simulated failure');

    $state = MailSyncState::sole();
    expect(MailMessage::orderBy('uid')->pluck('uid')->all())->toBe([106, 107, 108, 109])
        ->and($state->status)->toBe(MailSyncState::STATUS_FAILED)
        ->and($state->backfill_lowest_uid)->toBe(106)
        ->and($state->consecutive_failures)->toBe(1)
        ->and($state->last_error)->toContain('Simulated failure');

    $resumed = $service->backfillMetadata($this->mailbox, Carbon::parse('2025-11-01'));

    $state->refresh();
    expect($resumed->inserted)->toBe(5)
        ->and($resumed->ranges)->toBe(3) // 104-105, 102-103, 101: finished ranges are not re-read
        ->and(MailMessage::count())->toBe(9)
        ->and(MailMessage::distinct()->count('gm_msgid'))->toBe(9)
        ->and($state->status)->toBe(MailSyncState::STATUS_IDLE)
        ->and($state->consecutive_failures)->toBe(0)
        ->and($state->last_error)->toBeNull()
        ->and($state->backfill_completed_at)->not->toBeNull();
});

test('syncNew resumes from its checkpoint after a mid-run failure and still fetches the bodies', function () {
    $source = syncSource();
    $service = syncService($source)->configure(initialLookbackDays: 100_000);
    $source->failOn('fetchEnvelopes', 2);

    expect(fn () => $service->syncNew($this->mailbox))->toThrow(MailSourceException::class);

    expect(MailMessage::count())->toBe(3)
        ->and(MailSyncState::sole()->last_uid)->toBe(103)
        ->and(MailMessage::whereNotNull('body_fetched_at')->count())->toBe(0);

    $resumed = $service->syncNew($this->mailbox);

    expect($resumed->inserted)->toBe(6)
        ->and(MailMessage::count())->toBe(9)
        ->and(MailSyncState::sole()->last_uid)->toBe(109)
        ->and(MailMessage::whereNull('body_fetched_at')->count())->toBe(0);
});

test('a UIDVALIDITY change resets the cursor without duplicating rows; backfill re-sweep relocates old rows', function () {
    $source = syncSource();
    $service = syncService($source)->configure(initialLookbackDays: 100_000);
    $service->syncNew($this->mailbox);

    // Server rebuilt the folder: new epoch, every UID renumbered, plus a new message.
    $source->changeUidValidity(2, uidOffset: 1000);
    $source->addMessage(syncNewRaw('CAEmma-after-reset@mail.gmail.com'), 1110, '1847650000000001110', '1847650000000001110', ['\\Inbox'], now()->toIso8601String());

    $report = $service->syncNew($this->mailbox);

    $state = MailSyncState::sole();
    expect($report->uidValidityResets)->toBe([FakeMailSource::ALL_MAIL])
        ->and($report->inserted)->toBe(1)
        ->and(MailMessage::count())->toBe(10)
        ->and($state->uidvalidity)->toBe(2)
        ->and($state->uidvalidity_resets)->toBe(1)
        ->and($state->last_uid)->toBe(1110)
        ->and($state->backfill_completed_at)->toBeNull()
        ->and(MailMessage::where('uidvalidity', 1)->count())->toBe(9); // stale until re-swept

    $sweep = $service->backfillMetadata($this->mailbox, Carbon::parse('2025-11-01'));

    expect($sweep->inserted)->toBe(0)
        ->and($sweep->updated)->toBe(9)
        ->and(MailMessage::count())->toBe(10)
        ->and(MailMessage::where('uidvalidity', 2)->count())->toBe(10)
        ->and(MailMessage::where('gm_msgid', '1847650000000000101')->value('uid'))->toBe(1101);
});

test('the fake refuses to serve UIDs from a stale UIDVALIDITY', function () {
    $source = syncSource();
    $state = $source->folderState(new FolderRef(FakeMailSource::ALL_MAIL, FolderRef::ROLE_ALL));
    $source->changeUidValidity(7);

    expect(fn () => $source->fetchEnvelopes($state, 1, 200))->toThrow(UidValidityChanged::class);
});

test('non-Gmail servers sync INBOX + Sent and dedupe by Message-ID', function () {
    $source = syncSource(gmail: false);
    $service = syncService($source)->configure(initialLookbackDays: 100_000);

    $report = $service->syncNew($this->mailbox);

    expect($report->inserted)->toBe(9)
        ->and(MailSyncState::orderBy('folder')->pluck('folder')->all())->toBe(['INBOX', 'Sent'])
        ->and(MailMessage::whereNotNull('gm_msgid')->count())->toBe(0)
        ->and(MailMessage::where('folder', 'Sent')->sole()->direction)->toBe(MailDirection::Outbound);

    // Same message delivered to both folders (e.g. a copy sent to ourselves).
    $source->addMessage((string) file_get_contents(base_path('tests/Fixtures/mail/01-customer-enquiry.eml')), 103, folder: 'Sent');
    $again = $service->syncNew($this->mailbox);

    expect($again->fetched)->toBe(1)
        ->and($again->inserted)->toBe(0)
        ->and(MailMessage::count())->toBe(9)
        ->and(MailMessage::where('message_id', 'CAEmma001.k3j2@mail.gmail.com')->value('folder'))->toBe('INBOX');
});

test('hydrateBodies fills bodies for backfilled rows and returns attachments', function () {
    $source = syncSource();
    $service = syncService($source);
    $service->backfillMetadata($this->mailbox, Carbon::parse('2025-11-01'));

    $row = MailMessage::where('uid', 107)->sole();
    $parsed = $service->hydrateBodies($this->mailbox, [$row], $source);

    $row->refresh();
    expect($parsed)->toHaveKey($row->id)
        ->and($parsed[$row->id]->attachments)->toHaveCount(2)
        ->and($row->has_attachments)->toBeTrue()
        ->and($row->body_html)->toContain('cid:ii_shade01')
        ->and($row->body_clean)->toBe("Here is the photo of the cracked shade, and the invoice is attached.\n\nEmma");
});

test('oversized bodies are skipped and marked, not retried forever', function () {
    $source = syncSource();
    $service = syncService($source)->configure(initialLookbackDays: 100_000, maxBodyBytes: 1_000);

    $service->syncNew($this->mailbox);

    $big = MailMessage::where('uid', 107)->sole();
    expect($big->body_fetched_at)->not->toBeNull()
        ->and($big->body_html)->toBeNull()
        ->and($big->headers['x-fusterai-body-skipped'])->toBe('oversize');
});
