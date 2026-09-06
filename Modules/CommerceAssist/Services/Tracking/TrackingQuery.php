<?php

namespace Modules\CommerceAssist\Services\Tracking;

use Modules\CommerceAssist\Models\ShopifySnapshot;

/**
 * Every key we might have for a shipment. Providers take what they can use:
 * Track123 prefers the Shopify order id, the aggregators need a tracking number.
 */
class TrackingQuery
{
    public function __construct(
        public readonly ?string $trackingNumber = null,
        public readonly ?string $orderId = null,
        public readonly ?string $orderNumber = null,
        public readonly ?string $carrierCode = null,
        public readonly ?string $carrierName = null,
        public readonly ?string $destinationCountry = null,
    ) {}

    public static function fromShopify(ShopifySnapshot $snapshot): self
    {
        $payload = $snapshot->payload ?? [];

        return new self(
            trackingNumber: self::clean($payload['tracking_number'] ?? null),
            orderId: self::numericId($payload['shopify_order_id'] ?? null),
            orderNumber: self::orderNumber($payload['order_number'] ?? null),
            carrierName: self::clean($payload['tracking_company'] ?? null),
            destinationCountry: self::clean($payload['shipping_country'] ?? null),
        );
    }

    /** Nothing to ask a provider about. */
    public function isEmpty(): bool
    {
        return $this->trackingNumber === null
            && $this->orderId === null
            && $this->orderNumber === null;
    }

    /** Stable identity for freshness checks, so a re-generate does not re-bill. */
    public function cacheKey(): string
    {
        return implode('|', [
            $this->trackingNumber ?? '-',
            $this->orderId ?? '-',
            $this->orderNumber ?? '-',
        ]);
    }

    /** Shopify returns `gid://shopify/Order/123`; Track123 wants the trailing digits. */
    private static function numericId(mixed $value): ?string
    {
        $value = self::clean($value);
        if ($value === null) {
            return null;
        }

        return preg_match('/(\d+)\s*$/', $value, $m) === 1 ? $m[1] : null;
    }

    /** `#SD8421` is stored with the hash; the by-number endpoint takes digits only. */
    private static function orderNumber(mixed $value): ?string
    {
        $value = self::clean($value);

        return $value === null ? null : ltrim($value, '#');
    }

    private static function clean(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
