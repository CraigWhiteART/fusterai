<?php

namespace App\Domains\Commerce\Actions\Support;

use App\Domains\Commerce\Models\CommerceSetting;

/**
 * The access scopes the store granted (commerce_settings.shopify_granted_scopes,
 * recorded on every client-credentials token exchange). A write scope
 * includes its read scope (shopify.dev "Manage access scopes").
 */
final class Scopes
{
    /** @param  list<string>  $granted */
    public function __construct(public readonly array $granted) {}

    public static function for(CommerceSetting $settings): self
    {
        $raw = (string) ($settings->shopify_granted_scopes ?? '');

        return new self(array_values(array_filter(array_map(
            fn ($s) => strtolower(trim($s)),
            preg_split('/[\s,]+/', $raw) ?: [],
        ))));
    }

    public function has(string $scope): bool
    {
        $scope = strtolower($scope);
        if (in_array($scope, $this->granted, true)) {
            return true;
        }
        if (str_starts_with($scope, 'read_')) {
            return in_array('write_'.substr($scope, 5), $this->granted, true);
        }

        return false;
    }

    /** @param  list<string>  $anyOf */
    public function hasAny(array $anyOf): bool
    {
        foreach ($anyOf as $scope) {
            if ($this->has($scope)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Requirement groups that are not met. Each group is "any of these".
     *
     * @param  list<list<string>>  $groups
     * @return list<list<string>>
     */
    public function missing(array $groups): array
    {
        return array_values(array_filter($groups, fn (array $group) => ! $this->hasAny($group)));
    }

    public function canReadFulfillmentOrders(): bool
    {
        return $this->hasAny([
            'read_merchant_managed_fulfillment_orders',
            'read_third_party_fulfillment_orders',
            'read_assigned_fulfillment_orders',
        ]);
    }

    public function known(): bool
    {
        return $this->granted !== [];
    }
}
