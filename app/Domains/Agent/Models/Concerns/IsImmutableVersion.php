<?php

namespace App\Domains\Agent\Models\Concerns;

use LogicException;

/**
 * Version rows are append-only: an edit is a new version, and restore copies
 * an old version into a new one. Runs reference version ids, so changing a
 * row in place would silently change what a recorded run "used".
 */
trait IsImmutableVersion
{
    public static function bootIsImmutableVersion(): void
    {
        static::updating(function (): void {
            throw new LogicException('Version rows are immutable; create a new version instead.');
        });
    }
}
