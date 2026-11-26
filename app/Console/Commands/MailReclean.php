<?php

namespace App\Console\Commands;

use App\Domains\Mailbox\Sync\Parsing\QuoteStripper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes the cleaned text of stored mail (mail_messages.body_clean) with
 * the current QuoteStripper, and the plain text of the threads made from it:
 * a thread is updated when it still holds the old cleaned text, or when it is
 * a customer message (the ledger text replaces what an older importer kept).
 * Raw bodies are never changed. Use after a cleaner fix.
 *
 * Without --apply it only counts what would change.
 */
class MailReclean extends Command
{
    protected $signature = 'mail:reclean {--apply : Make the changes}';

    protected $description = 'Re-clean stored mail bodies with the current quote and signature stripper';

    public function handle(QuoteStripper $stripper): int
    {
        $apply = (bool) $this->option('apply');
        $seen = $changed = $threads = 0;

        DB::table('mail_messages')
            ->whereNotNull('body_fetched_at')
            ->where(fn ($q) => $q->whereNotNull('body_html')->orWhereNotNull('body_text'))
            ->select(['id', 'body_html', 'body_text', 'body_clean', 'thread_id'])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($stripper, $apply, &$seen, &$changed, &$threads) {
                foreach ($rows as $row) {
                    $seen++;
                    $old = (string) $row->body_clean;
                    $new = $stripper->clean($row->body_html, $row->body_text);
                    $thread = $row->thread_id !== null
                        ? DB::table('threads')->where('id', $row->thread_id)->where('mail_message_id', $row->id)->first(['id', 'body_plain', 'customer_id'])
                        : null;
                    $threadStale = $thread !== null && $new !== '' && (string) $thread->body_plain !== $new
                        && ((string) $thread->body_plain === $old || $thread->customer_id !== null);

                    if ($new !== $old) {
                        $changed++;
                        if ($apply) {
                            DB::table('mail_messages')->where('id', $row->id)->update(['body_clean' => $new]);
                        }
                    }
                    if ($threadStale) {
                        $threads++;
                        if ($apply) {
                            DB::table('threads')->where('id', $thread->id)->update(['body_plain' => $new]);
                        }
                    }
                }
            });

        // Customer threads from before the mail ledger: their plain text was
        // made by the old importer, which left CSS and template markup in.
        $legacy = 0;
        DB::table('threads')
            ->where('type', 'message')
            ->whereNotNull('customer_id')
            ->whereNull('mail_message_id')
            ->where('body', 'like', '%<%')
            ->select(['id', 'body', 'body_plain'])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($stripper, $apply, &$legacy) {
                foreach ($rows as $row) {
                    $new = $stripper->clean((string) $row->body, null);
                    if ($new !== '' && $new !== (string) $row->body_plain) {
                        $legacy++;
                        if ($apply) {
                            DB::table('threads')->where('id', $row->id)->update(['body_plain' => $new]);
                        }
                    }
                }
            });
        $this->info("{$legacy} pre-ledger customer threads ".($apply ? 'updated' : 'would change').'.');

        $this->info("{$seen} messages checked; {$changed} cleaned texts ".($apply ? 'updated' : 'would change')."; {$threads} threads ".($apply ? 'updated' : 'would change').'.');
        if (! $apply) {
            $this->warn('Dry run. Add --apply to make the changes.');
        }

        return self::SUCCESS;
    }
}
