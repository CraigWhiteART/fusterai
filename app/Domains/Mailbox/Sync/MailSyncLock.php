<?php

namespace App\Domains\Mailbox\Sync;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * The per-mailbox lock around anything that moves a folder cursor in
 * mail_sync_states (emails:fetch's syncNew, the backfill's metadata sweep).
 * Both read-modify-write the same state row, including the UIDVALIDITY
 * reset, so they never run at the same time for one mailbox. Body downloads
 * and ingest do not touch the cursor and do not take it.
 */
final class MailSyncLock
{
    /** Longer than any sane emails:fetch run; released as soon as the holder is done. */
    public const SECONDS = 900;

    public static function for(int $mailboxId, int $seconds = self::SECONDS): Lock
    {
        return Cache::lock('emails:fetch:mailbox:'.$mailboxId, $seconds);
    }
}
