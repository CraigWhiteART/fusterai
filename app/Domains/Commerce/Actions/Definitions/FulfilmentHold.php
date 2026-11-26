<?php

namespace App\Domains\Commerce\Actions\Definitions;

use App\Domains\Commerce\Actions\Gateway\ShopifyGateway;
use App\Domains\Commerce\Actions\Models\ActionSettings;
use App\Domains\Commerce\Actions\OrderState;
use App\Domains\Commerce\Actions\OrderStateLoader;

/**
 * Puts the order's open fulfilment order on hold (fulfillmentOrderHold, with
 * the execution marker as the hold handle) or releases only the holds this
 * app placed (fulfillmentOrderReleaseHold with explicit holdIds). Exactly one
 * open fulfilment order; otherwise a human does it. Scope:
 * write_merchant_managed_fulfillment_orders or write_third_party_fulfillment_orders
 * (4PX locations are third-party).
 */
class FulfilmentHold extends ActionDefinition
{
    public const HOLD = <<<'GQL'
    mutation ActionFulfilmentHold($id: ID!, $fulfillmentHold: FulfillmentOrderHoldInput!) {
      fulfillmentOrderHold(id: $id, fulfillmentHold: $fulfillmentHold) {
        fulfillmentHold { id handle }
        userErrors { field message code }
      }
    }
    GQL;

    public const RELEASE = <<<'GQL'
    mutation ActionFulfilmentRelease($id: ID!, $holdIds: [ID!]) {
      fulfillmentOrderReleaseHold(id: $id, holdIds: $holdIds) {
        fulfillmentOrder { id status }
        userErrors { field message code }
      }
    }
    GQL;

    public const REASONS = ['OTHER', 'INCORRECT_ADDRESS', 'AWAITING_RETURN_ITEMS', 'HIGH_RISK_OF_FRAUD', 'INVENTORY_OUT_OF_STOCK', 'UNKNOWN_DELIVERY_DATE'];

    public function key(): string
    {
        return 'fulfilment_hold';
    }

    public function label(): string
    {
        return 'Hold or release fulfilment';
    }

    public function summary(): string
    {
        return 'Puts the order\'s fulfilment on hold, or releases a hold fusterai placed.';
    }

    public function requiredScopes(): array
    {
        return [['write_merchant_managed_fulfillment_orders', 'write_third_party_fulfillment_orders']];
    }

    public function riskTier(): string
    {
        return self::RISK_MEDIUM;
    }

    public function paramsHint(): string
    {
        return '{"operation": "hold|release", "reason": "'.implode('|', self::REASONS).'", "note": "why"}';
    }

    public function rules(ActionSettings $settings): array
    {
        return [
            'operation' => ['required', 'in:hold,release'],
            'reason' => ['nullable', 'required_if:operation,hold', 'in:'.implode(',', [...self::REASONS, ...array_map('strtolower', self::REASONS)])],
            'note' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    protected function normalize(array $params, ActionSettings $settings): array
    {
        return [
            'note' => trim((string) $params['note']),
            'operation' => (string) $params['operation'],
            'reason' => $params['operation'] === 'hold' ? strtoupper((string) $params['reason']) : null,
        ];
    }

    public function describe(array $params, ?OrderState $state, ActionSettings $settings): string
    {
        $order = $state?->name() ?: 'the order';

        return ($params['operation'] ?? '') === 'release'
            ? "Release the fulfilment hold fusterai placed on {$order}, so the warehouse can ship it. Note: {$params['note']}."
            : "Put {$order} on hold so the warehouse does not ship it yet (reason: ".strtolower((string) ($params['reason'] ?? 'other'))."). Note: {$params['note']}. A warehouse that already picked the order may not see the hold.";
    }

    public function preconditions(array $params, OrderState $state, ActionContext $ctx): Checks
    {
        $checks = new Checks;
        $this->orderPresent($checks, $state);
        $checks->check('fulfillment_orders_readable', $state->fulfillmentOrdersReadable, $state->fulfillmentOrdersReadable ? 'Fulfilment orders are readable.' : 'The app cannot read fulfilment orders (scope).');

        $open = $state->openFulfillmentOrders();
        $checks->check('one_fulfillment_order', count($open) === 1, count($open) === 1 ? 'One open fulfilment order.' : count($open).' open fulfilment orders; do this in Shopify.');
        $fo = $open[0] ?? null;
        $checks->computed['fulfillment_order_id'] = $fo['id'] ?? null;

        if (($params['operation'] ?? '') === 'release') {
            $mine = array_values(array_filter((array) ($fo['fulfillmentHolds'] ?? []), fn ($h) => ($h['heldByRequestingApp'] ?? false) === true));
            $checks->computed['hold_ids'] = array_values(array_map(fn ($h) => (string) $h['id'], $mine));
            $checks->check('own_hold', $mine !== [], $mine !== [] ? count($mine).' hold(s) placed by fusterai.' : 'No hold placed by fusterai is on this order.');
        } else {
            $signals = $state->warehouseSignals() ?? [];
            if ($signals !== []) {
                $checks->warn('The warehouse may already be working on this order ('.implode('; ', $signals).'); a hold may not stop it.', 'danger');
            }
        }

        return $checks;
    }

    public function execute(ShopifyGateway $gateway, array $params, OrderState $state, Checks $checks, ActionContext $ctx): Outcome
    {
        $foId = (string) $checks->computed['fulfillment_order_id'];

        if ($params['operation'] === 'release') {
            return Outcome::fromResult($gateway->mutate(self::RELEASE, ['id' => $foId, 'holdIds' => $checks->computed['hold_ids']]), 'fulfillmentOrderReleaseHold', data: ['fulfillment_order_id' => $foId, 'hold_ids' => $checks->computed['hold_ids']]);
        }

        return Outcome::fromResult($gateway->mutate(self::HOLD, ['id' => $foId, 'fulfillmentHold' => [
            'reason' => $params['reason'],
            'reasonNotes' => mb_substr($params['note'].' - '.$ctx->marker(), 0, 255),
            'handle' => $ctx->marker(),
            'notifyMerchant' => false,
        ]]), 'fulfillmentOrderHold', data: ['fulfillment_order_id' => $foId]);
    }

    public function verify(ShopifyGateway $gateway, array $params, ActionContext $ctx, OrderStateLoader $loader): Outcome
    {
        $state = $loader->load($gateway, $ctx->scopes, $ctx->execution->order_id, $ctx->execution->order_number);
        if ($state === null) {
            return new Outcome(Outcome::UNKNOWN, $loader->last['error']);
        }

        $holds = [];
        foreach ($state->fulfillmentOrders() as $fo) {
            foreach ((array) ($fo['fulfillmentHolds'] ?? []) as $hold) {
                $holds[] = $hold;
            }
        }
        $ours = array_filter($holds, fn ($h) => ($h['handle'] ?? null) === $ctx->marker());

        if (($params['operation'] ?? '') === 'release') {
            $released = (array) ($ctx->execution->precondition_snapshot['computed']['hold_ids'] ?? []);
            $still = array_filter($holds, fn ($h) => in_array($h['id'] ?? null, $released, true));

            return $still === [] ? new Outcome(Outcome::SUCCEEDED) : new Outcome(Outcome::FAILED, 'The hold is still on the order.');
        }

        return $ours !== [] ? new Outcome(Outcome::SUCCEEDED, data: ['holds' => array_values($ours)]) : new Outcome(Outcome::FAILED, 'No fusterai hold is on the order.');
    }

    public function undo(array $params, ?OrderState $state, Outcome $outcome): ?array
    {
        return ($params['operation'] ?? '') === 'release'
            ? ['how' => 'Put the order back on hold in Shopify.']
            : ['how' => 'Release the hold in Shopify (Orders > the order > Release hold), or propose fulfilment_hold with operation release.'];
    }
}
