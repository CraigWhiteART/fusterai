<?php

namespace App\Domains\Commerce\Actions\Definitions;

use App\Domains\Commerce\Actions\Gateway\ShopifyGateway;
use App\Domains\Commerce\Actions\Models\ActionSettings;
use App\Domains\Commerce\Actions\OrderState;
use App\Domains\Commerce\Actions\OrderStateLoader;

/**
 * Replaces the shipping address with orderUpdate(input: {shippingAddress})
 * (shopify.dev orderUpdate: "updates ... the shipping address for the
 * order"; order editing is only for line items / quantities / discounts).
 * Only while the order is unfulfilled. Scope: write_orders.
 *
 * 4PX: the warehouse may already hold the order with the old address, and a
 * Shopify-side change may not reach it. See docs/agent-actions.md
 * ("The 4PX problem").
 */
class ChangeAddress extends ActionDefinition
{
    public const MUTATION = <<<'GQL'
    mutation ActionChangeAddress($input: OrderInput!) {
      orderUpdate(input: $input) {
        order { id shippingAddress { address1 address2 city provinceCode zip countryCodeV2 } }
        userErrors { field message }
      }
    }
    GQL;

    private const FIELDS = ['first_name' => 'firstName', 'last_name' => 'lastName', 'company' => 'company', 'address1' => 'address1', 'address2' => 'address2', 'city' => 'city', 'province_code' => 'provinceCode', 'zip' => 'zip', 'country_code' => 'countryCode', 'phone' => 'phone'];

    public function key(): string
    {
        return 'change_address';
    }

    public function label(): string
    {
        return 'Change the shipping address';
    }

    public function summary(): string
    {
        return 'Replaces the order\'s shipping address while the order is still unfulfilled.';
    }

    public function requiredScopes(): array
    {
        return [['write_orders']];
    }

    public function isMoney(): bool
    {
        return true;
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
        return '{"first_name": "", "last_name": "", "address1": "", "address2": "", "city": "", "province_code": "NSW", "zip": "", "country_code": "AU", "phone": ""}';
    }

    public function rules(ActionSettings $settings): array
    {
        return [
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'company' => ['nullable', 'string', 'max:120'],
            'address1' => ['required', 'string', 'min:3', 'max:200'],
            'address2' => ['nullable', 'string', 'max:200'],
            'city' => ['required', 'string', 'max:120'],
            'province_code' => ['nullable', 'string', 'max:10'],
            'zip' => ['required', 'string', 'max:20'],
            'country_code' => ['required', 'string', 'size:2', 'alpha'],
            'phone' => ['nullable', 'string', 'max:40'],
        ];
    }

    protected function normalize(array $params, ActionSettings $settings): array
    {
        $out = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $value = trim((string) ($params[$field] ?? ''));
            if ($value !== '') {
                $out[$field] = in_array($field, ['country_code', 'province_code'], true) ? strtoupper($value) : $value;
            }
        }
        ksort($out);

        return $out;
    }

    public function describe(array $params, ?OrderState $state, ActionSettings $settings): string
    {
        $new = $this->oneLine($params);
        $old = $state?->shippingAddress() !== null ? $this->oneLine($this->fromShopify((array) $state->shippingAddress())) : 'unknown';

        return 'Change the shipping address on '.($state?->name() ?: 'the order')." from \"{$old}\" to \"{$new}\". "
            .'Shopify will ship to the new address; if the warehouse (4PX) has already received the order it may still ship to the old one.';
    }

    public function preconditions(array $params, OrderState $state, ActionContext $ctx): Checks
    {
        $checks = new Checks;
        $this->orderPresent($checks, $state);
        $checks->check('unfulfilled', $state->isUnfulfilled(), $state->isUnfulfilled()
            ? 'The order has not been fulfilled.'
            : "The order is {$state->fulfilmentStatus()}: the address can no longer be changed here.");

        $current = $state->countryCode();
        $checks->computed['country_changed'] = $current !== null && $current !== ($params['country_code'] ?? null);
        $checks->computed['destination_country'] = $params['country_code'] ?? null;
        if ($checks->computed['country_changed']) {
            $checks->warn("The new address is in a different country ({$current} -> {$params['country_code']}). Shipping cost and duties may change.");
        }

        return $checks;
    }

    public function execute(ShopifyGateway $gateway, array $params, OrderState $state, Checks $checks, ActionContext $ctx): Outcome
    {
        $address = [];
        foreach (self::FIELDS as $ours => $theirs) {
            if (isset($params[$ours])) {
                $address[$theirs] = $params[$ours];
            }
        }

        $result = $gateway->mutate(self::MUTATION, ['input' => ['id' => $state->id(), 'shippingAddress' => $address]]);

        return Outcome::fromResult($result, 'orderUpdate', data: ['previous_address' => $state->shippingAddress()]);
    }

    public function verify(ShopifyGateway $gateway, array $params, ActionContext $ctx, OrderStateLoader $loader): Outcome
    {
        $state = $loader->load($gateway, $ctx->scopes, $ctx->execution->order_id, $ctx->execution->order_number);
        if ($state === null) {
            return new Outcome(Outcome::UNKNOWN, $loader->last['error']);
        }

        $now = (array) ($state->shippingAddress() ?? []);
        $same = fn (?string $a, ?string $b) => mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));
        $ok = $same($now['address1'] ?? null, $params['address1'] ?? null)
            && $same($now['zip'] ?? null, $params['zip'] ?? null)
            && $same($now['countryCodeV2'] ?? null, $params['country_code'] ?? null);

        return $ok
            ? new Outcome(Outcome::SUCCEEDED, data: ['shipping_address' => $now])
            : new Outcome(Outcome::FAILED, 'The order still shows a different shipping address.');
    }

    public function undo(array $params, ?OrderState $state, Outcome $outcome): ?array
    {
        return ['how' => 'Edit the shipping address in Shopify back to the previous one (and tell the warehouse).', 'previous_address' => $outcome->data['previous_address'] ?? null];
    }

    /** @param  array<string, mixed>  $a */
    private function oneLine(array $a): string
    {
        $name = trim(($a['first_name'] ?? '').' '.($a['last_name'] ?? ''));

        return implode(', ', array_filter([$name, $a['company'] ?? null, $a['address1'] ?? null, $a['address2'] ?? null, $a['city'] ?? null, trim(($a['province_code'] ?? '').' '.($a['zip'] ?? '')), $a['country_code'] ?? null], fn ($v) => filled($v)));
    }

    /** @param  array<string, mixed>  $s  @return array<string, mixed> */
    private function fromShopify(array $s): array
    {
        return [
            'first_name' => $s['firstName'] ?? null, 'last_name' => $s['lastName'] ?? null, 'company' => $s['company'] ?? null,
            'address1' => $s['address1'] ?? null, 'address2' => $s['address2'] ?? null, 'city' => $s['city'] ?? null,
            'province_code' => $s['provinceCode'] ?? null, 'zip' => $s['zip'] ?? null, 'country_code' => $s['countryCodeV2'] ?? null,
        ];
    }
}
