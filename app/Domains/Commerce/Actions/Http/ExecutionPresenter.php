<?php

namespace App\Domains\Commerce\Actions\Http;

use App\Domains\Commerce\Actions\ActionApprovals;
use App\Domains\Commerce\Actions\ActionRegistry;
use App\Domains\Commerce\Actions\Models\ActionExecution;
use App\Models\User;

/** JSON shapes for docs/agent-actions.md ("ActionExecution"). */
class ExecutionPresenter
{
    public const REASON_LABELS = [
        'policy_never' => 'This action is switched off (policy: never).',
        'policy_requires_approval' => 'The policy for this action requires approval.',
        'agent_mode_not_live' => 'The agent is not in live mode, so nothing runs unattended.',
        'autonomy_paused' => 'Automatic work is paused (Pause AI agent), so nothing runs unattended.',
        'kill_switch' => 'The agent kill switch is on.',
        'run_handed_over' => 'The agent handed this conversation to a human.',
        'order_state_unavailable' => 'The order could not be read from Shopify.',
        'preconditions_failed' => 'A precondition failed (see checks).',
        'invalid_params' => 'The proposed parameters are not valid.',
        'sender_email_mismatch' => 'The sender\'s email does not match the order email.',
        'amount_unknown' => 'The amount could not be determined.',
        'over_hard_ceiling' => 'Above the refund hard ceiling: approval only.',
        'no_max_amount_limit' => 'No maximum amount is set for this action.',
        'over_max_amount' => 'Above the maximum amount per action.',
        'no_max_percentage_limit' => 'No maximum discount percentage is set.',
        'over_max_percentage' => 'Above the maximum discount percentage.',
        'over_customer_30d_count' => 'This customer reached the 30-day count limit.',
        'over_customer_30d_amount' => 'This customer reached the 30-day amount limit.',
        'over_daily_count' => 'Today\'s count limit for this action is reached.',
        'over_daily_amount' => 'Today\'s amount limit for this action is reached.',
        'daily_action_cap' => 'Today\'s unattended action cap (agent settings) is reached.',
        'over_order_age' => 'The order is older than the policy allows.',
        'fulfilment_state_not_allowed' => 'The order\'s fulfilment state is not allowed by the policy.',
        'country_not_allowed' => 'The shipping country is not allowed by the policy.',
        'country_changed' => 'The new address is in a different country.',
        'warehouse_guard' => 'The 4PX warehouse may already have the order.',
        'approval_only_action' => 'This action is always approval-only.',
    ];

    public function __construct(
        private readonly ActionRegistry $registry,
        private readonly ActionApprovals $approvals,
    ) {}

    /** @return array<string, mixed> */
    public function summary(ActionExecution $e, ?User $viewer = null): array
    {
        $definition = $this->registry->has($e->action_key) ? $this->registry->get($e->action_key) : null;

        return [
            'id' => $e->id,
            'action_key' => $e->action_key,
            'label' => $definition?->label() ?? $e->action_key,
            'status' => $e->status,
            'route' => $e->route,
            'description' => $e->description,
            'order_number' => $e->order_number,
            'amount' => $e->amount,
            'currency' => $e->currency,
            'customer_email' => $e->customer_email,
            'conversation_id' => $e->conversation_id,
            'conversation_subject' => $e->conversation?->subject,
            'run_id' => $e->run_id,
            'reply_blocking' => $e->reply_blocking,
            'money' => $definition?->isMoney() ?? false,
            'reversible' => $definition?->reversible() ?? false,
            'risk' => $definition?->riskTier(),
            'approval_reasons' => array_map(fn ($r) => ['code' => $r, 'label' => self::REASON_LABELS[$r] ?? $r], (array) ($e->approval_reasons ?? [])),
            'warnings' => (array) ($e->warnings ?? []),
            'has_danger_warning' => collect((array) ($e->warnings ?? []))->contains(fn ($w) => ($w['level'] ?? '') === 'danger'),
            'required_role' => $definition !== null ? $this->approvals->requiredRole($e) : null,
            'can_approve' => $viewer !== null && $e->status === ActionExecution::PENDING_APPROVAL && $this->approvals->canApprove($viewer, $e),
            'expires_at' => $e->expires_at?->toIso8601String(),
            'approved_by' => $e->approver !== null ? ['id' => $e->approver->id, 'name' => $e->approver->name] : null,
            'approved_at' => $e->approved_at?->toIso8601String(),
            'rejected_by' => $e->rejecter !== null ? ['id' => $e->rejecter->id, 'name' => $e->rejecter->name] : null,
            'rejected_at' => $e->rejected_at?->toIso8601String(),
            'rejection_reason' => $e->rejection_reason,
            'error' => $e->error,
            'dry_run' => $e->dry_run,
            'executed_at' => $e->executed_at?->toIso8601String(),
            'completed_at' => $e->completed_at?->toIso8601String(),
            'created_at' => $e->created_at?->toIso8601String(),
            'updated_at' => $e->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(ActionExecution $e, ?User $viewer = null): array
    {
        $definition = $this->registry->has($e->action_key) ? $this->registry->get($e->action_key) : null;

        return [
            ...$this->summary($e, $viewer),
            'params' => $e->params,
            'param_errors' => $e->param_errors,
            'params_edited' => $e->params_edited,
            'params_hint' => $definition?->paramsHint(),
            'reason' => $e->reason,
            'cited_evidence' => (array) ($e->cited_evidence ?? []),
            'policy_snapshot' => $e->policy_snapshot,
            'precondition_snapshot' => $e->precondition_snapshot,
            'request' => $e->request,
            'response' => $e->response,
            'verify_result' => $e->verify_result,
            'undo' => $e->undo,
            'reconcile_attempts' => $e->reconcile_attempts,
            'rewrite_requested' => $e->rewrite_requested,
            'idempotency_key' => $e->idempotency_key,
        ];
    }
}
