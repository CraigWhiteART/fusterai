<?php

namespace App\Domains\Agent\Proposals;

use RuntimeException;

/**
 * The proposal cannot move that way now (wrong status, stale base, blocking
 * conflicts, gate still running, live pointer moved since apply). `code` is a
 * machine-readable reason the HTTP layer returns with a 409.
 */
class ProposalStateException extends RuntimeException
{
    /** @param  array<string, mixed>  $context */
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
