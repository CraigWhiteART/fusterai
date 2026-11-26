<?php

namespace App\Console\Commands;

use App\Domains\Mailbox\Models\Mailbox;
use App\Domains\Mailbox\Sync\InboxReconciler;
use Illuminate\Console\Command;
use Throwable;

/**
 * Closes open conversations whose customer mail is no longer in the Gmail
 * Inbox (InboxReconciler), reading the Inbox as it is now. emails:fetch does
 * the same every run, with a limit on how many it will close at once; this
 * command has no limit and can also cover conversations from before the mail
 * ledger (--include-unsynced). Nothing is sent or deleted.
 *
 * Without --apply it only reports what it would do.
 */
class MailCloseArchived extends Command
{
    protected $signature = 'mail:close-archived
                            {--mailbox=* : Mailbox IDs (default: all active mailboxes)}
                            {--include-unsynced : Also conversations that have no synced mail (matched by Message-ID)}
                            {--apply : Make the changes}';

    protected $description = 'Close open conversations whose mail is archived in Gmail';

    public function handle(InboxReconciler $reconciler): int
    {
        $query = Mailbox::query()->where('active', true);
        if ($ids = $this->option('mailbox')) {
            $query->whereIn('id', $ids);
        }

        $failed = 0;
        foreach ($query->orderBy('id')->get() as $mailbox) {
            try {
                $r = $reconciler->reconcile($mailbox, (bool) $this->option('apply'), (bool) $this->option('include-unsynced'));
            } catch (Throwable $e) {
                $failed++;
                $this->error("Mailbox {$mailbox->id} ({$mailbox->name}): ".$e->getMessage());

                continue;
            }

            if (! $r['supported']) {
                $this->line("Mailbox {$mailbox->id} ({$mailbox->name}): not Gmail (or the Inbox is too large), skipped.");

                continue;
            }

            $this->info("Mailbox {$mailbox->id} ({$mailbox->name}): {$r['inbox']} messages in the Inbox; {$r['open']} open conversations, {$r['in_inbox']} kept, {$r['to_close']} to close, {$r['closed']} closed.");
        }

        if (! $this->option('apply')) {
            $this->warn('Dry run. Nothing changed. Add --apply to make the changes.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
