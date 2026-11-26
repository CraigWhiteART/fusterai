<?php

namespace App\Domains\Commerce\Actions\Definitions;

use App\Domains\Commerce\Actions\Gateway\ShopifyGateway;
use App\Domains\Commerce\Actions\Models\ActionSettings;
use App\Domains\Commerce\Actions\OrderState;
use App\Domains\Commerce\Actions\OrderStateLoader;
use App\Domains\Commerce\Actions\Support\Money;

/**
 * refundCreate, full or partial: by line items (Shopify computes the amount
 * via Order.suggestedRefund) or by a plain amount, plus an explicit shipping
 * choice. Never more than Shopify's maximumRefundableSet, the parent
 * payment's maximumRefundableV2 and the order's net payment. One payment
 * (parent transaction) only; split payments are refunded by hand.
 * Sent with @idempotent (required from API 2026-04). Scope: write_orders.
 */
class Refund extends ActionDefinition
{
    public const SUGGESTED = <<<'GQL'
    query ActionSuggestedRefund($id: ID!, $refundLineItems: [RefundLineItemInput!], $refundShipping: Boolean, $shippingAmount: Money) {
      order(id: $id) {
        id
        suggestedRefund(refundLineItems: $refundLineItems, refundShipping: $refundShipping, shippingAmount: $shippingAmount) {
          amountSet { shopMoney { amount currencyCode } }
          maximumRefundableSet { shopMoney { amount currencyCode } }
          shipping { amountSet { shopMoney { amount } } maximumRefundableSet { shopMoney { amount } } }
        }
      }
    }
    GQL;

    /** The key is substituted per execution (a hex UUID: safe to inline). */
    public const MUTATION = <<<'GQL'
    mutation ActionRefund($input: RefundInput!) {
      refundCreate(input: $input) @idempotent(key: "__KEY__") {
        refund { id note totalRefundedSet { shopMoney { amount currencyCode } } }
        userErrors { field message }
      }
    }
    GQL;

    public function key(): string
    {
        return 'refund';
    }

    public function label(): string
    {
        return 'Refund (full or partial)';
    }

    public function summary(): string
    {
        return 'Refunds line items and/or an amount to the original payment method, with shipping refunded only when asked. Never more than Shopify says is refundable.';
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

    public function paramsHint(): string
    {
        return '{"line_items": [{"line_item_id": "gid://shopify/LineItem/1", "quantity": 1, "restock": false}], "amount": "20.00", "shipping": "none|full|amount", "shipping_amount": "5.00", "notify_customer": true, "reason": "why"} (line_items OR amount)';
    }

    public function rules(ActionSettings $settings): array
    {
        return [
            'line_items' => ['nullable', 'array', 'max:20'],
            'line_items.*.line_item_id' => ['required', 'string', 'starts_with:gid://shopify/LineItem/'],
            'line_items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'line_items.*.restock' => ['nullable', 'boolean'],
            'amount' => ['nullable', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
            'shipping' => ['required', 'in:none,full,amount'],
            'shipping_amount' => ['nullable', 'required_if:shipping,amount', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
            'notify_customer' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ];
    }

    protected function normalize(array $params, ActionSettings $settings): array
    {
        $items = [];
        foreach ((array) ($params['line_items'] ?? []) as $item) {
            $items[] = ['line_item_id' => (string) $item['line_item_id'], 'quantity' => (int) $item['quantity'], 'restock' => (bool) ($item['restock'] ?? false)];
        }
        usort($items, fn ($a, $b) => strcmp($a['line_item_id'], $b['line_item_id']));
        $amount = isset($params['amount']) ? Money::cents($params['amount']) : null;
        $shipping = (string) $params['shipping'];

        return [
            'amount' => $amount !== null && $amount > 0 ? Money::decimal($amount) : null,
            'line_items' => $items,
            'notify_customer' => (bool) $params['notify_customer'],
            'reason' => trim((string) $params['reason']),
            'shipping' => $shipping,
            'shipping_amount' => $shipping === 'amount' ? Money::decimal((int) Money::cents($params['shipping_amount'] ?? '0')) : null,
        ];
    }

    protected function extraErrors(array $params, ActionSettings $settings): array
    {
        if ($params['line_items'] !== [] && $params['amount'] !== null) {
            return ['amount' => ['Give line items or an amount, not both.']];
        }
        if ($params['line_items'] === [] && $params['amount'] === null && $params['shipping'] === 'none') {
            return ['amount' => ['Name line items, an amount, or shipping to refund.']];
        }

        return [];
    }

    public function amountFromParams(array $params): ?int
    {
        if (($params['line_items'] ?? []) !== []) {
            return null; // Shopify computes it; known after prepare().
        }

        return (int) (Money::cents($params['amount'] ?? null) ?? 0) + (int) (Money::cents($params['shipping_amount'] ?? null) ?? 0);
    }

    public function describe(array $params, ?OrderState $state, ActionSettings $settings): string
    {
        $order = $state?->name() ?: 'the order';
        $currency = $state?->currency();
        $what = [];
        foreach ((array) ($params['line_items'] ?? []) as $item) {
            $line = $state?->lineItem((string) $item['line_item_id']);
            $what[] = $item['quantity'].' x '.($line['title'] ?? $item['line_item_id']).($item['restock'] ? ' (restocked)' : '');
        }
        if (($params['amount'] ?? null) !== null) {
            $what[] = Money::format((int) Money::cents($params['amount']), $currency);
        }
        $shipping = match ($params['shipping'] ?? 'none') {
            'full' => 'the full shipping charge',
            'amount' => Money::format((int) Money::cents($params['shipping_amount'] ?? '0'), $currency).' of shipping',
            default => null,
        };
        if ($shipping !== null) {
            $what[] = $shipping;
        }

        return "Refund {$order}: ".implode(', ', $what).'. The money goes back to the original payment method (usually 5-10 business days to appear). '
            .(($params['notify_customer'] ?? false) ? 'Shopify will email the customer a refund receipt.' : 'Shopify will not email the customer.')
            .' A refund cannot be undone.';
    }

    public function prepare(ShopifyGateway $gateway, array $params, OrderState $state, ActionContext $ctx): void
    {
        $vars = ['id' => $state->id()];
        if (($params['line_items'] ?? []) !== []) {
            $vars['refundLineItems'] = array_map(fn ($i) => ['lineItemId' => $i['line_item_id'], 'quantity' => $i['quantity']], $params['line_items']);
        }
        if (($params['shipping'] ?? 'none') === 'full') {
            $vars['refundShipping'] = true;
        } elseif (($params['shipping'] ?? 'none') === 'amount') {
            $vars['shippingAmount'] = $params['shipping_amount'];
        }

        $result = $gateway->query(self::SUGGESTED, $vars);
        $ctx->extra['suggested'] = $result->ok() ? ($result->data['order']['suggestedRefund'] ?? null) : null;
        $ctx->extra['suggested_error'] = $result->ok() ? null : $result->errorText();
    }

    public function preconditions(array $params, OrderState $state, ActionContext $ctx): Checks
    {
        $checks = new Checks;
        $checks->currency = $state->currency();
        $checks->check('refundable', (bool) ($state->order['refundable'] ?? false), ($state->order['refundable'] ?? false) ? 'Shopify says the order can be refunded.' : 'Shopify says the order cannot be refunded.');

        $presentment = (string) ($state->order['presentmentCurrencyCode'] ?? $state->currency());
        $checks->check('single_currency', $presentment === $state->currency(), $presentment === $state->currency()
            ? 'The order was paid in the shop currency.'
            : "The customer paid in {$presentment}; refund it in Shopify by hand.");

        foreach ((array) ($params['line_items'] ?? []) as $item) {
            $line = $state->lineItem((string) $item['line_item_id']);
            $refundable = (int) ($line['refundableQuantity'] ?? 0);
            $checks->check('line_item_refundable', $line !== null && $item['quantity'] <= $refundable, $line === null
                ? "Line item {$item['line_item_id']} is not on this order."
                : "{$item['quantity']} x {$line['title']} requested, {$refundable} refundable.");
            if ($item['restock'] && $line !== null) {
                $unfulfilled = (int) ($line['unfulfilledQuantity'] ?? 0);
                $checks->check('restock_unfulfilled', $item['quantity'] <= $unfulfilled, $item['quantity'] <= $unfulfilled
                    ? 'Restocked items are unfulfilled (restock type CANCEL).'
                    : "Only {$unfulfilled} of {$line['title']} are unfulfilled; restock shipped items in Shopify by hand.");
            }
        }

        $suggested = $ctx->extra['suggested'] ?? null;
        if (! is_array($suggested)) {
            $checks->check('suggested_refund', false, 'Could not get the refundable amount from Shopify: '.($ctx->extra['suggested_error'] ?? 'no data'));

            return $checks;
        }

        $max = (int) (Money::cents($suggested['maximumRefundableSet']['shopMoney']['amount'] ?? null) ?? 0);
        $shippingCents = match ($params['shipping'] ?? 'none') {
            'full' => (int) (Money::cents($suggested['shipping']['amountSet']['shopMoney']['amount'] ?? null) ?? 0),
            'amount' => (int) (Money::cents($params['shipping_amount'] ?? null) ?? 0),
            default => 0,
        };
        if (($params['shipping'] ?? 'none') === 'amount') {
            $shippingMax = (int) (Money::cents($suggested['shipping']['maximumRefundableSet']['shopMoney']['amount'] ?? null) ?? 0);
            $checks->check('shipping_refundable', $shippingCents <= $shippingMax, 'Shipping refund '.Money::decimal($shippingCents).' of '.Money::decimal($shippingMax).' refundable.');
        }

        $total = ($params['line_items'] ?? []) !== []
            ? (int) (Money::cents($suggested['amountSet']['shopMoney']['amount'] ?? null) ?? 0)
            : (int) (Money::cents($params['amount'] ?? null) ?? 0) + $shippingCents;

        $parent = $this->parentTransaction($state, $total);
        $net = $state->netPaymentCents();
        $ceiling = min($max, $net, $parent !== null ? (int) Money::cents($parent['maximumRefundableV2']['amount'] ?? '0') : 0);

        $checks->amountCents = $total;
        $checks->computed = ['total_cents' => $total, 'shipping_cents' => $shippingCents, 'max_cents' => $max, 'net_cents' => $net, 'parent' => $parent];

        $checks->check('positive', $total > 0, $total > 0 ? 'Refund amount '.Money::format($total, $state->currency()).'.' : 'The refund amount is zero.');
        $checks->check('within_refundable', $total <= $ceiling, $total <= $ceiling
            ? 'Within the refundable amount ('.Money::format($ceiling, $state->currency()).').'
            : 'Asks for '.Money::format($total, $state->currency()).' but only '.Money::format(max(0, $ceiling), $state->currency()).' can be refunded.');
        $checks->check('single_payment', $parent !== null, $parent !== null
            ? 'Refunds payment '.($parent['gateway'] ?? '').'.'
            : 'No single payment can cover this refund (split or unpaid order); refund it in Shopify by hand.');

        return $checks;
    }

    public function execute(ShopifyGateway $gateway, array $params, OrderState $state, Checks $checks, ActionContext $ctx): Outcome
    {
        $total = (int) $checks->computed['total_cents'];
        $parent = (array) $checks->computed['parent'];

        $input = [
            'orderId' => $state->id(),
            'note' => mb_substr($ctx->auditLine('Refund: '.$params['reason']), 0, 500),
            'notify' => (bool) $params['notify_customer'],
            'transactions' => [[
                'orderId' => $state->id(),
                'parentId' => $parent['id'],
                'kind' => 'REFUND',
                'gateway' => $parent['gateway'],
                'amount' => Money::decimal($total),
            ]],
        ];
        if ($params['line_items'] !== []) {
            $input['refundLineItems'] = array_map(fn ($i) => [
                'lineItemId' => $i['line_item_id'],
                'quantity' => $i['quantity'],
                'restockType' => $i['restock'] ? 'CANCEL' : 'NO_RESTOCK',
            ], $params['line_items']);
        }
        if ($params['shipping'] === 'full') {
            $input['shipping'] = ['fullRefund' => true];
        } elseif ($params['shipping'] === 'amount') {
            $input['shipping'] = ['amount' => $params['shipping_amount']];
        }

        $document = str_replace('__KEY__', $ctx->execution->shopifyIdempotencyKey(), self::MUTATION);

        return Outcome::fromResult($gateway->mutate($document, ['input' => $input]), 'refundCreate', data: ['amount' => Money::decimal($total)]);
    }

    public function verify(ShopifyGateway $gateway, array $params, ActionContext $ctx, OrderStateLoader $loader): Outcome
    {
        $state = $loader->load($gateway, $ctx->scopes, $ctx->execution->order_id, $ctx->execution->order_number);
        if ($state === null) {
            return new Outcome(Outcome::UNKNOWN, $loader->last['error']);
        }

        foreach ($state->refunds() as $refund) {
            if ($ctx->execution->hasMarker((string) ($refund['note'] ?? ''))) {
                return new Outcome(Outcome::SUCCEEDED, data: ['refund_id' => $refund['id'] ?? null, 'refunded' => $refund['totalRefundedSet']['shopMoney']['amount'] ?? null]);
            }
        }

        return new Outcome(Outcome::FAILED, 'No refund carrying this execution\'s marker is on the order.');
    }

    public function undo(array $params, ?OrderState $state, Outcome $outcome): ?array
    {
        return ['how' => 'A refund cannot be reversed in Shopify.', 'reversible' => false];
    }

    /**
     * The one successful SALE/CAPTURE that can cover the whole refund.
     *
     * @return array<string, mixed>|null
     */
    private function parentTransaction(OrderState $state, int $totalCents): ?array
    {
        foreach ((array) ($state->order['transactions'] ?? []) as $tx) {
            if (! is_array($tx) || ! in_array($tx['kind'] ?? '', ['SALE', 'CAPTURE'], true) || ($tx['status'] ?? '') !== 'SUCCESS') {
                continue;
            }
            $max = Money::cents($tx['maximumRefundableV2']['amount'] ?? null) ?? 0;
            if ($max >= $totalCents && $max > 0) {
                return $tx;
            }
        }

        return null;
    }
}
