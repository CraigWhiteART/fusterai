<?php

namespace App\Domains\Commerce\Actions\Policy;

final class ResolvedPolicy
{
    /**
     * @param  array<string, mixed>  $limits
     * @param  list<string>  $forced
     */
    public function __construct(
        public readonly string $actionKey,
        public readonly string $mode,
        public readonly array $limits,
        public readonly string $source,
        public readonly array $forced,
        public readonly ?int $workspacePolicyId,
        public readonly ?int $playbookPolicyId,
        public readonly ?int $playbookId,
    ) {}

    public function limit(string $key): mixed
    {
        return $this->limits[$key] ?? null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'action_key' => $this->actionKey,
            'mode' => $this->mode,
            'limits' => $this->limits,
            'source' => $this->source,
            'forced' => $this->forced,
            'workspace_policy_id' => $this->workspacePolicyId,
            'playbook_policy_id' => $this->playbookPolicyId,
            'playbook_id' => $this->playbookId,
        ];
    }
}
