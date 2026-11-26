<?php

namespace App\Domains\Commerce\Actions;

use App\Domains\Agent\AutoSend\AutoSendGuard;
use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Commerce\Actions\Definitions\ActionContext;
use App\Domains\Commerce\Actions\Definitions\AddOrderNote;
use App\Domains\Commerce\Actions\Definitions\Outcome;
use App\Domains\Commerce\Actions\Gateway\ShopifyGateway;
use App\Domains\Commerce\Actions\Gateway\ShopifyGatewayFactory;
use App\Domains\Commerce\Actions\Jobs\ReconcileActionJob;
use App\Domains\Commerce\Actions\Models\ActionExecution as E;
use App\Domains\Commerce\Actions\Models\ActionSettings;
use App\Domains\Commerce\Actions\Support\Money;
use App\Domains\Commerce\Actions\Support\Scopes;
use App\Domains\Commerce\Models\CommerceSetting;
use Throwable;

/**
 * The executor state machine (docs/agent-actions.md "Executor"):
 *
 *   approved --claim--> executing --> succeeded
 *                                 \--> failed     (userErrors / rejected request; hand over)
 *                                 \--> unknown    (timeout / 5xx / connection; reconcile, never re-send)
 *                                 \--> pending_approval (unattended, but a limit or precondition no longer holds)
 *                                 \--> failed     (human-approved, but the order changed: stop)
 *   approved --> cancelled (kill switch, policy now never, scope gone, non-live dry run)
 *   unknown --reconcile--> succeeded | failed | (still unknown -> hand over after N attempts)
 *
 * The claim is a conditional UPDATE (approved -> executing) made before any
 * Shopify request, so a double dispatch or double click sends one mutation.
 */
class ActionExecutor
{
    public function __construct(
        private readonly ActionRegistry $registry,
        private readonly ActionAssessor $assessor,
        private readonly ShopifyGatewayFactory $gateways,
        private readonly OrderStateLoader $loader,
        private readonly ActionNotifier $notifier,
    ) {}

    public function execute(int $executionId): void
    {
        $e = E::find($executionId);
        if ($e === null || $e->status !== E::APPROVED) {
            return;
        }

        if (AgentSetting::forWorkspace($e->workspace_id)->kill_switch) {
            if ($e->transition([E::APPROVED], E::CANCELLED, ['error' => 'Cancelled: the agent kill switch is on.', 'completed_at' => now()])) {
                $this->notifier->changed($e, 'cancelled');
            }

            return;
        }

        // Structural dry run: a non-live execution gets a gateway with no HTTP client.
        $gateway = $this->gateways->forExecution($e);
        if (! $gateway->isLive()) {
            $nonLive = $e->dry_run || $e->purpose !== AgentRun::PURPOSE_LIVE || ($e->run_id !== null && AgentRun::whereKey($e->run_id)->value('purpose') !== AgentRun::PURPOSE_LIVE);
            if ($nonLive) {
                if ($e->transition([E::APPROVED], E::CANCELLED, ['dry_run' => true, 'error' => "Dry run: a {$e->purpose} run never executes actions.", 'completed_at' => now()])) {
                    $this->notifier->changed($e, 'dry_run');
                }
            } elseif ($e->transition([E::APPROVED], E::FAILED, ['error' => 'Shopify is not connected.', 'completed_at' => now()])) {
                $this->failed($e, 'not_connected', 'Shopify is not connected, so this action could not run.');
            }

            return;
        }

        if (! $e->transition([E::APPROVED], E::EXECUTING, ['executed_at' => now()])) {
            return; // someone else claimed it
        }

        try {
            $this->run($e, $gateway);
        } catch (Throwable $ex) {
            report($ex);
            // We cannot tell whether the mutation went out: never guess, reconcile.
            if ($e->transition([E::EXECUTING], E::UNKNOWN, ['error' => 'Unexpected error: '.mb_substr($ex->getMessage(), 0, 500)])) {
                $this->scheduleReconcile($e);
                $this->notifier->changed($e, 'unknown');
            }
        }
    }

    private function run(E $e, ShopifyGateway $gateway): void
    {
        $definition = $this->registry->get($e->action_key);
        $assessment = $this->assessor->assess($e, $gateway, $e->approver?->name);

        $e->forceFill([
            'policy_snapshot' => $assessment->policy->toArray(),
            'precondition_snapshot' => $assessment->preconditionSnapshot(),
            'description' => $assessment->description,
            'warnings' => $assessment->warnings,
            'order_id' => $assessment->state?->id() ?: $e->order_id,
            'customer_email' => $e->customer_email ?: $assessment->state?->email(),
            'amount' => $assessment->checks?->amountCents !== null ? Money::decimal($assessment->checks->amountCents) : $e->amount,
            'currency' => $assessment->checks->currency ?? $e->currency,
        ])->save();

        // Last look at the kill switch / pause / mode: the assessment above made
        // Shopify reads, so time has passed since the check at the top, and
        // nothing below does any more I/O before the mutation.
        if ($this->stoppedByAutonomy($e)) {
            return;
        }

        if ($assessment->blocked()) {
            $why = $assessment->unavailable ?? 'The action policy is now "never".';
            if ($e->transition([E::EXECUTING], E::CANCELLED, ['error' => 'Cancelled: '.$why, 'completed_at' => now()])) {
                $this->notifier->changed($e, 'cancelled');
                $this->notifier->requestRewrite($e);
            }

            return;
        }

        if (! $assessment->executable()) {
            $problems = $assessment->checks?->failures() ?? [];
            $why = $assessment->stateError ?? implode(' ', $problems);
            if ($e->route === E::ROUTE_UNATTENDED) {
                $this->toApproval($e, [...$assessment->approvalReasons, 'preconditions_failed']);

                return;
            }
            if ($e->transition([E::EXECUTING], E::FAILED, ['error' => 'Stopped before sending: the order changed since approval. '.$why, 'completed_at' => now()])) {
                $this->failed($e, 'preconditions_changed', "Stopped before sending {$definition->label()}: the order changed since it was approved. {$why}");
            }

            return;
        }

        if ($e->route === E::ROUTE_UNATTENDED && ! $assessment->unattended()) {
            $this->toApproval($e, $assessment->approvalReasons);

            return;
        }

        $state = $assessment->state;
        $checks = $assessment->checks;
        if ($state === null || $checks === null) {
            return; // unreachable: executable() checked both
        }

        $outcome = $definition->execute($gateway, $e->params, $state, $checks, $assessment->context);
        $e->forceFill([
            'request' => ['requests' => $outcome->requests],
            'response' => ['responses' => $outcome->responses],
        ])->save();

        match ($outcome->status) {
            Outcome::SUCCEEDED => $this->afterSuccess($e, $gateway, $outcome, $assessment->context, $state),
            Outcome::FAILED => $this->afterFailure($e, $outcome),
            default => $this->afterUnknown($e, (string) $outcome->error),
        };
    }

    private function afterSuccess(E $e, ShopifyGateway $gateway, Outcome $outcome, ActionContext $ctx, OrderState $state): void
    {
        $definition = $this->registry->get($e->action_key);
        $verify = $definition->verify($gateway, $e->params, $ctx, $this->loader);

        if ($verify->status !== Outcome::SUCCEEDED) {
            // Shopify accepted it but the effect is not visible yet: decide later, by reading.
            $this->afterUnknown($e, 'Accepted by Shopify; confirming. '.($verify->error ?? ''), ['verify' => $verify->status, 'detail' => $verify->error]);

            return;
        }

        $this->succeed($e, $gateway, $ctx, ['verify' => $verify->data, 'execute' => array_diff_key($outcome->data, ['payload' => true])], $definition->undo($e->params, $state, $outcome));
    }

    /**
     * @param  array<string, mixed>  $verify
     * @param  array<string, mixed>|null  $undo
     */
    private function succeed(E $e, ShopifyGateway $gateway, ActionContext $ctx, array $verify, ?array $undo): void
    {
        if (! $e->transition([E::EXECUTING, E::UNKNOWN], E::SUCCEEDED, ['verify_result' => $verify, 'undo' => $undo, 'error' => null, 'completed_at' => now()])) {
            return;
        }

        $verify['audit_note'] = $this->auditNote($e, $gateway, $ctx);
        $e->forceFill(['verify_result' => $verify])->save();
        $this->notifier->changed($e, 'succeeded', $e->approver);
    }

    private function afterFailure(E $e, Outcome $outcome): void
    {
        if ($e->transition([E::EXECUTING], E::FAILED, ['error' => mb_substr((string) $outcome->error, 0, 2000), 'completed_at' => now()])) {
            $label = $this->registry->get($e->action_key)->label();
            $this->failed($e, 'user_errors', "{$label} failed in Shopify: {$outcome->error}");
        }
    }

    /** @param  array<string, mixed>|null  $verify */
    private function afterUnknown(E $e, string $error, ?array $verify = null): void
    {
        if ($e->transition([E::EXECUTING], E::UNKNOWN, ['error' => mb_substr($error, 0, 2000), 'verify_result' => $verify])) {
            $this->scheduleReconcile($e);
            $this->notifier->changed($e, 'unknown');
        }
    }

    public function reconcile(int $executionId): void
    {
        $e = E::find($executionId);
        if ($e === null || $e->status !== E::UNKNOWN) {
            return;
        }

        $attempt = $e->reconcile_attempts + 1;
        if (E::whereKey($e->id)->where('status', E::UNKNOWN)->where('reconcile_attempts', $e->reconcile_attempts)->update(['reconcile_attempts' => $attempt]) !== 1) {
            return;
        }
        $e->refresh();

        $gateway = $this->gateways->forExecution($e);
        if (! $gateway->isLive()) {
            return;
        }

        $definition = $this->registry->get($e->action_key);
        $ctx = new ActionContext($e, ActionSettings::forWorkspace($e->workspace_id), Scopes::for(CommerceSetting::forWorkspace($e->workspace_id)), $e->approver?->name);
        $verify = $definition->verify($gateway, $e->params, $ctx, $this->loader);
        $max = (int) config('agent_actions.reconcile_max_attempts', 5);

        if ($verify->status === Outcome::SUCCEEDED) {
            $this->succeed($e, $gateway, $ctx, ['verify' => $verify->data, 'reconciled' => true, 'attempts' => $attempt], $definition->undo($e->params, null, $verify));

            return;
        }

        // "Not there" is only believed on a second look, so a slow read never
        // makes a done refund look undone (and invite a manual second refund).
        if ($verify->status === Outcome::FAILED && $attempt >= 2) {
            if ($e->transition([E::UNKNOWN], E::FAILED, ['error' => 'Reconciled: '.$verify->error, 'completed_at' => now()])) {
                $this->failed($e, 'reconciled_failed', "{$definition->label()} did not take effect in Shopify (checked {$attempt} times): {$verify->error}");
            }

            return;
        }

        if ($attempt >= $max) {
            $e->forceFill(['error' => 'Outcome still unknown after '.$attempt.' checks: '.$verify->error])->save();
            $this->notifier->handOver($e, 'unknown_outcome', "Could not confirm whether {$definition->label()} on {$e->order_number} happened. Check the order in Shopify before doing anything by hand.");
            $this->notifier->changed($e, 'unknown');

            return;
        }

        $this->scheduleReconcile($e);
    }

    private function scheduleReconcile(E $e): void
    {
        ReconcileActionJob::dispatch($e->id)->delay(now()->addSeconds((int) config('agent_actions.reconcile_delay_seconds', 60)));
    }

    /**
     * Unattended executions stop for anything that stops autonomous work
     * (AutoSendGuard::autonomyBlockedReason: kill switch, workspace mode not
     * live, auto-sending paused). A human-approved execution stops only for
     * the kill switch: a person decided, so pause and mode do not apply.
     * Kill switch = cancelled; mode / pause = back to the approval queue.
     */
    private function stoppedByAutonomy(E $e): bool
    {
        $reason = $e->route === E::ROUTE_UNATTENDED
            ? AutoSendGuard::autonomyBlockedReason((int) $e->workspace_id)
            : (AgentSetting::forWorkspace($e->workspace_id)->kill_switch ? 'kill_switch' : null);

        if ($reason === null) {
            return false;
        }
        if ($reason === 'kill_switch') {
            if ($e->transition([E::EXECUTING], E::CANCELLED, ['error' => 'Cancelled: the agent kill switch is on.', 'executed_at' => null, 'completed_at' => now()])) {
                $this->notifier->changed($e, 'cancelled');
            }

            return true;
        }

        $this->toApproval($e, [$reason === 'paused' ? 'autonomy_paused' : 'agent_mode_not_live']);

        return true;
    }

    /** @param  list<string>  $reasons */
    private function toApproval(E $e, array $reasons): void
    {
        $hours = ActionSettings::forWorkspace($e->workspace_id)->approval_expiry_hours;
        if ($e->transition([E::EXECUTING], E::PENDING_APPROVAL, [
            'route' => E::ROUTE_APPROVAL,
            'approval_reasons' => array_values(array_unique($reasons)),
            'executed_at' => null,
            'expires_at' => now()->addHours($hours),
        ])) {
            $this->notifier->changed($e, 'pending_approval');
        }
    }

    private function failed(E $e, string $reason, string $text): void
    {
        $this->notifier->changed($e, 'failed', $e->approver);
        $this->notifier->handOver($e, $reason, $text);
        $this->notifier->requestRewrite($e);
    }

    /** Audit line on the order naming the helpdesk and approver. Best effort. */
    private function auditNote(E $e, ShopifyGateway $gateway, ActionContext $ctx): string
    {
        if (! $ctx->settings->audit_note || $e->action_key === 'add_order_note') {
            return 'skipped';
        }

        $state = $this->loader->load($gateway, $ctx->scopes, $e->order_id, $e->order_number);
        if ($state === null) {
            return 'failed: '.($this->loader->last['error'] ?? 'order not readable');
        }
        $what = $this->registry->get($e->action_key)->label().($e->amount !== null ? ' '.trim(($e->currency ?? '').' '.$e->amount) : '');
        $note = trim($state->note()) === '' ? $ctx->auditLine($what) : rtrim($state->note())."\n".$ctx->auditLine($what);
        $result = Outcome::fromResult($gateway->mutate(AddOrderNote::MUTATION, ['input' => ['id' => $state->id(), 'note' => $note]]), 'orderUpdate');

        return $result->status === Outcome::SUCCEEDED ? 'added' : 'failed: '.$result->error;
    }
}
