<?php

namespace App\Domains\Mailbox\Sync;

use App\Domains\Mailbox\Models\Mailbox;
use App\Enums\ConversationStatus;
use Illuminate\Support\Facades\DB;

/**
 * "Archived in Gmail = done." Compares the Gmail Inbox with the open
 * conversations of a mailbox:
 *
 *  1. the \Inbox label of the ledger rows is brought in line with the Inbox;
 *  2. an open or pending conversation whose customer mail has all left the
 *     Inbox is closed. A customer writing again reopens it (MailIngestService).
 *
 * Conversations are closed with a plain update: no notification, survey,
 * automation or email follows. Mail that arrived in the last few minutes is
 * left alone, so a message synced between the two reads is never closed.
 *
 * $includeUnsynced also covers conversations from before the mail ledger
 * (no ledger rows): they stay open only when one of their messages is in the
 * Inbox by Message-ID. Used once, by mail:close-archived.
 *
 * Read-only on the mail server. Does nothing for non-Gmail mailboxes.
 */
class InboxReconciler
{
    /** Customer mail newer than this never closes its conversation. */
    private const GRACE_MINUTES = 10;

    public function __construct(private readonly MailSyncService $sync) {}

    /**
     * @param  int|null  $maxClose  refuse to close more than this many in one go (null = no limit)
     * @return array{supported: bool, inbox: int, relabelled: int, open: int, in_inbox: int, to_close: int, closed: int, capped: bool}
     */
    public function reconcile(Mailbox $mailbox, bool $apply = true, bool $includeUnsynced = false, ?int $maxClose = null): array
    {
        $report = ['supported' => false, 'inbox' => 0, 'relabelled' => 0, 'open' => 0, 'in_inbox' => 0, 'to_close' => 0, 'closed' => 0, 'capped' => false];

        $snapshot = $this->sync->inboxSnapshot($mailbox);
        if ($snapshot === null) {
            return $report;
        }
        $report['supported'] = true;
        $report['inbox'] = count($snapshot['gm']);

        if ($apply) {
            $report['relabelled'] = $this->relabel($mailbox, $snapshot['gm']);
        }

        $open = DB::table('conversations')
            ->where('mailbox_id', $mailbox->id)
            ->whereIn('status', [ConversationStatus::Open->value, ConversationStatus::Pending->value])
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $report['open'] = count($open);

        $synced = [];
        $keep = [];
        $recent = now()->subMinutes(self::GRACE_MINUTES);
        foreach (array_chunk($open, 1000) as $chunk) {
            $rows = DB::table('mail_messages')
                ->whereIn('conversation_id', $chunk)
                ->where('direction', 'inbound')
                ->whereNotNull('gm_msgid')
                ->get(['conversation_id', 'gm_msgid', 'created_at']);
            foreach ($rows as $row) {
                $id = (int) $row->conversation_id;
                $synced[$id] = true;
                if (isset($snapshot['gm'][(string) $row->gm_msgid]) || $recent->lt($row->created_at)) {
                    $keep[$id] = true;
                }
            }
        }

        $close = array_diff(array_keys($synced), array_keys($keep));

        if ($includeUnsynced) {
            $unsynced = array_values(array_diff($open, array_keys($synced)));
            foreach (array_chunk($unsynced, 500) as $chunk) {
                $inInbox = [];
                $threads = DB::table('threads')->whereIn('conversation_id', $chunk)
                    ->get(['conversation_id', 'message_id', DB::raw("meta->>'message_id' as meta_message_id"), 'created_at']);
                foreach ($threads as $thread) {
                    foreach ([$thread->message_id, $thread->meta_message_id] as $candidate) {
                        if (is_string($candidate) && isset($snapshot['message_ids'][trim($candidate, '<> ')])) {
                            $inInbox[(int) $thread->conversation_id] = true;
                        }
                    }
                    if ($recent->lt($thread->created_at)) {
                        $inInbox[(int) $thread->conversation_id] = true;
                    }
                }
                foreach ($chunk as $id) {
                    if (isset($inInbox[$id])) {
                        $keep[$id] = true;
                    } else {
                        $close[] = $id;
                    }
                }
            }
        }

        $close = array_values(array_unique($close));
        $report['in_inbox'] = count($keep);
        $report['to_close'] = count($close);

        if (! $apply || $close === []) {
            return $report;
        }
        if ($maxClose !== null && count($close) > $maxClose) {
            $report['capped'] = true;

            return $report;
        }

        foreach (array_chunk($close, 500) as $chunk) {
            $report['closed'] += DB::table('conversations')
                ->whereIn('id', $chunk)
                ->whereIn('status', [ConversationStatus::Open->value, ConversationStatus::Pending->value])
                ->update(['status' => ConversationStatus::Closed->value, 'snoozed_until' => null, 'updated_at' => now()]);
        }

        return $report;
    }

    /**
     * Ledger rows gain or lose the \Inbox label to match the Inbox.
     *
     * @param  array<string, true>  $inbox  X-GM-MSGIDs in the Inbox
     */
    private function relabel(Mailbox $mailbox, array $inbox): int
    {
        $changed = 0;

        $labelled = DB::table('mail_messages')
            ->where('mailbox_id', $mailbox->id)
            ->whereNotNull('gm_msgid')
            ->whereRaw('labels @> ?::jsonb', [json_encode(['\\Inbox'])])
            ->get(['id', 'gm_msgid', 'labels']);
        $have = [];
        foreach ($labelled as $row) {
            if (isset($inbox[(string) $row->gm_msgid])) {
                $have[(string) $row->gm_msgid] = true;

                continue;
            }
            $labels = array_values(array_filter(
                (array) json_decode((string) $row->labels, true),
                fn ($l) => ! is_string($l) || strcasecmp($l, '\\Inbox') !== 0,
            ));
            DB::table('mail_messages')->where('id', $row->id)->update(['labels' => json_encode($labels)]);
            $changed++;
        }

        $missing = array_map('strval', array_keys(array_diff_key($inbox, $have)));
        foreach (array_chunk($missing, 500) as $chunk) {
            foreach (DB::table('mail_messages')->where('mailbox_id', $mailbox->id)->whereIn('gm_msgid', $chunk)->get(['id', 'labels']) as $row) {
                $labels = (array) json_decode((string) $row->labels, true);
                $labels[] = '\\Inbox';
                DB::table('mail_messages')->where('id', $row->id)->update(['labels' => json_encode(array_values($labels))]);
                $changed++;
            }
        }

        return $changed;
    }
}
