<?php

namespace App\Domains\Mailbox\Sync\Jobs;

use App\Domains\Mailbox\Sync\MailIngestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Turns one mail_messages row into a thread. Replaces ProcessInboundEmailJob
 * for IMAP mail. Safe to dispatch more than once for the same id (see
 * MailIngestService).
 *
 * $historical suppresses hooks, notifications, automation, AI and
 * auto-replies, and creates conversations closed + imported (backfill).
 */
class IngestMailMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Below the email-inbound supervisor timeout (120s); may re-read one message over IMAP. */
    public int $timeout = 90;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function __construct(
        public readonly int $mailMessageId,
        public readonly bool $historical = false,
    ) {
        $this->onQueue('email-inbound');
    }

    public function handle(MailIngestService $ingest): void
    {
        $ingest->ingest($this->mailMessageId, $this->historical);
    }
}
