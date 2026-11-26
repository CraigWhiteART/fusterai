<?php

namespace App\Domains\Mailbox\Sync;

use App\Domains\Mailbox\Models\Mailbox;
use App\Domains\Mailbox\Sync\Contracts\MailSource;
use App\Domains\Mailbox\Sync\Contracts\MailSourceFactory;
use App\Domains\Mailbox\Sync\Data\FolderRef;
use App\Domains\Mailbox\Sync\Data\FolderState;
use App\Domains\Mailbox\Sync\Data\MailEnvelope;
use App\Domains\Mailbox\Sync\Exceptions\MailSourceException;
use App\Domains\Mailbox\Sync\Imap\ImapMailSourceFactory;
use App\Domains\Mailbox\Sync\Models\MailMessage;
use App\Domains\Mailbox\Sync\Models\MailSyncState;
use App\Domains\Mailbox\Sync\Parsing\MessageClassifier;
use App\Domains\Mailbox\Sync\Parsing\MessageParser;
use App\Domains\Mailbox\Sync\Parsing\ParsedMessage;
use App\Domains\Mailbox\Sync\Threading\MailboxIdentity;
use App\Domains\Mailbox\Sync\Threading\ThreadResolver;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Copies a mailbox into the mail_messages ledger. Two resumable operations:
 *
 *  - syncNew(): UIDs above the folder's last_uid (new mail, including staff
 *    replies sent from Gmail, which land in All Mail too). Downloads bodies
 *    for the new rows.
 *  - backfillMetadata(): walks UIDs downwards from where live sync started
 *    to the first UID on/after a date, envelopes only.
 *
 * Both only write ledger rows (never conversations, threads or jobs) and
 * checkpoint the cursor in the same transaction as each range's rows, so a
 * crash resumes at the last finished range. Rows are keyed by
 * (mailbox_id, gm_msgid), falling back to (mailbox_id, message_id) when the
 * server has no Gmail ids, so re-running either operation, or re-reading a
 * folder after a UIDVALIDITY change, inserts nothing twice.
 */
class MailSyncService
{
    private readonly MailSourceFactory $sources;

    private readonly MessageParser $parser;

    private readonly ThreadResolver $resolver;

    private readonly MessageClassifier $classifier;

    /** UIDs per FETCH / checkpoint. */
    private int $rangeSize = 500;

    /** Ranges per run per folder, to bound a single run's duration. */
    private int $maxRangesPerRun = 20;

    /** On the very first syncNew, also take mail from the last N days (0 = from now on). */
    private int $initialLookbackDays = 0;

    /** Bodies larger than this are not downloaded (row is marked skipped). */
    private int $maxBodyBytes = 26_214_400;

    /** Bodies downloaded per syncNew run per folder; the rest wait for the next run. */
    private int $maxBodiesPerRun = 200;

    public function __construct(
        ?MailSourceFactory $sources = null,
        ?MessageParser $parser = null,
        ?ThreadResolver $resolver = null,
        ?MessageClassifier $classifier = null,
    ) {
        $this->sources = $sources ?? new ImapMailSourceFactory;
        $this->parser = $parser ?? new MessageParser;
        $this->resolver = $resolver ?? new ThreadResolver;
        $this->classifier = $classifier ?? new MessageClassifier;
    }

    public function configure(
        ?int $rangeSize = null,
        ?int $maxRangesPerRun = null,
        ?int $initialLookbackDays = null,
        ?int $maxBodyBytes = null,
        ?int $maxBodiesPerRun = null,
    ): static {
        $this->rangeSize = max(1, $rangeSize ?? $this->rangeSize);
        $this->maxRangesPerRun = max(1, $maxRangesPerRun ?? $this->maxRangesPerRun);
        $this->initialLookbackDays = max(0, $initialLookbackDays ?? $this->initialLookbackDays);
        $this->maxBodyBytes = max(1, $maxBodyBytes ?? $this->maxBodyBytes);
        $this->maxBodiesPerRun = max(1, $maxBodiesPerRun ?? $this->maxBodiesPerRun);

        return $this;
    }

    /** @param list<string> $aliases extra addresses staff send as */
    public function syncNew(Mailbox $mailbox, array $aliases = [], bool $fetchBodies = true): SyncReport
    {
        $identity = MailboxIdentity::forMailbox($mailbox, $aliases);
        $report = new SyncReport;
        $source = $this->sources->forMailbox($mailbox);

        try {
            foreach ($source->syncFolders() as $folder) {
                $report->merge($this->syncFolder($mailbox, $source, $folder, $identity, $fetchBodies));
            }
        } finally {
            $source->close();
        }

        sort($report->newMessageIds);

        return $report;
    }

    /** Above this many Inbox messages the snapshot is not taken. */
    public const INBOX_SNAPSHOT_MAX = 3000;

    /**
     * What is in the Gmail Inbox right now: the X-GM-MSGID and Message-ID of
     * every message. Null when the server is not Gmail, or the Inbox is too
     * large to read cheaply. Throws when the Inbox cannot be read; an empty
     * result always means an empty Inbox.
     *
     * @return array{gm: array<string, true>, message_ids: array<string, true>}|null
     */
    public function inboxSnapshot(Mailbox $mailbox): ?array
    {
        $source = $this->sources->forMailbox($mailbox);

        try {
            if (! $source->supportsGmailExtensions()) {
                return null;
            }

            $fs = $source->folderState(new FolderRef('INBOX', FolderRef::ROLE_INBOX, [], 'INBOX'));
            if ($fs->exists > self::INBOX_SNAPSHOT_MAX) {
                return null;
            }

            $snapshot = ['gm' => [], 'message_ids' => []];
            $envelopes = $fs->exists > 0 ? $source->fetchEnvelopes($fs, 1, $fs->maxUid()) : [];
            if (count($envelopes) < $fs->exists) {
                throw new MailSourceException('Inbox snapshot incomplete: '.count($envelopes)." of {$fs->exists} messages returned");
            }

            foreach ($envelopes as $envelope) {
                if ($envelope->gmMsgid === null) {
                    throw new MailSourceException('Inbox snapshot has a message without X-GM-MSGID');
                }
                $snapshot['gm'][$envelope->gmMsgid] = true;
                $messageId = $this->parser->parseHeaders($envelope->rawHeaders)->messageId;
                if ($messageId !== null) {
                    $snapshot['message_ids'][$messageId] = true;
                }
            }

            return $snapshot;
        } finally {
            $source->close();
        }
    }

    /** @param list<string> $aliases */
    public function backfillMetadata(Mailbox $mailbox, DateTimeInterface $since, array $aliases = [], ?int $maxRanges = null): SyncReport
    {
        $identity = MailboxIdentity::forMailbox($mailbox, $aliases);
        $report = new SyncReport;
        $source = $this->sources->forMailbox($mailbox);

        try {
            foreach ($source->syncFolders() as $folder) {
                $report->merge($this->backfillFolder($mailbox, $source, $folder, $identity, $since, $maxRanges ?? $this->maxRangesPerRun));
            }
        } finally {
            $source->close();
        }

        sort($report->newMessageIds);

        return $report;
    }

    /**
     * Download, parse and store bodies for ledger rows (any folder). Returns
     * the parsed messages, which carry attachments (not stored in the ledger),
     * keyed by mail_messages.id. Rows whose UIDVALIDITY is stale are skipped;
     * the next backfill sweep refreshes their location.
     *
     * $textOnly (history): attachments are not downloaded (see
     * MailSource::fetchTextBodies()); their name / type / size go to
     * attachment_meta and the returned messages carry no attachments. The
     * size cap does not apply then, since only the text parts come down.
     *
     * @param  iterable<MailMessage>  $rows
     * @return array<int, ParsedMessage>
     */
    public function hydrateBodies(Mailbox $mailbox, iterable $rows, ?MailSource $source = null, bool $textOnly = false): array
    {
        $owned = $source === null;
        $source ??= $this->sources->forMailbox($mailbox);

        try {
            $byFolder = [];
            foreach ($rows as $row) {
                $byFolder[$row->folder][] = $row;
            }

            $parsed = [];
            foreach ($byFolder as $folder => $folderRows) {
                $state = $source->folderState(new FolderRef((string) $folder, 'other'));
                $parsed += $this->hydrateFolderRows($source, $state, $folderRows, $textOnly);
            }

            return $parsed;
        } finally {
            if ($owned) {
                $source->close();
            }
        }
    }

    // ── syncNew ──────────────────────────────────────────────────────

    private function syncFolder(Mailbox $mailbox, MailSource $source, FolderRef $folder, MailboxIdentity $identity, bool $fetchBodies): SyncReport
    {
        $report = new SyncReport;
        $state = $this->stateFor($mailbox, $folder);

        try {
            $fs = $source->folderState($folder);
            $this->reconcileValidity($state, $fs, $source, $report);
            $state->status = MailSyncState::STATUS_SYNCING;
            $state->uidnext = $fs->uidNext;
            $state->save();

            $ranges = 0;
            while ($state->last_uid < $fs->maxUid() && $ranges < $this->maxRangesPerRun) {
                $from = $state->last_uid + 1;
                $to = min($fs->maxUid(), $from + $this->rangeSize - 1);
                $envelopes = $source->fetchEnvelopes($fs, $from, $to);

                DB::transaction(function () use ($mailbox, $identity, $envelopes, $state, $to, $report) {
                    $written = $this->writeEnvelopes($mailbox, $identity, $envelopes, $report);
                    $state->last_uid = $to;
                    $state->synced_count += $written;
                    $state->save();
                });

                $report->fetched += count($envelopes);
                $report->ranges++;
                $ranges++;
            }

            if ($fetchBodies) {
                $pending = MailMessage::query()
                    ->where('mailbox_id', $mailbox->id)
                    ->where('folder', $folder->name)
                    ->where('uidvalidity', $fs->uidValidity)
                    ->where('uid', '>=', (int) $state->live_from_uid)
                    ->whereNull('body_fetched_at')
                    ->orderBy('uid')
                    ->limit($this->maxBodiesPerRun)
                    ->get();
                $report->bodiesFetched += count($this->hydrateFolderRows($source, $fs, $pending->all()));
            }

            $state->status = MailSyncState::STATUS_IDLE;
            if ($state->last_uid >= $fs->maxUid()) {
                // Only when caught up: a UIDVALIDITY reset re-reads from here.
                $state->last_synced_at = now();
            } else {
                $report->completed = false;
            }
            $state->consecutive_failures = 0;
            $state->last_error = null;
            $state->save();
        } catch (Throwable $e) {
            $this->recordFailure($state, $e);
            throw $e;
        }

        return $report;
    }

    // ── backfill ─────────────────────────────────────────────────────

    private function backfillFolder(Mailbox $mailbox, MailSource $source, FolderRef $folder, MailboxIdentity $identity, DateTimeInterface $since, int $maxRanges): SyncReport
    {
        $report = new SyncReport;
        $state = $this->stateFor($mailbox, $folder);

        try {
            $fs = $source->folderState($folder);
            $this->reconcileValidity($state, $fs, $source, $report);

            $sinceDate = Carbon::instance($since)->startOfDay();
            if ($state->backfill_since?->toDateString() !== $sinceDate->toDateString()) {
                $state->backfill_since = $sinceDate;
                $state->backfill_stop_uid = null;
                $state->backfill_completed_at = null;
            }

            $state->backfill_lowest_uid ??= $state->last_uid + 1;

            if ($state->backfill_stop_uid === null) {
                $first = $source->firstUidOnOrAfter($fs, $sinceDate);
                // Nothing on/after the date: there is nothing to sweep.
                $state->backfill_stop_uid = $first ?? $state->backfill_lowest_uid;
            }

            $state->status = MailSyncState::STATUS_BACKFILLING;
            $state->save();

            $ranges = 0;
            while ($state->backfill_lowest_uid > $state->backfill_stop_uid && $ranges < $maxRanges) {
                $hi = $state->backfill_lowest_uid - 1;
                $lo = max($state->backfill_stop_uid, $hi - $this->rangeSize + 1, 1);
                $envelopes = $source->fetchEnvelopes($fs, $lo, $hi);

                DB::transaction(function () use ($mailbox, $identity, $envelopes, $state, $lo, $report) {
                    $written = $this->writeEnvelopes($mailbox, $identity, $envelopes, $report);
                    $state->backfill_lowest_uid = $lo;
                    $state->backfilled_count += $written;
                    $state->last_backfilled_at = now();
                    $state->save();
                });

                $report->fetched += count($envelopes);
                $report->ranges++;
                $ranges++;
            }

            if ($state->backfill_lowest_uid <= $state->backfill_stop_uid) {
                $state->backfill_completed_at ??= now();
            } else {
                $report->completed = false;
            }
            $state->status = MailSyncState::STATUS_IDLE;
            $state->consecutive_failures = 0;
            $state->last_error = null;
            $state->save();
        } catch (Throwable $e) {
            $this->recordFailure($state, $e);
            throw $e;
        }

        return $report;
    }

    // ── Shared ───────────────────────────────────────────────────────

    private function stateFor(Mailbox $mailbox, FolderRef $folder): MailSyncState
    {
        return MailSyncState::query()->firstOrCreate(['mailbox_id' => $mailbox->id, 'folder' => $folder->name]);
    }

    /**
     * First run: start live sync at the top of the folder (or N days back),
     * backfill below it. UIDVALIDITY changed: every stored UID is void, so
     * re-read from one day before the last time we were caught up (the unique
     * keys make the overlap harmless) and restart the backfill sweep from the
     * new top so old rows get their new UIDs.
     */
    private function reconcileValidity(MailSyncState $state, FolderState $fs, MailSource $source, SyncReport $report): void
    {
        if ($state->uidvalidity === null) {
            $start = $fs->maxUid();
            if ($this->initialLookbackDays > 0) {
                $first = $source->firstUidOnOrAfter($fs, now()->subDays($this->initialLookbackDays)->startOfDay());
                $start = $first !== null ? $first - 1 : $start;
            }

            $state->uidvalidity = $fs->uidValidity;
            $state->last_uid = $start;
            $state->live_from_uid = $start + 1;
            $state->backfill_lowest_uid = $start + 1;
            $state->save();

            return;
        }

        if ($state->uidvalidity === $fs->uidValidity) {
            return;
        }

        $since = Carbon::instance($state->last_synced_at ?? $state->created_at ?? now())->subDay()->startOfDay();
        $first = $source->firstUidOnOrAfter($fs, $since);
        $start = $first !== null ? $first - 1 : $fs->maxUid();

        $state->uidvalidity = $fs->uidValidity;
        $state->last_uid = $start;
        $state->live_from_uid = $start + 1;
        $state->backfill_lowest_uid = $start + 1;
        $state->backfill_stop_uid = null;
        $state->backfill_completed_at = null;
        $state->uidvalidity_resets++;
        $state->save();

        $report->uidValidityResets[] = $fs->folder->name;
    }

    /**
     * Insert unseen envelopes, refresh location/labels of known ones.
     * Returns the number of rows inserted.
     *
     * @param  list<MailEnvelope>  $envelopes
     */
    private function writeEnvelopes(Mailbox $mailbox, MailboxIdentity $identity, array $envelopes, SyncReport $report): int
    {
        if ($envelopes === []) {
            return 0;
        }

        $rows = [];
        foreach ($envelopes as $envelope) {
            $rows[] = $this->rowFor($mailbox, $identity, $envelope);
        }

        $gmIds = array_values(array_filter(array_column($rows, 'gm_msgid')));
        $existingGm = $gmIds === [] ? collect() : MailMessage::query()
            ->where('mailbox_id', $mailbox->id)
            ->whereIn('gm_msgid', $gmIds)
            ->get(['id', 'gm_msgid', 'folder', 'uidvalidity', 'uid', 'labels'])
            ->keyBy(fn (MailMessage $m) => (string) $m->gm_msgid);

        $msgIds = array_values(array_filter(array_map(fn ($r) => $r['gm_msgid'] === null ? $r['message_id'] : null, $rows)));
        $existingMsg = $msgIds === [] ? collect() : MailMessage::query()
            ->where('mailbox_id', $mailbox->id)
            ->whereNull('gm_msgid')
            ->whereIn('message_id', $msgIds)
            ->get(['id', 'message_id', 'folder', 'uidvalidity', 'uid', 'labels'])
            ->keyBy('message_id');

        $inserted = 0;
        foreach ($rows as $row) {
            $existing = $row['gm_msgid'] !== null
                ? $existingGm->get($row['gm_msgid'])
                : $existingMsg->get((string) $row['message_id']);

            if ($existing === null) {
                $key = $row['gm_msgid'] !== null
                    ? ['mailbox_id' => $mailbox->id, 'gm_msgid' => $row['gm_msgid']]
                    : ['mailbox_id' => $mailbox->id, 'gm_msgid' => null, 'message_id' => $row['message_id']];

                // createOrFirst: insert in a savepoint, fall back to the existing
                // row on a unique violation (concurrent run, duplicate in batch).
                $model = MailMessage::query()->createOrFirst($key, $row);
                if ($model->wasRecentlyCreated) {
                    $inserted++;
                    $report->inserted++;
                    $report->newMessageIds[] = (int) $model->id;

                    // Same message twice in one batch (non-Gmail INBOX + Sent).
                    if ($row['gm_msgid'] !== null) {
                        $existingGm->put($row['gm_msgid'], $model);
                    } else {
                        $existingMsg->put((string) $row['message_id'], $model);
                    }
                }

                continue;
            }

            // Only the copy in the synced folder moves the location; for the
            // non-Gmail INBOX + Sent case keep the first location seen.
            $moved = $existing->folder === $row['folder']
                && ($existing->uidvalidity !== $row['uidvalidity'] || $existing->uid !== $row['uid']);
            $relabelled = $row['gm_msgid'] !== null && ($existing->labels ?? []) !== $row['labels'];

            if ($moved || $relabelled) {
                $existing->forceFill(array_filter([
                    'uidvalidity' => $moved ? $row['uidvalidity'] : null,
                    'uid' => $moved ? $row['uid'] : null,
                    'labels' => $relabelled ? $row['labels'] : null,
                ], fn ($v) => $v !== null))->save();
                $report->updated++;
            }
        }

        return $inserted;
    }

    /** @return array<string, mixed> */
    private function rowFor(Mailbox $mailbox, MailboxIdentity $identity, MailEnvelope $envelope): array
    {
        $parsed = $this->parser->parseHeaders($envelope->rawHeaders);
        $direction = $this->resolver->direction($parsed, $identity);
        $customer = $this->resolver->customer($parsed, $identity, $direction);

        return [
            'mailbox_id' => $mailbox->id,
            'gm_msgid' => $envelope->gmMsgid,
            'gm_thrid' => $envelope->gmThrid,
            'folder' => $envelope->folder,
            'uidvalidity' => $envelope->uidValidity,
            'uid' => $envelope->uid,
            'message_id' => self::clip($parsed->messageId, 998),
            'in_reply_to' => self::clip($parsed->inReplyTo, 998),
            'references' => $parsed->references,
            'from_email' => self::clip($parsed->fromEmail(), 255),
            'from_name' => self::clip($parsed->from['name'] ?? null, 255),
            'reply_to' => $parsed->replyTo,
            'to' => $parsed->to,
            'cc' => $parsed->cc,
            'subject' => $parsed->subject,
            // Columns are timestamp without time zone: store in the app timezone,
            // not whatever offset the header/server used.
            'sent_at' => self::local($parsed->sentAt),
            'internal_date' => self::local($envelope->internalDate),
            'direction' => $direction,
            'customer_email' => self::clip($customer !== null ? strtolower($customer['email']) : null, 255),
            'labels' => $envelope->labels,
            'size' => $envelope->size,
            'classification' => $this->classifier->classify($parsed, $direction),
            'headers' => $parsed->headers,
        ];
    }

    /**
     * @param  list<MailMessage>  $rows  rows in $state's folder
     * @return array<int, ParsedMessage>
     */
    private function hydrateFolderRows(MailSource $source, FolderState $state, array $rows, bool $textOnly = false): array
    {
        $wanted = [];
        foreach ($rows as $row) {
            if ($row->uidvalidity !== $state->uidValidity) {
                continue; // stale location
            }
            if (! $textOnly && $row->size !== null && $row->size > $this->maxBodyBytes) {
                $row->forceFill([
                    'body_fetched_at' => now(),
                    'headers' => array_merge($row->headers ?? [], ['x-fusterai-body-skipped' => 'oversize']),
                ])->save();

                continue;
            }
            $wanted[$row->uid] = $row;
        }

        if ($wanted === []) {
            return [];
        }

        $bodies = $textOnly
            ? $source->fetchTextBodies($state, array_keys($wanted))
            : $source->fetchBodies($state, array_keys($wanted));

        $parsed = [];
        foreach ($wanted as $uid => $row) {
            $body = $bodies[$uid] ?? null;
            if ($body === null) {
                continue; // expunged since the envelope sweep
            }

            $message = $this->parser->parse($body->raw);
            $meta = $body->textOnly
                ? $body->attachments
                : array_map(fn (array $a) => ['name' => $a['name'], 'mime' => $a['mime'], 'size' => $a['size'], 'inline' => $a['inline']], $message->attachments);

            $row->forceFill([
                'body_html' => $message->bodyHtml,
                'body_text' => $message->bodyText,
                'body_clean' => $message->bodyClean,
                // Real attachments only, as ParsedMessage::hasAttachments().
                'has_attachments' => array_filter($meta, fn (array $a) => ! $a['inline']) !== [],
                'attachment_meta' => $meta !== [] ? array_values($meta) : null,
                'body_fetched_at' => now(),
            ])->save();

            if ($textOnly && $message->attachments !== []) {
                // Small messages come down whole: keep the metadata, drop the bytes.
                $message = $message->withoutAttachments();
            }
            $parsed[(int) $row->id] = $message;
        }

        return $parsed;
    }

    private function recordFailure(MailSyncState $state, Throwable $e): void
    {
        try {
            $state->status = MailSyncState::STATUS_FAILED;
            $state->last_error = mb_substr(get_class($e).': '.$e->getMessage(), 0, 2000);
            $state->last_error_at = now();
            $state->consecutive_failures++;
            $state->save();
        } catch (Throwable) {
            // Never mask the original failure.
        }
    }

    private static function local(?DateTimeInterface $date): ?Carbon
    {
        return $date === null ? null : Carbon::instance($date)->setTimezone(date_default_timezone_get());
    }

    private static function clip(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $max);
    }
}
