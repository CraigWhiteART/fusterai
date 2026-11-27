<?php

namespace App\Domains\Mailbox\Sync\Jobs;

use App\Domains\Conversation\Models\Conversation;
use App\Domains\Mailbox\Sync\Contracts\InboxArchiver;
use App\Domains\Mailbox\Sync\Models\MailMessage;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A conversation was closed or replied to: mark its mail read in Gmail and
 * take it out of the Inbox (mailsync.archive_when_done).
 *
 * Only the conversation's own ledger rows that are in the Inbox are touched,
 * never the rest of the Gmail thread (Gmail threads unrelated customers
 * together, e.g. Shopify contact forms). With $upTo (a reply) only mail that
 * had arrived by then is archived, so a customer message the agent had not
 * seen yet stays in the Inbox and keeps the conversation open
 * (InboxReconciler).
 *
 * Archiving makes InboxReconciler close the conversation on its next run,
 * like any mail archived in Gmail; a new customer message reopens it.
 */
class ArchiveConversationMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(
        public readonly int $conversationId,
        public readonly ?CarbonInterface $upTo = null,
    ) {
        $this->onQueue('email-outbound');
    }

    public static function enabled(): bool
    {
        return (bool) config('mailsync.archive_when_done', true);
    }

    public function handle(InboxArchiver $archiver): void
    {
        if (! self::enabled()) {
            return;
        }

        $conversation = Conversation::with('mailbox')->find($this->conversationId);
        if ($conversation?->mailbox === null) {
            return;
        }

        $rows = MailMessage::query()
            ->where('mailbox_id', $conversation->mailbox_id)
            ->where('conversation_id', $conversation->id)
            ->whereNotNull('gm_msgid')
            ->whereRaw('labels @> ?::jsonb', [json_encode(['\\Inbox'])])
            ->when($this->upTo, fn ($q) => $q->where('created_at', '<=', $this->upTo))
            ->get(['id', 'folder', 'uidvalidity', 'uid']);

        if ($rows->isEmpty()) {
            return;
        }

        $archived = $archiver->archive($conversation->mailbox, $rows);

        // Ledger in step with Gmail now, not on the next sync.
        MailMessage::query()->whereIn('id', $archived)->get(['id', 'labels'])->each(function (MailMessage $row) {
            $row->update(['labels' => array_values(array_filter($row->labels ?? [], fn ($l) => strcasecmp((string) $l, '\\Inbox') !== 0))]);
        });
    }

    public function failed(Throwable $e): void
    {
        Log::warning('Archiving conversation mail in Gmail failed', ['conversation_id' => $this->conversationId, 'error' => $e->getMessage()]);
    }
}
