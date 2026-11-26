<?php

namespace App\Console\Commands;

use App\Domains\Mailbox\Models\Mailbox;
use App\Domains\Mailbox\Sync\InboxReconciler;
use App\Domains\Mailbox\Sync\Jobs\IngestMailMessageJob;
use App\Domains\Mailbox\Sync\MailSyncLock;
use App\Domains\Mailbox\Sync\MailSyncService;
use App\Domains\Mailbox\Sync\Models\MailMessage;
use App\Domains\Mailbox\Sync\Models\MailSyncState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Copies new mail (inbound and staff mail sent from Gmail) from every active
 * IMAP mailbox into the mail_messages ledger with MailSyncService::syncNew(),
 * then queues one IngestMailMessageJob per new row. Read-only on the server:
 * nothing is marked read, moved or flagged (see docs/mail-sync.md).
 */
class FetchEmails extends Command
{
    protected $signature = 'emails:fetch
                            {--mailbox=* : Specific mailbox IDs to fetch}
                            {--limit= : Max message bodies to download per folder per run}';

    protected $description = 'Sync new mail from all active IMAP mailboxes into the mail ledger and queue it for ingest';

    /** Per-mailbox lock; longer than any sane run, released as soon as the run ends. */
    private const LOCK_SECONDS = MailSyncLock::SECONDS;

    /** Live rows older than this are not re-dispatched by the sweep (gave up / needs a human). */
    private const REINGEST_MAX_AGE_HOURS = 48;

    public function handle(MailSyncService $sync): int
    {
        ini_set('memory_limit', '512M');

        $query = Mailbox::where('active', true)->whereNotNull('imap_config')->orderBy('id');

        if ($ids = $this->option('mailbox')) {
            $query->whereIn('id', $ids);
        }

        $mailboxes = $query->get();

        if ($mailboxes->isEmpty()) {
            $this->info('No active IMAP mailboxes found.');

            return self::SUCCESS;
        }

        $sync->configure(
            initialLookbackDays: (int) config('mailsync.initial_lookback_days', 3),
            maxBodiesPerRun: $this->option('limit') !== null
                ? max(1, (int) $this->option('limit'))
                : (int) config('mailsync.max_bodies_per_run', 200),
        );

        $failed = 0;
        foreach ($mailboxes as $mailbox) {
            // One mailbox failing (bad password, server down) never stops the others.
            try {
                if (! $this->syncMailbox($sync, $mailbox)) {
                    continue;
                }
            } catch (Throwable $e) {
                $failed++;
                $this->error("  Error syncing {$mailbox->email}: ".$e->getMessage());
                Log::error('FetchEmails failed', [
                    'mailbox_id' => $mailbox->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** False when the mailbox was skipped. */
    private function syncMailbox(MailSyncService $sync, Mailbox $mailbox): bool
    {
        $config = $mailbox->imap_config;
        if (! $config || empty($config['host']) || empty($config['username'])) {
            $this->warn("  Skipping {$mailbox->email}: IMAP host/username missing.");
            Log::warning('FetchEmails skipped mailbox with incomplete IMAP config', [
                'mailbox_id' => $mailbox->id,
            ]);

            return false;
        }

        // The scheduler uses withoutOverlapping() for the whole command; this
        // also covers a manual `emails:fetch --mailbox=N` during a scheduled run,
        // and a history backfill's metadata sweep (it holds the same lock for a
        // few seconds per slice, so live mail waits at most one run).
        $lock = MailSyncLock::for($mailbox->id, self::LOCK_SECONDS);
        if (! $lock->get()) {
            $this->warn("  Skipping {$mailbox->email}: another sync is running.");

            return false;
        }

        try {
            $this->info("Syncing: {$mailbox->name} <{$mailbox->email}>");

            $report = $sync->syncNew($mailbox);

            foreach ($report->newMessageIds as $id) {
                IngestMailMessageJob::dispatch($id);
            }

            $requeued = $this->requeueStranded($mailbox);

            $this->info("  {$report->inserted} new, {$report->bodiesFetched} bodies, {$requeued} re-queued.");
            if ($report->uidValidityResets !== []) {
                $this->warn('  UIDVALIDITY changed: '.implode(', ', $report->uidValidityResets));
            }

            $this->closeArchived($mailbox);
        } finally {
            $lock->release();
        }

        return true;
    }

    /**
     * Archived in Gmail = done (mailsync.inbox_only). A failure here never
     * fails the sync, and nothing is closed when the number looks wrong.
     */
    private function closeArchived(Mailbox $mailbox): void
    {
        if (! config('mailsync.inbox_only', true)) {
            return;
        }

        try {
            $r = app(InboxReconciler::class)->reconcile($mailbox, maxClose: (int) config('mailsync.max_archive_closes_per_run', 25));
        } catch (Throwable $e) {
            $this->warn('  Inbox check failed: '.$e->getMessage());
            Log::warning('Inbox reconcile failed', ['mailbox_id' => $mailbox->id, 'error' => $e->getMessage()]);

            return;
        }

        if ($r['capped']) {
            $this->warn("  {$r['to_close']} conversations look archived; too many to close automatically. Run mail:close-archived.");
            Log::warning('Inbox reconcile over the per-run limit; nothing closed', ['mailbox_id' => $mailbox->id, 'to_close' => $r['to_close']]);
        } elseif ($r['closed'] > 0) {
            $this->info("  {$r['closed']} archived in Gmail, closed.");
        }
    }

    /**
     * Live rows that never got an ingest result (the process died between
     * the ledger commit and the dispatch, or the job was lost) are queued
     * again. Only live rows (UID at or above the folder's live_from_uid), so
     * backfilled history is never ingested as live mail.
     */
    private function requeueStranded(Mailbox $mailbox): int
    {
        $count = 0;
        $olderThan = now()->subMinutes(max(1, (int) config('mailsync.reingest_after_minutes', 10)));

        foreach (MailSyncState::where('mailbox_id', $mailbox->id)->whereNotNull('live_from_uid')->get() as $state) {
            $ids = MailMessage::query()
                ->where('mailbox_id', $mailbox->id)
                ->where('folder', $state->folder)
                ->where('uidvalidity', $state->uidvalidity)
                ->where('uid', '>=', $state->live_from_uid)
                ->whereNull('ingested_at')
                ->whereNull('thread_id')
                ->where('created_at', '<=', $olderThan)
                ->where('created_at', '>=', now()->subHours(self::REINGEST_MAX_AGE_HOURS))
                ->orderBy('id')
                ->limit(100)
                ->pluck('id');

            foreach ($ids as $id) {
                IngestMailMessageJob::dispatch((int) $id);
                $count++;
            }
        }

        return $count;
    }
}
