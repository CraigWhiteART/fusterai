<?php

namespace App\Domains\Commerce\Actions\Definitions;

use App\Domains\Commerce\Actions\Models\ActionExecution;
use App\Domains\Commerce\Actions\Models\ActionSettings;
use App\Domains\Commerce\Actions\Support\Scopes;

/**
 * What an action needs besides its params and the order: the execution row
 * (for markers / idempotency keys), workspace settings and granted scopes.
 */
final class ActionContext
{
    /** @var array<string, mixed> extra reads an action made in prepare() (e.g. Shopify's suggested refund) */
    public array $extra = [];

    public function __construct(
        public readonly ActionExecution $execution,
        public readonly ActionSettings $settings,
        public readonly Scopes $scopes,
        public readonly ?string $approverName = null,
    ) {}

    public function marker(): string
    {
        return $this->execution->marker();
    }

    /** Text written into Shopify (order note / staff note) for the audit trail. */
    public function auditLine(string $what): string
    {
        $by = $this->approverName !== null ? "approved by {$this->approverName}" : 'unattended, within policy limits';

        return '['.config('agent_actions.helpdesk_name', 'fusterai').' '.now()->format('Y-m-d H:i').'] '.$what.' ('.$by.') - '.$this->marker();
    }
}
