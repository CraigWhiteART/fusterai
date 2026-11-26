<?php

namespace App\Domains\Agent\Mining;

use RuntimeException;

/** The next model call would take the run past its budget cap. */
final class BudgetExhausted extends RuntimeException
{
    public function __construct(
        public readonly int $spentMicroUsd,
        public readonly int $budgetMicroUsd,
        public readonly int $nextCallMicroUsd,
        public readonly string $stage,
    ) {
        parent::__construct(sprintf(
            'Budget cap of $%.2f reached during %s: $%.4f spent, the next call would cost about $%.4f. Raise the cap and resume to continue.',
            $budgetMicroUsd / 1_000_000,
            $stage,
            $spentMicroUsd / 1_000_000,
            $nextCallMicroUsd / 1_000_000,
        ));
    }
}
