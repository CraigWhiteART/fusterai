<?php

namespace App\Domains\Commerce\Actions\Policy;

use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Commerce\Actions\Definitions\ActionDefinition;
use App\Domains\Commerce\Actions\Definitions\Checks;
use App\Domains\Commerce\Actions\Models\ActionExecution;
use App\Domains\Commerce\Actions\Models\ActionPolicy;
use App\Domains\Commerce\Actions\Models\ActionSettings;
use App\Domains\Commerce\Actions\OrderState;
use App\Domains\Commerce\Actions\Support\Money;

/**
 * Decides whether an action may run unattended. Returns the reasons it may
 * NOT (empty = unattended). Every reason is shown to the approver; nothing
 * is dropped silently. Pure apart from counting earlier executions.
 *
 * Unattended requires ALL of: policy `always`; agent mode live; kill switch
 * off; the run drafted (did not hand over); sender email matches the order
 * email; fresh preconditions pass; every limit holds; the workspace daily
 * action cap (agent_settings.caps.actions_per_day) has room; and, for address
 * change / cancel, the 4PX warehouse guard.
 */
class LimitEvaluator
{
    /**
     * @param  list<string>  $senderEmails
     * @return array{reasons: list<string>, warnings: list<array{level: string, text: string}>, details: array<string, mixed>}
     */
    public function evaluate(
        ActionDefinition $definition,
        ResolvedPolicy $policy,
        ActionExecution $execution,
        ?OrderState $state,
        ?Checks $checks,
        ActionSettings $settings,
        array $senderEmails,
        ?string $runDecision,
    ): array {
        $reasons = [];
        $warnings = [];
        $details = [];
        $agent = AgentSetting::forWorkspace($execution->workspace_id);

        if ($policy->mode !== ActionPolicy::ALWAYS) {
            $reasons[] = $policy->mode === ActionPolicy::NEVER ? 'policy_never' : 'policy_requires_approval';
        }
        if ($agent->mode !== AgentSetting::MODE_LIVE) {
            $reasons[] = 'agent_mode_not_live';
        }
        if ($agent->kill_switch) {
            $reasons[] = 'kill_switch';
        }
        if ($runDecision !== null && $runDecision === 'handover') {
            $reasons[] = 'run_handed_over';
        }
        if ($state === null) {
            $reasons[] = 'order_state_unavailable';
        }
        if ($checks !== null && ! $checks->passed()) {
            $reasons[] = 'preconditions_failed';
        }
        if ($execution->param_errors) {
            $reasons[] = 'invalid_params';
        }

        // Sender identity.
        $orderEmail = $state?->email() ?? '';
        $match = $orderEmail !== '' && in_array($orderEmail, array_map('mb_strtolower', $senderEmails), true);
        $details['sender_email_match'] = $match;
        if (! $match) {
            $warnings[] = ['level' => 'danger', 'text' => 'The sender\'s email does not match the order email ('.($orderEmail !== '' ? $orderEmail : 'unknown').'). Confirm the person asking owns this order.'];
            if ((bool) $policy->limit('require_sender_email_match') !== false) {
                $reasons[] = 'sender_email_mismatch';
            }
        }

        $amount = $checks->amountCents ?? $definition->amountFromParams($execution->params);
        $details['amount_cents'] = $amount;

        // Hard ceiling: refunds (and cancellations that refund) above it are approval-only.
        if (in_array($definition->key(), ['refund', 'cancel_order'], true)) {
            $ceiling = min((int) Money::cents((string) $settings->refund_hard_ceiling), (int) Money::cents((string) config('agent_actions.max_refund_ceiling', 5000)));
            $details['hard_ceiling_cents'] = $ceiling;
            if ($amount === null) {
                $reasons[] = 'amount_unknown';
            } elseif ($amount > $ceiling) {
                $reasons[] = 'over_hard_ceiling';
            }
        }

        if ($definition->isMoney() && $definition->percentage($execution->params) === null && in_array($definition->key(), ['refund', 'cancel_order', 'discount_code'], true)) {
            $max = $policy->limit('max_amount');
            if ($max === null) {
                $reasons[] = 'no_max_amount_limit';
            } elseif ($amount === null || $amount > (int) Money::cents($max)) {
                $reasons[] = 'over_max_amount';
            }
        } elseif (($max = $policy->limit('max_amount')) !== null && $amount !== null && $amount > (int) Money::cents($max)) {
            $reasons[] = 'over_max_amount';
        }

        $percentage = $definition->percentage($execution->params);
        if ($percentage !== null) {
            $maxPct = $policy->limit('max_percentage');
            if ($maxPct === null) {
                $reasons[] = 'no_max_percentage_limit';
            } elseif ($percentage > (float) $maxPct) {
                $reasons[] = 'over_max_percentage';
            }
        }

        // Rolling per-customer and daily aggregates (other executions only).
        $others = ActionExecution::query()
            ->where('workspace_id', $execution->workspace_id)
            ->where('action_key', $definition->key())
            ->whereIn('status', ActionExecution::COUNTED)
            ->when($execution->exists, fn ($q) => $q->whereKeyNot($execution->id));

        $customer = $execution->customer_email ?: $orderEmail;
        if ($policy->limit('per_customer_30d_amount') !== null || $policy->limit('per_customer_30d_count') !== null) {
            $window = (clone $others)->where('customer_email', $customer)->where('created_at', '>=', now()->subDays(30));
            $count = (clone $window)->count();
            $sum = (int) Money::cents((string) ((clone $window)->sum('amount') ?: '0'));
            $details['customer_30d'] = ['count' => $count, 'amount_cents' => $sum];
            if (($limit = $policy->limit('per_customer_30d_count')) !== null && $count + 1 > (int) $limit) {
                $reasons[] = 'over_customer_30d_count';
            }
            if (($limit = $policy->limit('per_customer_30d_amount')) !== null && $sum + (int) $amount > (int) Money::cents($limit)) {
                $reasons[] = 'over_customer_30d_amount';
            }
        }
        if ($policy->limit('per_day_amount') !== null || $policy->limit('per_day_count') !== null) {
            $today = (clone $others)->where('created_at', '>=', now()->startOfDay());
            $count = (clone $today)->count();
            $sum = (int) Money::cents((string) ((clone $today)->sum('amount') ?: '0'));
            $details['today'] = ['count' => $count, 'amount_cents' => $sum];
            if (($limit = $policy->limit('per_day_count')) !== null && $count + 1 > (int) $limit) {
                $reasons[] = 'over_daily_count';
            }
            if (($limit = $policy->limit('per_day_amount')) !== null && $sum + (int) $amount > (int) Money::cents($limit)) {
                $reasons[] = 'over_daily_amount';
            }
        }

        // Workspace-wide daily cap on unattended actions (agent settings).
        $cap = (int) (($agent->caps ?? [])['actions_per_day'] ?? 0);
        $usedToday = ActionExecution::query()
            ->where('workspace_id', $execution->workspace_id)
            ->where('route', ActionExecution::ROUTE_UNATTENDED)
            ->whereIn('status', ActionExecution::COUNTED)
            ->where('created_at', '>=', now()->startOfDay())
            ->when($execution->exists, fn ($q) => $q->whereKeyNot($execution->id))
            ->count();
        $details['unattended_today'] = ['used' => $usedToday, 'cap' => $cap];
        if ($usedToday + 1 > $cap) {
            $reasons[] = 'daily_action_cap';
        }

        if ($state !== null) {
            $ageDays = $state->ageMinutes() !== null ? intdiv((int) $state->ageMinutes(), 1440) : null;
            if (($limit = $policy->limit('max_order_age_days')) !== null && ($ageDays === null || $ageDays > (int) $limit)) {
                $reasons[] = 'over_order_age';
            }
            if (($allowed = $policy->limit('allowed_fulfilment_states')) !== null && ! in_array($state->fulfilmentStatus(), (array) $allowed, true)) {
                $reasons[] = 'fulfilment_state_not_allowed';
            }
            if (($countries = $policy->limit('allowed_countries')) !== null) {
                $destinations = array_filter([$state->countryCode(), $checks?->computed['destination_country'] ?? null]);
                if ($destinations === [] || array_diff($destinations, (array) $countries) !== []) {
                    $reasons[] = 'country_not_allowed';
                }
            }
            if ($checks !== null && ($checks->computed['country_changed'] ?? false)) {
                $reasons[] = 'country_changed';
            }

            if ($definition->warehouseSensitive()) {
                $guard = $this->warehouseGuard($state, $settings);
                $details['warehouse_guard'] = $guard;
                if (! $guard['clear']) {
                    $reasons[] = 'warehouse_guard';
                }
            }
        }

        return ['reasons' => array_values(array_unique($reasons)), 'warnings' => $warnings, 'details' => $details];
    }

    /**
     * The 4PX guard (open business decision, docs/agent-actions.md): clear only
     * when the order is unfulfilled, no fulfilment order is with / accepted by
     * a fulfilment service (or it could not be read), and it is younger than
     * the configured window.
     *
     * @return array{clear: bool, reasons: list<string>, age_minutes: int|null, window_minutes: int}
     */
    public function warehouseGuard(OrderState $state, ActionSettings $settings): array
    {
        $window = (int) $settings->unattended_order_age_minutes;
        $reasons = [];
        if (! $state->isUnfulfilled()) {
            $reasons[] = "the order is {$state->fulfilmentStatus()}";
        }
        $signals = $state->warehouseSignals();
        if ($signals === null) {
            $reasons[] = 'fulfilment orders could not be read (missing fulfilment-order scope), so it is unknown whether the warehouse has the order';
        } else {
            array_push($reasons, ...$signals);
        }
        $age = $state->ageMinutes();
        if ($age === null || $age > $window) {
            $reasons[] = 'the order is older than '.$window.' minutes'.($age !== null ? " ({$age} minutes)" : '');
        }

        return ['clear' => $reasons === [], 'reasons' => $reasons, 'age_minutes' => $age, 'window_minutes' => $window];
    }
}
