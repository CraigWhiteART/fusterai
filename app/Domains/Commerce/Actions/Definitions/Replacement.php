<?php

namespace App\Domains\Commerce\Actions\Definitions;

use App\Domains\Commerce\Actions\Gateway\ShopifyGateway;
use App\Domains\Commerce\Actions\Models\ActionSettings;
use App\Domains\Commerce\Actions\OrderState;
use App\Domains\Commerce\Actions\OrderStateLoader;
use App\Domains\Commerce\Actions\Support\Money;

/**
 * A free replacement order: draftOrderCreate (same variants, 100% discount,
 * free shipping, same customer and address, tagged with the execution
 * marker) then draftOrderComplete. Approval-only, never unattended.
 * Scope: write_draft_orders (+ read_orders to read the original).
 */
class Replacement extends ActionDefinition
{
    public const CREATE = <<<'GQL'
    mutation ActionReplacementDraft($input: DraftOrderInput!) {
      draftOrderCreate(input: $input) {
        draftOrder { id name }
        userErrors { field message }
      }
    }
    GQL;

    public const COMPLETE = <<<'GQL'
    mutation ActionReplacementComplete($id: ID!) {
      draftOrderComplete(id: $id) {
        draftOrder { id status order { id name } }
        userErrors { field message }
      }
    }
    GQL;

    public const FIND = <<<'GQL'
    query ActionReplacementFind($query: String!) {
      draftOrders(first: 2, query: $query) {
        nodes { id name status order { id name } }
      }
    }
    GQL;

    public function key(): string
    {
        return 'replacement';
    }

    public function label(): string
    {
        return 'Send a replacement';
    }

    public function summary(): string
    {
        return 'Creates and completes a free replacement order for chosen items (100% discount, free shipping). Approval only.';
    }

    public function requiredScopes(): array
    {
        return [['write_draft_orders'], ['read_orders']];
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

    public function unattendedAllowed(): bool
    {
        return false;
    }

    public function paramsHint(): string
    {
        return '{"line_items": [{"line_item_id": "gid://shopify/LineItem/1", "quantity": 1}], "reason": "why"}';
    }

    public function rules(ActionSettings $settings): array
    {
        return [
            'line_items' => ['required', 'array', 'min:1', 'max:10'],
            'line_items.*.line_item_id' => ['required', 'string', 'starts_with:gid://shopify/LineItem/'],
            'line_items.*.quantity' => ['required', 'integer', 'min:1', 'max:10'],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ];
    }

    protected function normalize(array $params, ActionSettings $settings): array
    {
        $items = array_map(fn ($i) => ['line_item_id' => (string) $i['line_item_id'], 'quantity' => (int) $i['quantity']], (array) $params['line_items']);
        usort($items, fn ($a, $b) => strcmp($a['line_item_id'], $b['line_item_id']));

        return ['line_items' => $items, 'reason' => trim((string) $params['reason'])];
    }

    public function describe(array $params, ?OrderState $state, ActionSettings $settings): string
    {
        $items = [];
        foreach ((array) ($params['line_items'] ?? []) as $item) {
            $line = $state?->lineItem((string) $item['line_item_id']);
            $items[] = $item['quantity'].' x '.($line['title'] ?? $item['line_item_id']);
        }

        return 'Create a free replacement order for '.($state?->name() ?: 'the order').': '.implode(', ', $items)
            .'. 100% discount and free shipping, sent to the same address. Reason: '.($params['reason'] ?? '').'. '
            .'The warehouse will ship it as a new order; it cannot be undone once completed (cancel the new order if needed).';
    }

    public function preconditions(array $params, OrderState $state, ActionContext $ctx): Checks
    {
        $checks = new Checks;
        $checks->currency = $state->currency();
        $total = 0;
        foreach ((array) $params['line_items'] as $item) {
            $line = $state->lineItem($item['line_item_id']);
            $variant = $line['variant']['id'] ?? null;
            $checks->check('line_item', $line !== null && $variant !== null, $line === null
                ? "Line item {$item['line_item_id']} is not on this order."
                : ($variant === null ? "{$line['title']} has no product variant any more." : "{$item['quantity']} x {$line['title']}."));
            $checks->check('quantity', $line !== null && $item['quantity'] <= (int) ($line['quantity'] ?? 0), 'Replacing at most what was ordered.');
            $total += (int) (Money::cents($line['originalUnitPriceSet']['shopMoney']['amount'] ?? null) ?? 0) * (int) $item['quantity'];
        }
        $checks->check('address', $state->shippingAddress() !== null, $state->shippingAddress() !== null ? 'The original order has a shipping address.' : 'The original order has no shipping address.');
        $checks->amountCents = $total;

        return $checks;
    }

    public function execute(ShopifyGateway $gateway, array $params, OrderState $state, Checks $checks, ActionContext $ctx): Outcome
    {
        $lines = [];
        foreach ($params['line_items'] as $item) {
            $line = (array) $state->lineItem($item['line_item_id']);
            $lines[] = ['variantId' => $line['variant']['id'], 'quantity' => $item['quantity']];
        }
        $a = (array) $state->shippingAddress();
        $address = array_filter([
            'firstName' => $a['firstName'] ?? null, 'lastName' => $a['lastName'] ?? null, 'company' => $a['company'] ?? null,
            'address1' => $a['address1'] ?? null, 'address2' => $a['address2'] ?? null, 'city' => $a['city'] ?? null,
            'provinceCode' => $a['provinceCode'] ?? null, 'zip' => $a['zip'] ?? null, 'countryCode' => $a['countryCodeV2'] ?? null, 'phone' => $a['phone'] ?? null,
        ], fn ($v) => filled($v));

        $input = [
            'lineItems' => $lines,
            'appliedDiscount' => ['valueType' => 'PERCENTAGE', 'value' => 100.0, 'title' => 'Replacement', 'description' => mb_substr('Replacement for '.$state->name().': '.$params['reason'], 0, 255)],
            'shippingLine' => ['title' => 'Replacement - free shipping', 'priceWithCurrency' => ['amount' => '0.00', 'currencyCode' => $state->currency()]],
            'shippingAddress' => $address,
            'email' => $state->email(),
            'note' => $ctx->auditLine('Replacement for '.$state->name().': '.$params['reason']),
            'tags' => ['fusterai-replacement', $ctx->marker()],
        ];
        if ($state->customerId() !== null) {
            $input['purchasingEntity'] = ['customerId' => $state->customerId()];
        }

        $created = Outcome::fromResult($gateway->mutate(self::CREATE, ['input' => $input]), 'draftOrderCreate');
        if ($created->status !== Outcome::SUCCEEDED) {
            return $created;
        }

        $draftId = (string) ($created->data['payload']['draftOrder']['id'] ?? '');
        if ($draftId === '') {
            return new Outcome(Outcome::UNKNOWN, 'Shopify created a draft but returned no id.', $created->requests, $created->responses);
        }

        $completed = Outcome::fromResult($gateway->mutate(self::COMPLETE, ['id' => $draftId]), 'draftOrderComplete', data: ['draft_order_id' => $draftId]);

        return $created->then($completed);
    }

    public function verify(ShopifyGateway $gateway, array $params, ActionContext $ctx, OrderStateLoader $loader): Outcome
    {
        $result = $gateway->query(self::FIND, ['query' => 'tag:'.$ctx->marker()]);
        if (! $result->ok()) {
            return new Outcome(Outcome::UNKNOWN, $result->errorText());
        }

        foreach ((array) ($result->data['draftOrders']['nodes'] ?? []) as $draft) {
            if (! is_array($draft)) {
                continue;
            }
            if (($draft['status'] ?? '') === 'COMPLETED') {
                return new Outcome(Outcome::SUCCEEDED, data: ['draft_order_id' => $draft['id'] ?? null, 'order' => $draft['order'] ?? null]);
            }

            return new Outcome(Outcome::FAILED, 'Draft order '.($draft['name'] ?? '').' exists but was not completed: complete or delete it in Shopify.', data: ['draft_order_id' => $draft['id'] ?? null]);
        }

        return new Outcome(Outcome::FAILED, 'No replacement draft order carrying this execution\'s marker exists.');
    }

    public function undo(array $params, ?OrderState $state, Outcome $outcome): ?array
    {
        return ['how' => 'Cancel the replacement order in Shopify before the warehouse ships it.', 'order' => $outcome->data['payload']['draftOrder']['order'] ?? null];
    }
}
