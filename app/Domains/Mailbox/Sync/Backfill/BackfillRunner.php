<?php

namespace App\Domains\Mailbox\Sync\Backfill;

use App\Domains\Conversation\Models\Conversation;
use App\Domains\Mailbox\Sync\Exceptions\MailSourceException;
use App\Domains\Mailbox\Sync\IngestResult;
use App\Domains\Mailbox\Sync\MailIngestService;
use App\Domains\Mailbox\Sync\MailSyncLock;
use App\Domains\Mailbox\Sync\MailSyncService;
use App\Domains\Mailbox\Sync\Models\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Executes a backfill run in short, checkpointed slices. Each RunMailBackfillJob
 * calls runStep() once: it works through slices of the current stage for up
 * to mailsync.backfill.step_seconds, saving after every slice, then says how
 * long to wait before the next job. A killed worker loses at most the slice
 * in flight; everything a slice writes is idempotent.
 *
 * Stages:
 *  A. metadata    MailSyncService::backfillMetadata() down to the run's date,
 *                 a few UID ranges per slice under the per-mailbox sync lock
 *                 (the same lock emails:fetch takes).
 *  B. filter      ThreadGrouper + ThreadFilter over the ledger, one pass in one
 *                 transaction (re-run from scratch if interrupted). Dry runs
 *                 end here.
 *  C. bodies      text parts of the kept threads' messages, newest thread first,
 *                 in bounded batches; attachments described, not downloaded.
 *  D. materialise each kept thread's messages, oldest first, through
 *                 MailIngestService in historical mode (the live ingest path).
 *  -  index       the run's conversations pushed to the search index in bulk
 *                 (ingest skipped the per-save sync).
 *
 * Undo (status undoing) deletes what the run created, in slices, and resets
 * the ledger rows it ingested.
 */
final class BackfillRunner
{
    public const CONTINUE = 'continue';

    public const WAIT = 'wait';

    public const FINISHED = 'finished';

    public const STOPPED = 'stopped';

    /** Seconds before retrying a slice that found the sync lock taken. */
    public const LOCK_WAIT_SECONDS = 15;

    public function __construct(
        private readonly MailSyncService $sync,
        private readonly MailIngestService $ingest,
        private readonly ThreadGrouper $grouper = new ThreadGrouper,
    ) {}

    /**
     * One job's worth of work. Returns the delay before the next job, or null
     * when the chain should stop (finished, paused, cancelled, superseded,
     * failed).
     */
    public function runStep(int $runId, ?string $token = null): ?int
    {
        $run = MailBackfillRun::query()->find($runId);
        if ($run === null || ! $this->owns($run, $token)) {
            return null;
        }

        if ($run->status === MailBackfillRun::STATUS_QUEUED) {
            MailBackfillRun::query()->whereKey($run->id)->where('status', MailBackfillRun::STATUS_QUEUED)
                ->update(['status' => MailBackfillRun::STATUS_RUNNING, 'started_at' => now(), 'heartbeat_at' => now()]);
            $run->refresh();
        }

        if (! in_array($run->status, [MailBackfillRun::STATUS_RUNNING, MailBackfillRun::STATUS_UNDOING], true)) {
            return null;
        }

        try {
            $outcome = $this->work($run);
        } catch (Throwable $e) {
            return $this->recordFailure($run->id, $e, $token);
        }

        return match ($outcome) {
            self::CONTINUE => max(0, (int) config('mailsync.backfill.throttle_seconds', 3)),
            self::WAIT => self::LOCK_WAIT_SECONDS,
            default => null,
        };
    }

    /**
     * Record an error and decide the backoff: 30s, 60s, 120s ... capped at
     * 15 minutes. After max_consecutive_errors the run is marked failed
     * (null = stop); an undo is never marked failed, it keeps retrying.
     */
    public function recordFailure(int $runId, Throwable $e, ?string $token = null): ?int
    {
        $run = MailBackfillRun::query()->find($runId);
        if ($run === null || ! $this->owns($run, $token) || ! in_array($run->status, [MailBackfillRun::STATUS_RUNNING, MailBackfillRun::STATUS_UNDOING, MailBackfillRun::STATUS_QUEUED], true)) {
            return null;
        }

        $n = $run->consecutive_errors + 1;
        $giveUp = $run->status !== MailBackfillRun::STATUS_UNDOING
            && $n >= max(1, (int) config('mailsync.backfill.max_consecutive_errors', 8));

        $run->forceFill([
            'error_count' => $run->error_count + 1,
            'consecutive_errors' => $n,
            'last_error' => mb_substr('['.$run->stage.'] '.get_class($e).': '.$e->getMessage(), 0, 2000),
            'last_error_at' => now(),
            'heartbeat_at' => now(),
        ]);
        if ($giveUp) {
            $run->status = MailBackfillRun::STATUS_FAILED;
            $run->finished_at = now();
        }
        $run->save();

        Log::warning('Mail backfill step failed', ['run_id' => $run->id, 'stage' => $run->stage, 'attempt' => $n, 'error' => $e->getMessage()]);

        return $giveUp ? null : min(900, 30 * 2 ** ($n - 1));
    }

    // ── Loop ─────────────────────────────────────────────────────────

    private function owns(MailBackfillRun $run, ?string $token): bool
    {
        return $token === null || $run->job_token === null || hash_equals((string) $run->job_token, $token);
    }

    private function work(MailBackfillRun $run): string
    {
        $deadline = microtime(true) + max(1, (int) config('mailsync.backfill.step_seconds', 120));
        $slicesLeft = max(1, (int) config('mailsync.backfill.max_slices_per_step', 500));

        do {
            $run->refresh();
            $outcome = match ($run->status) {
                MailBackfillRun::STATUS_RUNNING => $this->slice($run),
                MailBackfillRun::STATUS_UNDOING => $this->undoSlice($run),
                default => self::STOPPED,
            };
            if ($outcome === self::STOPPED) {
                return $outcome;
            }

            $run->heartbeat_at = now();
            $run->consecutive_errors = 0;
            $run->save();

            if ($outcome !== self::CONTINUE) {
                return $outcome;
            }
        } while (--$slicesLeft > 0 && microtime(true) < $deadline);

        return self::CONTINUE;
    }

    private function slice(MailBackfillRun $run): string
    {
        return match ($run->stage) {
            MailBackfillRun::STAGE_METADATA => $this->metadataSlice($run),
            MailBackfillRun::STAGE_FILTER => $this->filterSlice($run),
            MailBackfillRun::STAGE_BODIES => $this->bodiesSlice($run),
            MailBackfillRun::STAGE_MATERIALISE => $this->materialiseSlice($run),
            MailBackfillRun::STAGE_INDEX => $this->indexSlice($run),
            default => $this->finish($run),
        };
    }

    private function finish(MailBackfillRun $run): string
    {
        MailBackfillRun::query()->whereKey($run->id)->where('status', MailBackfillRun::STATUS_RUNNING)
            ->update(['status' => MailBackfillRun::STATUS_COMPLETED, 'finished_at' => now(), 'heartbeat_at' => now()]);

        return self::FINISHED;
    }

    // ── A. Metadata ──────────────────────────────────────────────────

    private function metadataSlice(MailBackfillRun $run): string
    {
        $mailbox = $run->mailbox ?? throw new RuntimeException("Mailbox {$run->mailbox_id} no longer exists");

        // Same lock as emails:fetch: both move the cursor in mail_sync_states.
        $lock = MailSyncLock::for($mailbox->id, 300);
        if (! $lock->get()) {
            return self::WAIT;
        }

        try {
            $report = $this->sync
                ->configure(
                    rangeSize: max(1, (int) config('mailsync.backfill.range_size', 500)),
                    // If the backfill reaches a folder before live sync ever did,
                    // start the live cursor exactly where emails:fetch would.
                    initialLookbackDays: (int) config('mailsync.initial_lookback_days', 3),
                )
                ->backfillMetadata($mailbox, $run->since, maxRanges: max(1, (int) config('mailsync.backfill.ranges_per_slice', 4)));
        } finally {
            $lock->release();
        }

        $run->bump(['envelopes_fetched' => $report->fetched, 'messages_added' => $report->inserted]);
        if ($report->completed) {
            $run->stage = MailBackfillRun::STAGE_FILTER;
        }

        return self::CONTINUE;
    }

    // ── B. Thread filter ─────────────────────────────────────────────

    private function filterSlice(MailBackfillRun $run): string
    {
        $filter = new ThreadFilter(
            sinceTs: Carbon::parse($run->since)->startOfDay()->getTimestamp(),
            nowTs: now()->getTimestamp(),
            includeUnanswered: (bool) $run->option('include_unanswered', false),
            activeWindowDays: (int) $run->option('active_window_days', 14),
        );

        $totals = [
            'messages_seen' => 0, 'threads_total' => 0, 'threads_kept' => 0, 'threads_dropped' => 0,
            'threads_skipped' => 0, 'threads_active' => 0, 'messages_to_import' => 0,
        ];
        $reasons = [ThreadFilter::KEPT => [], ThreadFilter::DROPPED => [], ThreadFilter::SKIPPED => []];

        DB::transaction(function () use ($run, $filter, &$totals, &$reasons) {
            MailBackfillThread::query()->where('run_id', $run->id)->delete();

            $batch = [];
            $now = now();
            foreach ($this->grouper->groups($run->mailbox_id) as $key => $rows) {
                $d = $filter->decide($rows);
                $reasons[$d['decision']][$d['reason']] = ($reasons[$d['decision']][$d['reason']] ?? 0) + 1;

                if ($d['reason'] === ThreadFilter::REASON_OUT_OF_WINDOW) {
                    continue; // older history: counted, not stored
                }

                $totals['threads_total']++;
                $totals['messages_seen'] += count($rows);
                $totals['threads_'.$d['decision']]++;
                if ($d['decision'] === ThreadFilter::KEPT) {
                    $totals['messages_to_import'] += count($d['importable']);
                    $totals['threads_active'] += $d['active'] ? 1 : 0;
                }

                $batch[] = [
                    'run_id' => $run->id,
                    'thread_key' => mb_substr((string) $key, 0, 64),
                    'gm_thrid' => $rows[0]['gm_thrid'] ?? null,
                    'decision' => $d['decision'],
                    'reason' => $d['reason'],
                    'active' => $d['active'],
                    'message_ids' => json_encode($d['decision'] === ThreadFilter::KEPT ? $d['importable'] : array_column($rows, 'id')),
                    'message_count' => count($rows),
                    'inbound_count' => $d['inbound'],
                    'outbound_count' => $d['outbound'],
                    'customer_email' => $d['customer_email'] !== null ? mb_substr((string) $d['customer_email'], 0, 255) : null,
                    'subject' => $d['subject'],
                    'first_at' => $d['first_at'] !== null ? Carbon::createFromTimestamp($d['first_at'])->setTimezone(date_default_timezone_get()) : null,
                    'last_at' => $d['last_at'] !== null ? Carbon::createFromTimestamp($d['last_at'])->setTimezone(date_default_timezone_get()) : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($batch) >= 500) {
                    MailBackfillThread::query()->insert($batch);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                MailBackfillThread::query()->insert($batch);
            }
        });

        $run->setCounters($totals + [
            'kept_reasons' => $reasons[ThreadFilter::KEPT],
            'dropped_reasons' => $reasons[ThreadFilter::DROPPED],
            'skipped_reasons' => $reasons[ThreadFilter::SKIPPED],
        ]);
        $run->stage = $run->isDryRun() ? MailBackfillRun::STAGE_DONE : MailBackfillRun::STAGE_BODIES;

        return self::CONTINUE;
    }

    // ── C. Bodies ────────────────────────────────────────────────────

    private function bodiesSlice(MailBackfillRun $run): string
    {
        $mailbox = $run->mailbox ?? throw new RuntimeException("Mailbox {$run->mailbox_id} no longer exists");
        $limit = max(1, (int) config('mailsync.backfill.bodies_per_slice', 100));

        $threads = MailBackfillThread::query()
            ->where('run_id', $run->id)
            ->where('decision', ThreadFilter::KEPT)
            ->whereNull('bodies_fetched_at')
            ->orderByDesc('last_at')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'message_ids']);

        if ($threads->isEmpty()) {
            $run->stage = MailBackfillRun::STAGE_MATERIALISE;

            return self::CONTINUE;
        }

        // Whole threads only, so a thread is never left half-fetched.
        $picked = [];
        $ids = [];
        foreach ($threads as $thread) {
            $picked[] = $thread->id;
            $ids = array_merge($ids, $thread->message_ids);
            if (count($ids) >= $limit) {
                break;
            }
        }

        $rows = MailMessage::query()->whereIn('id', $ids)->whereNull('body_fetched_at')->get();
        $fetched = $rows->isEmpty() ? [] : $this->sync->hydrateBodies($mailbox, $rows->all(), textOnly: true);

        MailBackfillThread::query()->whereIn('id', $picked)->update(['bodies_fetched_at' => now()]);
        $run->bump([
            'bodies_fetched' => count($fetched),
            // Expunged or moved (stale UIDVALIDITY) since the sweep: ingested from headers.
            'bodies_missing' => $rows->count() - count($fetched),
        ]);

        return self::CONTINUE;
    }

    // ── D. Materialise ───────────────────────────────────────────────

    private function materialiseSlice(MailBackfillRun $run): string
    {
        $threads = MailBackfillThread::query()
            ->where('run_id', $run->id)
            ->where('decision', ThreadFilter::KEPT)
            ->whereNull('materialised_at')
            ->orderBy('first_at')
            ->orderBy('id')
            ->limit(max(1, (int) config('mailsync.backfill.threads_per_slice', 25)))
            ->get();

        if ($threads->isEmpty()) {
            $run->stage = MailBackfillRun::STAGE_INDEX;
            $this->recount($run);

            return self::CONTINUE;
        }

        foreach ($threads as $thread) {
            $this->materialiseThread($run, $thread);
            $thread->forceFill(['materialised_at' => now()])->save();
        }

        $this->recount($run);

        return self::CONTINUE;
    }

    private function materialiseThread(MailBackfillRun $run, MailBackfillThread $thread): void
    {
        $rows = MailMessage::query()
            ->whereIn('id', $thread->message_ids)
            ->whereNull('ingested_at')
            ->get(['id', 'sent_at', 'internal_date', 'labels'])
            ->filter(fn (MailMessage $m) => ! $m->hasLabel('\\Draft'))
            ->sortBy(fn (MailMessage $m) => [ThreadGrouper::orderTime($m->sent_at, $m->internal_date)?->getTimestamp() ?? 0, $m->id])
            ->values();

        // Oldest first: the first message creates the conversation, the rest
        // join it through the resolver (Gmail thread + customer, References).
        foreach ($rows as $row) {
            try {
                $this->ingest->ingest($row->id, historical: true, backfillRunId: $run->id, keepActive: $thread->active);
            } catch (MailSourceException $e) {
                throw $e; // IMAP trouble: back off and redo this thread (done rows are skipped)
            } catch (Throwable $e) {
                // One malformed message must not stall the import.
                report($e);
                $run->bump(['message_errors' => 1]);
                $run->last_error = mb_substr("[materialise] message {$row->id}: ".get_class($e).': '.$e->getMessage(), 0, 2000);
                $run->last_error_at = now();
            }
        }
    }

    /** Counters derived from the database, so they stay right across retries. */
    private function recount(MailBackfillRun $run): void
    {
        $outcomes = MailMessage::query()
            ->where('backfill_run_id', $run->id)
            ->groupBy('ingest_outcome')
            ->selectRaw('ingest_outcome, count(*) as n')
            ->pluck('n', 'ingest_outcome')
            ->map(fn ($n) => (int) $n)
            ->all();

        $skipped = 0;
        foreach ($outcomes as $outcome => $n) {
            if (str_starts_with((string) $outcome, 'skipped_')) {
                $skipped += $n;
            }
        }

        $run->setCounters([
            'threads_materialised' => MailBackfillThread::query()->where('run_id', $run->id)->whereNotNull('materialised_at')->count(),
            'conversations_created' => Conversation::withTrashed()->where('backfill_run_id', $run->id)->count(),
            'conversations_open' => Conversation::query()->where('backfill_run_id', $run->id)->where('imported', false)->count(),
            'messages_ingested' => $outcomes[IngestResult::CREATED] ?? 0,
            'messages_linked' => $outcomes[IngestResult::LINKED] ?? 0,
            'messages_skipped' => $skipped,
        ]);
    }

    // ── Search index ─────────────────────────────────────────────────

    private function indexSlice(MailBackfillRun $run): string
    {
        $cursor = $run->counter('index_cursor');
        $batch = Conversation::query()
            ->where('backfill_run_id', $run->id)
            ->where('id', '>', $cursor)
            ->orderBy('id')
            ->limit(500)
            ->get();

        if ($batch->isEmpty()) {
            $run->stage = MailBackfillRun::STAGE_DONE;

            return self::CONTINUE;
        }

        try {
            $batch->first()->queueMakeSearchable($batch);
        } catch (Throwable $e) {
            // The import itself is complete; search can be rebuilt with scout:import.
            Log::warning('Mail backfill could not index conversations', ['run_id' => $run->id, 'error' => $e->getMessage()]);
            $run->last_error = mb_substr('[index] '.get_class($e).': '.$e->getMessage().' (run scout:import to rebuild search)', 0, 2000);
            $run->last_error_at = now();
            $run->stage = MailBackfillRun::STAGE_DONE;

            return self::CONTINUE;
        }

        $run->setCounters(['index_cursor' => (int) $batch->last()->id]);
        $run->bump(['conversations_indexed' => $batch->count()]);

        return self::CONTINUE;
    }

    // ── Undo ─────────────────────────────────────────────────────────

    /**
     * Removes what the run created, never live data:
     *  1. conversations the run created whose every thread came from the run
     *     are deleted (threads, attachments, tags... cascade); one that has
     *     since got a live message or a staff note is kept, only its run
     *     threads go;
     *  2. threads the run added to other conversations are deleted;
     *  3. the ledger rows it ingested are reset so a later run can redo them.
     */
    private function undoSlice(MailBackfillRun $run): string
    {
        $createdRows = fn () => DB::table('mail_messages')
            ->select('id')
            ->where('backfill_run_id', $run->id)
            ->where('ingest_outcome', IngestResult::CREATED);

        $ids = DB::table('conversations')->where('backfill_run_id', $run->id)->orderBy('id')->limit(200)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids !== []) {
            $keep = DB::table('threads')
                ->whereIn('conversation_id', $ids)
                ->where(fn ($q) => $q->whereNull('mail_message_id')->orWhereNotIn('mail_message_id', $createdRows()))
                ->distinct()
                ->pluck('conversation_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $delete = array_values(array_diff($ids, $keep));

            DB::transaction(function () use ($delete, $keep) {
                if ($delete !== []) {
                    $gone = Conversation::withTrashed()->whereIn('id', $delete)->get();
                    $gone->first()?->queueRemoveFromSearch($gone);
                    DB::table('conversations')->whereIn('id', $delete)->delete();
                }
                if ($keep !== []) {
                    DB::table('conversations')->whereIn('id', $keep)->update(['backfill_run_id' => null]);
                }
            });

            $run->bump(['undo_conversations_deleted' => count($delete), 'undo_conversations_kept' => count($keep)]);

            return self::CONTINUE;
        }

        $threadIds = DB::table('mail_messages')
            ->where('backfill_run_id', $run->id)
            ->where('ingest_outcome', IngestResult::CREATED)
            ->whereNotNull('thread_id')
            ->limit(500)
            ->pluck('thread_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        if ($threadIds !== []) {
            DB::transaction(function () use ($threadIds) {
                $affected = DB::table('threads')->whereIn('id', $threadIds)->distinct()->pluck('conversation_id')->all();
                DB::table('threads')->whereIn('id', $threadIds)->delete();
                foreach ($affected as $conversationId) {
                    $last = DB::table('threads')->where('conversation_id', $conversationId)->where('type', 'message')->max('created_at');
                    if ($last !== null) {
                        DB::table('conversations')->where('id', $conversationId)->update(['last_reply_at' => $last]);
                    }
                }
            });
            $run->bump(['undo_threads_deleted' => count($threadIds)]);

            return self::CONTINUE;
        }

        DB::transaction(function () use ($run) {
            DB::table('mail_messages')->where('backfill_run_id', $run->id)->update([
                'thread_id' => null,
                'conversation_id' => null,
                'ingested_at' => null,
                'ingest_outcome' => null,
                'backfill_run_id' => null,
                'updated_at' => now(),
            ]);
            MailBackfillThread::query()->where('run_id', $run->id)->update(['materialised_at' => null]);
            MailBackfillRun::query()->whereKey($run->id)->where('status', MailBackfillRun::STATUS_UNDOING)
                ->update(['status' => MailBackfillRun::STATUS_UNDONE, 'finished_at' => now()]);
        });

        return self::FINISHED;
    }
}
