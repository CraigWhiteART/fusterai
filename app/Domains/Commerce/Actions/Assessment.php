<?php

namespace App\Domains\Commerce\Actions;

use App\Domains\Commerce\Actions\Definitions\ActionContext;
use App\Domains\Commerce\Actions\Definitions\Checks;
use App\Domains\Commerce\Actions\Policy\ResolvedPolicy;

/** Fresh state + preconditions + policy for one execution at one moment. */
final class Assessment
{
    /**
     * @param  list<string>  $approvalReasons  why it cannot run unattended (empty = it can)
     * @param  list<array{level: string, text: string}>  $warnings
     * @param  array<string, mixed>  $limitDetails
     */
    public function __construct(
        public readonly ?OrderState $state,
        public readonly ?Checks $checks,
        public readonly ResolvedPolicy $policy,
        public readonly ActionContext $context,
        public readonly array $approvalReasons,
        public readonly array $warnings,
        public readonly array $limitDetails,
        public readonly ?string $unavailable,
        public readonly ?string $stateError,
        public readonly string $description,
    ) {}

    public function blocked(): bool
    {
        return $this->unavailable !== null || $this->policy->mode === 'never';
    }

    /** Hard preconditions hold (whoever approved it). */
    public function executable(): bool
    {
        return ! $this->blocked() && $this->state !== null && $this->checks !== null && $this->checks->passed();
    }

    public function unattended(): bool
    {
        return $this->executable() && $this->approvalReasons === [];
    }

    /** @return array<string, mixed> */
    public function preconditionSnapshot(): array
    {
        return [
            'at' => now()->toIso8601String(),
            'order' => $this->state?->snapshot(),
            'state_error' => $this->stateError,
            ...($this->checks?->toArray() ?? ['passed' => false, 'checks' => [], 'warnings' => []]),
            'computed' => $this->checks !== null ? self::scalarOnly($this->checks->computed) : [],
            'limits' => $this->limitDetails,
            'unavailable' => $this->unavailable,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function scalarOnly(array $values): array
    {
        return array_map(fn ($v) => is_array($v) ? array_intersect_key($v, array_flip(['id', 'gateway', 'kind'])) + (array_is_list($v) ? $v : []) : $v, $values);
    }
}
