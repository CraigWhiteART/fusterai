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
}
