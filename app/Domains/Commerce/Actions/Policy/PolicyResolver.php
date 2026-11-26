<?php

namespace App\Domains\Commerce\Actions\Policy;

use App\Domains\Commerce\Actions\ActionRegistry;
use App\Domains\Commerce\Actions\Models\ActionPolicy;

/**
 * Resolves the effective policy for (workspace, action, playbook).
 *
 *  1. Workspace row (playbook_id null). Missing = `never`.
 *  2. Playbook row, when there is one, may only make it MORE restrictive
 *     (stricter mode, lower numbers, narrower lists) unless an admin set
 *     override_workspace on it, in which case it replaces the workspace row.
 *  3. Code rules on top: replacement is never unattended; money / address
 *     actions always require the sender email to match the order email.
 *     (The refund hard ceiling depends on the amount: LimitEvaluator.)
 */
class PolicyResolver
{
    public const LIMIT_KEYS = [
        'max_amount', 'per_customer_30d_amount', 'per_customer_30d_count', 'per_day_amount', 'per_day_count',
        'max_percentage', 'max_order_age_days', 'allowed_fulfilment_states', 'require_sender_email_match', 'allowed_countries',
    ];

    public const NUMERIC = ['max_amount', 'per_customer_30d_amount', 'per_customer_30d_count', 'per_day_amount', 'per_day_count', 'max_percentage', 'max_order_age_days'];

    public const LISTS = ['allowed_fulfilment_states', 'allowed_countries'];

    public function __construct(private readonly ActionRegistry $registry) {}

    public function resolve(int $workspaceId, string $actionKey, ?int $playbookId): ResolvedPolicy
    {
        $workspace = ActionPolicy::where('workspace_id', $workspaceId)->where('action_key', $actionKey)->whereNull('playbook_id')->first();
        $playbook = $playbookId !== null
            ? ActionPolicy::where('workspace_id', $workspaceId)->where('action_key', $actionKey)->where('playbook_id', $playbookId)->first()
            : null;

        $mode = $workspace->mode ?? ActionPolicy::NEVER;
        $limits = self::clean((array) ($workspace->limits ?? []));
        $source = 'workspace';

        if ($playbook !== null) {
            if ($playbook->override_workspace) {
                $mode = $playbook->mode;
                $limits = self::clean((array) ($playbook->limits ?? []));
                $source = 'playbook_override';
            } else {
                $mode = ActionPolicy::STRICTNESS[$playbook->mode] > ActionPolicy::STRICTNESS[$mode] ? $playbook->mode : $mode;
                $limits = self::stricter($limits, self::clean((array) ($playbook->limits ?? [])));
                $source = 'workspace+playbook';
            }
        }

        $forced = [];
        $definition = $this->registry->has($actionKey) ? $this->registry->get($actionKey) : null;
        if ($definition === null) {
            $mode = ActionPolicy::NEVER;
            $forced[] = 'unknown_action';
        } else {
            if (! $definition->unattendedAllowed() && $mode === ActionPolicy::ALWAYS) {
                $mode = ActionPolicy::APPROVAL;
                $forced[] = 'approval_only_action';
            }
            if ($definition->isMoney() && ($limits['require_sender_email_match'] ?? true) !== true) {
                $forced[] = 'sender_match_mandatory';
            }
            if ($definition->isMoney()) {
                $limits['require_sender_email_match'] = true;
            }
        }
        $limits['require_sender_email_match'] ??= true;

        return new ResolvedPolicy(
            actionKey: $actionKey,
            mode: $mode,
            limits: $limits,
            source: $source,
            forced: $forced,
            workspacePolicyId: $workspace?->id,
            playbookPolicyId: $playbook?->id,
            playbookId: $playbookId,
        );
    }

    /**
     * @param  array<string, mixed>  $limits
     * @return array<string, mixed>
     */
    public static function clean(array $limits): array
    {
        $out = [];
        foreach (self::LIMIT_KEYS as $key) {
            if (! array_key_exists($key, $limits) || $limits[$key] === null || $limits[$key] === '') {
                continue;
            }
            $value = $limits[$key];
            $out[$key] = match (true) {
                in_array($key, self::LISTS, true) => array_values(array_unique(array_map(fn ($v) => strtoupper(trim((string) $v)), (array) $value))),
                $key === 'require_sender_email_match' => (bool) $value,
                in_array($key, ['max_amount', 'per_customer_30d_amount', 'per_day_amount'], true) => number_format((float) $value, 2, '.', ''),
                default => is_numeric($value) ? $value + 0 : null,
            };
        }

        return array_filter($out, fn ($v) => $v !== null);
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    public static function stricter(array $a, array $b): array
    {
        $out = $a;
        foreach ($b as $key => $value) {
            if (! array_key_exists($key, $a)) {
                $out[$key] = $value;

                continue;
            }
            $out[$key] = match (true) {
                in_array($key, self::NUMERIC, true) => (float) $value < (float) $a[$key] ? $value : $a[$key],
                in_array($key, self::LISTS, true) => array_values(array_intersect((array) $a[$key], (array) $value)),
                $key === 'require_sender_email_match' => (bool) $a[$key] || (bool) $value,
                default => $a[$key],
            };
        }

        return $out;
    }
}
