<?php

namespace App\Domains\Mailbox\Sync\Backfill;

use RuntimeException;

/** A mailbox already has a backfill that is queued, running, paused or undoing. */
final class BackfillAlreadyRunning extends RuntimeException
{
    public static function forMailbox(int $mailboxId): self
    {
        return new self("Mailbox {$mailboxId} already has a history import in progress. Pause, cancel or finish it first.");
    }
}
