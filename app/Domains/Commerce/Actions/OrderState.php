<?php

namespace App\Domains\Commerce\Actions;

use App\Domains\Commerce\Actions\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Fresh order state read from Shopify just before a decision. Wraps the raw
 * `order` node from OrderStateLoader::ORDER_FIELDS.
 */
final class OrderState
{
    /**
     * @param  array<string, mixed>  $order
     */
    public function __construct(
        public readonly array $order,
        public readonly bool $fulfillmentOrdersReadable,
    ) {}

    public function id(): string
    {
        return (string) ($this->order['id'] ?? '');
    }

    public function name(): string
    {
        return (string) ($this->order['name'] ?? '');
    }

    public function email(): string
    {
        return mb_strtolower(trim((string) ($this->order['email'] ?? '')));
    }

    public function currency(): string
    {
        return (string) ($this->order['currencyCode'] ?? $this->order['totalPriceSet']['shopMoney']['currencyCode'] ?? '');
    }

    public function createdAt(): ?CarbonImmutable
    {
        return filled($this->order['createdAt'] ?? null) ? CarbonImmutable::parse((string) $this->order['createdAt']) : null;
    }

    public function ageMinutes(): ?int
    {
        $created = $this->createdAt();

        return $created === null ? null : (int) floor($created->diffInMinutes(CarbonImmutable::now(), true));
    }

    public function cancelled(): bool
    {
        return filled($this->order['cancelledAt'] ?? null);
    }

    public function fulfilmentStatus(): string
    {
        return (string) ($this->order['displayFulfillmentStatus'] ?? '');
    }

    public function financialStatus(): string
    {
        return (string) ($this->order['displayFinancialStatus'] ?? '');
    }

    /** @return list<string> */
    public function tags(): array
    {
        return array_values(array_map('strval', (array) ($this->order['tags'] ?? [])));
    }

    public function note(): string
    {
        return (string) ($this->order['note'] ?? '');
    }

    public function countryCode(): ?string
    {
        $code = $this->order['shippingAddress']['countryCodeV2'] ?? null;

        return is_string($code) && $code !== '' ? strtoupper($code) : null;
    }

    /** @return array<string, mixed>|null */
    public function shippingAddress(): ?array
    {
        $address = $this->order['shippingAddress'] ?? null;

        return is_array($address) ? $address : null;
    }

    public function customerId(): ?string
    {
        $id = $this->order['customer']['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function netPaymentCents(): int
    {
        return Money::cents($this->order['netPaymentSet']['shopMoney']['amount'] ?? null) ?? 0;
    }

    public function totalCents(): int
    {
        return Money::cents($this->order['totalPriceSet']['shopMoney']['amount'] ?? null) ?? 0;
    }

    /** Any fulfilment recorded at all (a parcel has been handed over). */
    public function hasFulfillments(): bool
    {
        return array_filter((array) ($this->order['fulfillments'] ?? []), 'is_array') !== [];
    }

    public function isUnfulfilled(): bool
    {
        return ! $this->hasFulfillments()
            && in_array($this->fulfilmentStatus(), ['UNFULFILLED', 'ON_HOLD', 'OPEN', 'PENDING_FULFILLMENT', 'SCHEDULED'], true);
    }

    /** @return list<array<string, mixed>> */
    public function lineItems(): array
    {
        $items = [];
        foreach ((array) ($this->order['lineItems']['nodes'] ?? []) as $node) {
            if (is_array($node)) {
                $items[] = $node;
            }
        }

        return $items;
    }

    /** @return array<string, mixed>|null */
    public function lineItem(string $id): ?array
    {
        foreach ($this->lineItems() as $item) {
            if (($item['id'] ?? null) === $id) {
                return $item;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function fulfillmentOrders(): array
    {
        return array_values(array_filter((array) ($this->order['fulfillmentOrders']['nodes'] ?? []), 'is_array'));
    }

    /** @return list<array<string, mixed>> fulfilment orders still to be shipped */
    public function openFulfillmentOrders(): array
    {
        return array_values(array_filter(
            $this->fulfillmentOrders(),
            fn ($fo) => in_array($fo['status'] ?? '', ['OPEN', 'ON_HOLD', 'SCHEDULED', 'IN_PROGRESS', 'INCOMPLETE'], true),
        ));
    }

    /**
     * The 4PX signal: a fulfilment order that a fulfilment service has been
     * sent, has accepted, or is working on. Null when Shopify did not let us
     * read fulfilment orders (missing scope): unknown, treat as "maybe".
     *
     * @return list<string>|null human-readable reasons
     */
    public function warehouseSignals(): ?array
    {
        if (! $this->fulfillmentOrdersReadable) {
            return null;
        }

        $signals = [];
        foreach ($this->fulfillmentOrders() as $fo) {
            $where = (string) ($fo['assignedLocation']['name'] ?? 'the fulfilment location');
            $request = (string) ($fo['requestStatus'] ?? '');
            $status = (string) ($fo['status'] ?? '');
            if (in_array($request, ['SUBMITTED', 'ACCEPTED', 'CANCELLATION_REQUESTED', 'CANCELLATION_REJECTED'], true)) {
                $signals[] = "fulfilment request {$request} at {$where}";
            }
            if (in_array($status, ['IN_PROGRESS', 'INCOMPLETE'], true)) {
                $signals[] = "fulfilment {$status} at {$where}";
            }
        }

        return array_values(array_unique($signals));
    }

    /** @return list<array<string, mixed>> */
    public function refunds(): array
    {
        return array_values(array_filter((array) ($this->order['refunds'] ?? []), 'is_array'));
    }

    /** @return array<string, mixed> compact snapshot for the audit trail */
    public function snapshot(): array
    {
        return [
            'id' => $this->id(),
            'name' => $this->name(),
            'email' => $this->email(),
            'created_at' => $this->order['createdAt'] ?? null,
            'age_minutes' => $this->ageMinutes(),
            'cancelled_at' => $this->order['cancelledAt'] ?? null,
            'financial_status' => $this->financialStatus(),
            'fulfilment_status' => $this->fulfilmentStatus(),
            'has_fulfillments' => $this->hasFulfillments(),
            'country' => $this->countryCode(),
            'currency' => $this->currency(),
            'total' => Money::decimal($this->totalCents()),
            'net_payment' => Money::decimal($this->netPaymentCents()),
            'tags' => $this->tags(),
            'fulfillment_orders_readable' => $this->fulfillmentOrdersReadable,
            'fulfillment_orders' => array_map(fn ($fo) => [
                'id' => $fo['id'] ?? null,
                'status' => $fo['status'] ?? null,
                'request_status' => $fo['requestStatus'] ?? null,
                'location' => $fo['assignedLocation']['name'] ?? null,
                'holds' => array_map(fn ($h) => ['id' => $h['id'] ?? null, 'handle' => $h['handle'] ?? null, 'mine' => $h['heldByRequestingApp'] ?? null], (array) ($fo['fulfillmentHolds'] ?? [])),
            ], $this->fulfillmentOrders()),
        ];
    }
}
