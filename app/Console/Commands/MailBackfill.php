<?php

namespace App\Console\Commands;

use App\Domains\Mailbox\Models\Mailbox;
use App\Domains\Mailbox\Sync\Backfill\BackfillAlreadyRunning;
use App\Domains\Mailbox\Sync\Backfill\BackfillRunner;
use App\Domains\Mailbox\Sync\Backfill\BackfillService;
use App\Domains\Mailbox\Sync\Backfill\MailBackfillRun;
use Illuminate\Console\Command;

/**
 * Import a mailbox's history as closed, imported conversations (see
 * docs/mail-sync.md, "Backfill"). Read-only against the mail server.
 *
 *   mail:backfill 1                     queue a full import of the last 18 months
 *   mail:backfill 1 --months=6 --dry-run  sweep + filter only, print what would be imported
 *   mail:backfill 1 --sync              run the whole import in this process
 *   mail:backfill --recover             re-queue stalled runs (scheduled)
 */
class MailBackfill extends Command
{
    protected $signature = 'mail:backfill
                            {mailbox? : Mailbox id or address}
                            {--months= : How far back to import (default mailsync.backfill.default_months)}
                            {--dry-run : Only sweep headers and filter threads; print the counts}
                            {--include-unanswered : Also import threads nobody replied to}
                            {--sync : Run in this process instead of on the ai-bulk queue}
                            {--recover : Re-queue backfills that stopped making progress}';

    protected $description = 'Import mailbox history (inbox and sent) as closed, imported conversations';

    public function handle(BackfillService $service, BackfillRunner $runner): int
    {
        if ($this->option('recover')) {
            $count = $service->recoverStalled();
            $this->info("Re-queued {$count} stalled backfill(s).");

            return self::SUCCESS;
        }

        $key = (string) $this->argument('mailbox');
        $mailbox = $key === '' ? null : Mailbox::query()
            ->when(ctype_digit($key), fn ($q) => $q->whereKey((int) $key), fn ($q) => $q->whereRaw('lower(email) = ?', [strtolower($key)]))
            ->first();
        if ($mailbox === null) {
            $this->error($key === '' ? 'Give a mailbox id or address.' : "No mailbox {$key}.");

            return self::FAILURE;
        }
        if (empty($mailbox->imap_config['host'] ?? null)) {
            $this->error("Mailbox {$mailbox->email} has no IMAP settings.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $inline = $dryRun || (bool) $this->option('sync');

        try {
            $run = $service->start(
                $mailbox,
                months: $this->option('months') !== null ? (int) $this->option('months') : null,
                dryRun: $dryRun,
                includeUnanswered: $this->option('include-unanswered') ? true : null,
                dispatch: ! $inline,
            );
        } catch (BackfillAlreadyRunning $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(($dryRun ? 'Dry run' : 'Import')." #{$run->id}: {$mailbox->email}, {$run->months} months (since {$run->since->toDateString()}).");

        if (! $inline) {
            $this->info('Queued on '.config('mailsync.backfill.queue').'. Follow it at /agent/import.');

            return self::SUCCESS;
        }

        $stage = null;
        while (true) {
            $delay = $runner->runStep($run->id, $run->job_token);
            $run->refresh();
            if ($run->stage !== $stage) {
                $stage = $run->stage;
                $this->line("  stage: {$stage}");
            }
            if ($delay === null) {
                break;
            }
            if ($delay >= BackfillRunner::LOCK_WAIT_SECONDS) {
                sleep(min($delay, 60)); // sync lock busy or backing off after an error
            }
        }

        $this->printReport($run);

        return $run->status === MailBackfillRun::STATUS_COMPLETED ? self::SUCCESS : self::FAILURE;
    }

    private function printReport(MailBackfillRun $run): void
    {
        $c = $run->counters ?? [];
        $this->newLine();
        $this->info("Status: {$run->status}".($run->last_error ? " (last error: {$run->last_error})" : ''));
        $this->table(['', 'count'], [
            ['Envelopes fetched', $c['envelopes_fetched'] ?? 0],
            ['Messages in window', $c['messages_seen'] ?? 0],
            ['Threads in window', $c['threads_total'] ?? 0],
            ['Threads to import', $c['threads_kept'] ?? 0],
            ['  of which still active (imported open)', $c['threads_active'] ?? 0],
            ['Messages to import', $c['messages_to_import'] ?? 0],
            ['Threads already imported / live', $c['threads_skipped'] ?? 0],
            ['Threads dropped', $c['threads_dropped'] ?? 0],
        ]);

        $rows = [];
        foreach (['kept_reasons' => 'kept', 'dropped_reasons' => 'dropped', 'skipped_reasons' => 'skipped'] as $key => $label) {
            foreach ((array) ($c[$key] ?? []) as $reason => $n) {
                $rows[] = [$label, $reason, $n];
            }
        }
        if ($rows !== []) {
            $this->table(['decision', 'reason', 'threads'], $rows);
        }

        if (! $run->isDryRun()) {
            $this->table(['', 'count'], [
                ['Bodies fetched', $c['bodies_fetched'] ?? 0],
                ['Conversations created', $c['conversations_created'] ?? 0],
                ['Messages ingested', $c['messages_ingested'] ?? 0],
                ['Messages linked to existing threads', $c['messages_linked'] ?? 0],
                ['Messages skipped (automated, internal, drafts)', $c['messages_skipped'] ?? 0],
                ['Errors', $run->error_count + ($c['message_errors'] ?? 0)],
            ]);
        }
    }
}
