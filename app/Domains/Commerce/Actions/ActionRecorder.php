<?php

namespace App\Domains\Commerce\Actions;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Commerce\Actions\Definitions\DiscountCode;
use App\Domains\Commerce\Actions\Gateway\ShopifyGatewayFactory;
use App\Domains\Commerce\Actions\Jobs\ExecuteActionJob;
use App\Domains\Commerce\Actions\Models\ActionExecution as E;
use App\Domains\Commerce\Actions\Models\ActionSettings;
use App\Domains\Commerce\Actions\Support\Money;
use App\Domains\Conversation\Models\Conversation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Turns a live run's proposed actions into agent_action_executions (the one
 * call RunEffects makes). Each proposal becomes pending_approval, or, when
 * every unattended condition holds on fresh order state, approved +
 * queued. Never dropped silently: anything that cannot run is pending (with
 * reasons) or cancelled (with the reason). An identical proposal (same
 * conversation, action, params, order) reuses the existing row.
 */
class ActionRecorder
{
    public function __construct(
        private readonly ActionRegistry $registry,
        private readonly ActionAssessor $assessor,
        private readonly ShopifyGatewayFactory $gateways,
        private readonly ActionNotifier $notifier,
    ) {}

    /** @return list<E> */
    public function recordForRun(AgentRun $run, Conversation $conversation): array
    {
        if (! $run->isLive()) {
            throw new LogicException("Run {$run->id} has purpose [{$run->purpose}]: only live runs create action executions.");
        }

        $proposed = (array) ($run->proposed_actions ?? []);
        $executions = [];
        $changed = false;

        foreach ($proposed as $i => $action) {
            if (! is_array($action) || ($action['status'] ?? '') !== 'proposed' || isset($action['execution_id'])) {
                continue;
            }

            try {
                $execution = $this->record($run, $conversation, $action);
            } catch (Throwable $e) {
                report($e);
                Log::warning('Recording a proposed action failed', ['run_id' => $run->id, 'error' => $e->getMessage()]);

                continue;
            }
            if ($execution === null) {
                continue;
            }

            $proposed[$i]['execution_id'] = $execution->id;
            $proposed[$i]['execution_status'] = $execution->status;
            $executions[] = $execution;
            $changed = true;
        }

        if ($changed) {
            $run->forceFill(['proposed_actions' => array_values($proposed)])->save();
        }

        return $executions;
    }

    /** @param  array<string, mixed>  $action */
    private function record(AgentRun $run, Conversation $conversation, array $action): ?E
    {
        $key = (string) ($action['action'] ?? '');
        if (! $this->registry->has($key)) {
            return null;
        }

        $definition = $this->registry->get($key);
        $settings = ActionSettings::forWorkspace($run->workspace_id);
        $validated = $definition->validate((array) ($action['params'] ?? []), $settings);
        $params = $validated['params'];

        $evidenceOrder = (array) (($run->evidence['meta']['shopify_payload'] ?? null) ?: []);
        $orderNumber = filled($action['order_number'] ?? null)
            ? strtoupper(ltrim(trim((string) $action['order_number']), '#'))
            : (filled($evidenceOrder['order_number'] ?? null) ? strtoupper(ltrim((string) $evidenceOrder['order_number'], '#')) : null);
        $evidenceNumber = strtoupper(ltrim((string) ($evidenceOrder['order_number'] ?? ''), '#'));
        $orderId = $orderNumber !== null && $orderNumber === $evidenceNumber ? ($evidenceOrder['shopify_order_id'] ?? null) : null;

        $idempotency = $this->idempotencyKey($conversation->id, $key, $params, $orderId ?: $orderNumber);

        $existing = E::where('idempotency_key', $idempotency)->first();
        if ($existing !== null) {
            return $existing;
        }

        $amount = $definition->amountFromParams($params);

        try {
            $execution = E::create([
                'workspace_id' => $run->workspace_id,
                'conversation_id' => $conversation->id,
                'run_id' => $run->id,
                'playbook_id' => $run->playbook_id,
                'purpose' => $run->purpose,
                'action_key' => $key,
                'params' => $params,
                'param_errors' => $validated['errors'] !== [] ? $validated['errors'] : null,
                'order_number' => $orderNumber,
                'order_id' => $orderId,
                'customer_email' => mb_strtolower((string) ($conversation->customer->email ?? '')) ?: null,
                'amount' => $amount !== null ? Money::decimal($amount) : null,
                'status' => E::PROPOSED,
                'reason' => mb_substr((string) ($action['reason'] ?? ''), 0, 2000),
                'cited_evidence' => $this->citations($run, (string) ($action['reason'] ?? '').' '.($action['details'] ?? '')),
                'reply_blocking' => $definition->replyWaits(),
                'idempotency_key' => $idempotency,
            ]);
        } catch (UniqueConstraintViolationException) {
            return E::where('idempotency_key', $idempotency)->first();
        }

        $gateway = $this->gateways->forRun($run);
        $assessment = $this->assessor->assess($execution, $gateway);

        $execution->forceFill([
            'policy_snapshot' => $assessment->policy->toArray(),
            'precondition_snapshot' => $assessment->preconditionSnapshot(),
            'description' => $assessment->description,
            'warnings' => $assessment->warnings,
            'order_id' => $assessment->state?->id() ?: $execution->order_id,
            'customer_email' => $assessment->state?->email() ?: $execution->customer_email,
            'amount' => $assessment->checks?->amountCents !== null ? Money::decimal($assessment->checks->amountCents) : $execution->amount,
            'currency' => $assessment->checks->currency ?? $assessment->state?->currency(),
        ])->save();

        if ($assessment->blocked()) {
            $execution->transition([E::PROPOSED], E::CANCELLED, [
                'error' => 'Not enabled: '.($assessment->unavailable ?? 'the action policy is "never".'),
                'completed_at' => now(),
            ]);
            $this->notifier->changed($execution, 'cancelled');

            return $execution;
        }

        if ($assessment->unattended()) {
            if ($execution->transition([E::PROPOSED], E::APPROVED, ['route' => E::ROUTE_UNATTENDED, 'approved_at' => now(), 'approval_reasons' => []])) {
                $this->notifier->changed($execution, 'approved_unattended');
                ExecuteActionJob::dispatch($execution->id);
            }

            return $execution->refresh();
        }

        $execution->transition([E::PROPOSED], E::PENDING_APPROVAL, [
            'route' => E::ROUTE_APPROVAL,
            'approval_reasons' => $assessment->approvalReasons,
            'expires_at' => now()->addHours($settings->approval_expiry_hours),
        ]);
        $this->notifier->changed($execution, 'pending_approval');

        if (in_array('sender_email_mismatch', $assessment->approvalReasons, true)) {
            $this->notifier->handOver($execution, 'sender_email_mismatch', "The agent proposed {$definition->label()} on {$orderNumber}, but the sender's email does not match the order email. It is waiting for a human to approve or reject it.");
        }

        return $execution;
    }

    /**
     * hash(conversation, action, canonical params, order). System-generated
     * values (a discount code) are left out so a re-proposal matches.
     *
     * @param  array<string, mixed>  $params
     */
    public function idempotencyKey(int $conversationId, string $action, array $params, ?string $order): string
    {
        if ($action === 'discount_code') {
            $params = array_diff_key($params, array_flip(DiscountCode::idempotencyExclude()));
        }

        return hash('sha256', implode('|', [$conversationId, $action, json_encode(self::canonical($params)), (string) $order]));
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($v) => is_array($v) ? self::canonical($v) : $v, $value);
    }

    /** @return list<array<string, mixed>> */
    private function citations(AgentRun $run, string $text): array
    {
        preg_match_all('/\b([EK]\d+)\b/', $text, $m);
        $ids = array_values(array_unique($m[1]));
        $facts = [];
        foreach ((array) ($run->evidence['facts'] ?? []) as $fact) {
            $facts[(string) ($fact['id'] ?? '')] = $fact;
        }
        foreach ((array) ($run->evidence['knowledge'] ?? []) as $item) {
            $facts[(string) ($item['id'] ?? '')] = ['id' => $item['id'] ?? null, 'source' => 'knowledge', 'label' => $item['title'] ?? $item['kind'] ?? 'knowledge', 'value' => mb_substr((string) ($item['body'] ?? ''), 0, 500)];
        }

        return array_values(array_map(fn ($id) => $facts[$id] ?? ['id' => $id, 'source' => 'unknown'], $ids));
    }
}
