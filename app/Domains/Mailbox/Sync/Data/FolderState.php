<?php

namespace App\Domains\Mailbox\Sync\Data;

/**
 * Result of EXAMINE: the folder's UID epoch and where the next UID will land.
 * Every UID is only meaningful together with its UIDVALIDITY.
 */
final readonly class FolderState
{
    public function __construct(
        public FolderRef $folder,
        public int $uidValidity,
        public int $uidNext,
        public int $exists = 0,
    ) {}

    /** Highest UID that can currently exist (UIDNEXT - 1). */
    public function maxUid(): int
    {
        return max(0, $this->uidNext - 1);
    }
}
