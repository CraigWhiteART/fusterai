<?php

namespace App\Domains\Mailbox\Sync;

/**
 * Outcome of one syncNew() / backfillMetadata() run across a mailbox's folders.
 */
final class SyncReport
{
    /** Envelopes received from the server. */
    public int $fetched = 0;

    /** New ledger rows. */
    public int $inserted = 0;

    /** Existing rows whose location (folder/UIDVALIDITY/UID) or labels changed. */
    public int $updated = 0;

    /** Bodies downloaded into the ledger. */
    public int $bodiesFetched = 0;

    /** UID ranges completed (each one checkpointed). */
    public int $ranges = 0;

    /** @var list<int> ids of the rows inserted by this run, ascending */
    public array $newMessageIds = [];

    /** @var list<string> folders whose UIDVALIDITY changed and whose cursor was reset */
    public array $uidValidityResets = [];

    /** Backfill only: every folder reached the stop UID. */
    public bool $completed = true;

    public function merge(SyncReport $other): void
    {
        $this->fetched += $other->fetched;
        $this->inserted += $other->inserted;
        $this->updated += $other->updated;
        $this->bodiesFetched += $other->bodiesFetched;
        $this->ranges += $other->ranges;
        $this->newMessageIds = array_merge($this->newMessageIds, $other->newMessageIds);
        $this->uidValidityResets = array_merge($this->uidValidityResets, $other->uidValidityResets);
        $this->completed = $this->completed && $other->completed;
    }
}
