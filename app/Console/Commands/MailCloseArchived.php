<?php

namespace App\Console\Commands;

use App\Domains\Mailbox\Models\Mailbox;
use App\Enums\ConversationStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Closes open conversations whose customer mail is all archived in Gmail
 * (none of their inbound ledger rows carries the \Inbox label, as of the
 * last sync). They are marked imported, so they read as history, not as
 * work that was handled in fusterai. Nothing is sent or deleted.
 *
 * Without --apply it only reports what it would do.
 */
class MailCloseArchived extends Command
{
    protected $signature = 'mail:close-archived
                            {--mailbox=* : Mailbox IDs (default: all active mailboxes)}
                            {--apply : Make the changes}';

    protected $description = 'Close open conversations whose mail is already archived in Gmail';

    public function handle(): int
    {
        $query = Mailbox::query()->where('active', true);
        if ($ids = $this->option('mailbox')) {
            $query->whereIn('id', $ids);
        }

        foreach ($query->orderBy('id')->get() as $mailbox) {
            $open = DB::table('conversations')
                ->where('mailbox_id', $mailbox->id)
                ->whereIn('status', [ConversationStatus::Open->value, ConversationStatus::Pending->value])
                ->whereNull('deleted_at')
                ->pluck('id')
                ->all();

            $inInbox = [];
            $hasMail = [];
            foreach (array_chunk($open, 1000) as $chunk) {
                $rows = DB::table('mail_messages')
                    ->whereIn('conversation_id', $chunk)
                    ->where('direction', 'inbound')
                    ->whereNotNull('gm_msgid')
                    ->get(['conversation_id', 'labels']);
                foreach ($rows as $row) {
                    $hasMail[(int) $row->conversation_id] = true;
                    foreach ((array) json_decode((string) $row->labels, true) as $label) {
                        if (is_string($label) && strcasecmp($label, '\\Inbox') === 0) {
                            $inInbox[(int) $row->conversation_id] = true;
                        }
                    }
                }
            }

            $close = array_values(array_diff(array_keys($hasMail), array_keys($inInbox)));
            $this->info("Mailbox {$mailbox->id} ({$mailbox->name}): ".count($open).' open, '.count($inInbox).' with mail in the Inbox, '.count($close).' to close.');

            if (! $this->option('apply')) {
                continue;
            }

            foreach (array_chunk($close, 500) as $chunk) {
                DB::table('conversations')->whereIn('id', $chunk)->update([
                    'status' => ConversationStatus::Closed->value,
                    'imported' => true,
                    'updated_at' => now(),
                ]);
            }
        }

        if (! $this->option('apply')) {
            $this->warn('Dry run. Nothing changed. Add --apply to make the changes.');
        }

        return self::SUCCESS;
    }
}
