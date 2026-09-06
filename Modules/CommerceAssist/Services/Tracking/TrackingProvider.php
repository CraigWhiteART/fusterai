<?php

namespace Modules\CommerceAssist\Services\Tracking;

/**
 * A carrier-tracking source. Implementations own their vendor's HTTP shape and
 * return the normalised TrackingResult, or null when they cannot answer at all
 * (missing credentials, no usable key in the query).
 */
interface TrackingProvider
{
    /** Stable key stored on settings and snapshots. */
    public function key(): string;

    public function lookup(TrackingQuery $query): ?TrackingResult;

    /**
     * Cheap round-trip used by the settings "Test connection" button.
     * Throws when the key is rejected or the provider cannot be reached.
     *
     * @return array{message: string}
     */
    public function ping(): array;
}
