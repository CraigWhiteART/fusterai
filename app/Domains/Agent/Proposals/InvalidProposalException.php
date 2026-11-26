<?php

namespace App\Domains\Agent\Proposals;

use RuntimeException;

/**
 * Ops that do not apply to the current live versions (unknown op, unknown
 * item id, missing text, target in another workspace...). `errors` lists
 * every problem as "changes.N.ops.M: message"; `suggestions` carries any
 * forbidden ops that were set aside before validation failed.
 */
class InvalidProposalException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     * @param  list<array<string, mixed>>  $suggestions
     */
    public function __construct(
        public readonly array $errors,
        public readonly array $suggestions = [],
    ) {
        parent::__construct('Invalid proposal: '.implode('; ', array_slice($errors, 0, 5)));
    }
}
