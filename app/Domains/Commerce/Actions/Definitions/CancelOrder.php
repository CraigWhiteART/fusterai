<?php

namespace App\Domains\Commerce\Actions\Definitions;

use App\Domains\Commerce\Actions\Gateway\ShopifyGateway;
use App\Domains\Commerce\Actions\Models\ActionSettings;
use App\Domains\Commerce\Actions\OrderState;
use App\Domains\Commerce\Actions\OrderStateLoader;
use App\Domains\Commerce\Actions\Support\Money;

/**
 * orderCancel (irreversible; runs as a Shopify job, so verify may see it
 * pending). Restock, refund and customer notification are explicit params;
 * refund uses refundMethod.originalPaymentMethodsRefund. Unfulfilled orders
 * only. Scope: write_orders.
 */
class CancelOrder extends ActionDefinition
{
    public const MUTATION = <<<'GQL'
    mutation ActionCancelOrder($orderId: ID!, $reason: OrderCancelReason!, $restock: Boolean!, $notifyCustomer: Boolean, $refundMethod: OrderCancelRefundMethodInput, $staffNote: String) {
      orderCancel(orderId: $orderId, reason: $reason, restock: $restock, notifyCustomer: $notifyCustomer, refundMethod: $refundMethod, staffNote: $staffNote) {
        job { id done }
        orderCancelUserErrors { field message code }
      }
    }
    GQL;

    public const REASONS = ['CUSTOMER', 'INVENTORY', 'OTHER'];

    public function key(): string
    {
        return 'cancel_order';
    }

    public function label(): string
    {
        return 'Cancel the order';
    }

    public function summary(): string
    {
        return 'Cancels an unfulfilled order, with explicit restock, refund and customer-notification choices. Cannot be undone.';
    }

    public function requiredScopes(): array
    {
        return [['write_orders']];
    }

    public function isMoney(): bool
    {
        return true;
    }

    public function reversible(): bool
    {
        return false;
    }

    public function riskTier(): string
    {
        return self::RISK_HIGH;
    }

    public function replyWaits(): bool
    {
        return true;
    }

    public function warehouseSensitive(): bool
    {
        return true;
    }

    public function paramsHint(): string
    {
        return '{"reason": "CUSTOMER|INVENTORY|OTHER", "restock": true, "refund": true, "notify_customer": false}';
    }

    public function rules(ActionSettings $settings): array
    {
        return [
            'reason' => ['required', 'string', 'in:'.implode(',', [...self::REASONS, ...array_map('strtolower', self::REASONS)])],
            'restock' => ['required', 'boolean'],
            'refund' => ['required', 'boolean'],
            'notify_customer' => ['required', 'boolean'],
        ];
    }

    protected function normalize(array $params, ActionSettings $settings): array
    {
        return [
            'notify_customer' => (bool) $params['notify_customer'],
            'reason' => strtoupper((string) $params['reason']),
            'refund' => (bool) $params['refund'],
            'restock' => (bool) $params['restock'],
        ];
    }

    public function describe(array $params, ?OrderState $state, ActionSettings $settings): string
    {
        $order = $state?->name() ?: 'the order';
        $money = $state !== null ? Money::format($state->netPaymentCents(), $state->currency()) : 'the amount paid';
        $parts = ["Cancel {$order} (reason: ".strtolower((string) ($params['reason'] ?? 'other')).').'];
        $parts[] = ($params['refund'] ?? false) ? "Refund {$money} to the original payment method." : 'Do NOT refund the payment (the customer keeps being charged unless refunded separately).';
        $parts[] = ($params['restock'] ?? false) ? 'Return the items to stock.' : 'Do not restock the items.';
        $parts[] = ($params['notify_customer'] ?? false) ? 'Shopify will email the customer a cancellation notice.' : 'Shopify will not email the customer.';
        $parts[] = 'This cannot be undone.';

        return implode(' ', $parts);
    }

    public function amountFromParams(array $params): ?int
    {
        return null;
    }

    public function preconditions(array $params, OrderState $state, ActionContext $ctx): Checks
    {
        $checks = new Checks;
        $this->orderPresent($checks, $state);
        $checks->check('unfulfilled', $state->isUnfulfilled(), $state->isUnfulfilled()
            ? 'The order has not been fulfilled.'
            : "The order is {$state->fulfilmentStatus()}: only unfulfilled orders are cancelled here.");

        $checks->currency = $state->currency();
        $checks->amountCents = ($params['refund'] ?? false) ? $state->netPaymentCents() : 0;
        if (($params['refund'] ?? false) && $state->netPaymentCents() <= 0) {
            $checks->warn('Shopify reports nothing left to refund on this order.', 'info');
        }

        return $checks;
    }

    public function execute(ShopifyGateway $gateway, array $params, OrderState $state, Checks $checks, ActionContext $ctx): Outcome
    {
        $variables = [
            'orderId' => $state->id(),
            'reason' => $params['reason'],
            'restock' => (bool) $params['restock'],
            'notifyCustomer' => (bool) $params['notify_customer'],
            'staffNote' => mb_substr($ctx->auditLine('Cancelled'), 0, 255),
        ];
        if ($params['refund']) {
            $variables['refundMethod'] = ['originalPaymentMethodsRefund' => true];
        }

        return Outcome::fromResult($gateway->mutate(self::MUTATION, $variables), 'orderCancel', 'orderCancelUserErrors');
    }

    public function verify(ShopifyGateway $gateway, array $params, ActionContext $ctx, OrderStateLoader $loader): Outcome
    {
        $state = $loader->load($gateway, $ctx->scopes, $ctx->execution->order_id, $ctx->execution->order_number);
        if ($state === null) {
            return new Outcome(Outcome::UNKNOWN, $loader->last['error']);
        }

        return $state->cancelled()
            ? new Outcome(Outcome::SUCCEEDED, data: ['cancelled_at' => $state->order['cancelledAt'] ?? null, 'financial_status' => $state->financialStatus()])
            : new Outcome(Outcome::PENDING, 'Shopify has not finished cancelling the order yet.');
    }

    public function undo(array $params, ?OrderState $state, Outcome $outcome): ?array
    {
        return ['how' => 'A cancelled order cannot be restored. Create a new order (draft order) if it was a mistake.', 'reversible' => false];
    }
}
