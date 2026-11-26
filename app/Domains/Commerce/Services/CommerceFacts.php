<?php

namespace App\Domains\Commerce\Services;

use App\Domains\Commerce\Services\Tracking\TrackingStatus;

/**
 * Turns stored Shopify / carrier payloads into label-value facts and the
 * shipment guidance rules (stale scan, last-mile handoff, proof of delivery).
 * Same fields and rules as the legacy ContextBuilder::formatShopify,
 * formatTracking and formatRules, but as data so the agent pipeline can give
 * every fact an id and validate drafts against it. Pure: no I/O.
 */
class CommerceFacts
{
    /**
     * @param  array<string, mixed>  $payload  ShopifySnapshot payload with found=true
     * @return list<array{key: string, label: string, value: string}>
     */
    public function shopifyFacts(array $payload): array
    {
        $fields = [
            'customer_name' => 'Customer name (Shopify)',
            'order_number' => 'Order number',
            'order_date' => 'Order date',
            'order_age_days' => 'Order age in days',
            'payment_status' => 'Payment status',
            'fulfilment_status' => 'Fulfilment status',
            'tracking_number' => 'Tracking number',
            'tracking_url' => 'Tracking URL',
            'tracking_company' => 'Tracking company',
            'tracking_age_days' => 'Days since fulfilment',
            'shipping_country' => 'Shipping country',
            'shipping_country_code' => 'Shipping country code',
        ];

        $facts = [];
        foreach ($fields as $key => $label) {
            $value = $this->scalar($payload[$key] ?? null);
            if ($value !== null) {
                $facts[] = ['key' => 'shopify.'.$key, 'label' => $label, 'value' => $value];
            }
        }

        foreach (['cancelled' => 'Order cancelled', 'refunded' => 'Order refunded'] as $key => $label) {
            if (array_key_exists($key, $payload) && $payload[$key] !== null) {
                $facts[] = ['key' => 'shopify.'.$key, 'label' => $label, 'value' => $payload[$key] ? 'yes' : 'no'];
            }
        }

        if (($payload['preorder_determined'] ?? false) === true) {
            $facts[] = ['key' => 'shopify.has_preorder_items', 'label' => 'Order has preorder items', 'value' => ($payload['has_preorder_items'] ?? false) ? 'yes' : 'no'];
        }

        foreach (array_values($payload['products'] ?? []) as $i => $product) {
            $stock = ($product['preorder'] ?? null) === true ? 'preorder' : (($product['preorder'] ?? null) === false ? 'current stock' : 'stock type unknown');
            $facts[] = [
                'key' => 'shopify.product.'.$i,
                'label' => 'Ordered product',
                'value' => sprintf(
                    '%s / %s x%s (%s)',
                    $product['title'] ?? 'unknown',
                    $product['variant'] ?? 'default',
                    $product['quantity'] ?? '?',
                    $stock,
                ),
            ];
        }

        $tags = array_values(array_filter((array) ($payload['order_tags'] ?? [])));
        if ($tags !== []) {
            $facts[] = ['key' => 'shopify.order_tags', 'label' => 'Order tags', 'value' => implode(', ', $tags)];
        }

        return $facts;
    }

    /**
     * Order notes are written by staff or apps and can carry anything, so they
     * are kept apart from facts and only ever shown as untrusted data.
     *
     * @param  array<string, mixed>  $payload
     */
    public function shopifyNotes(array $payload): ?string
    {
        $notes = trim((string) ($payload['notes'] ?? ''));

        return $notes === '' ? null : $notes;
    }

    /**
     * @param  array<string, mixed>  $payload  TrackingSnapshot payload with found=true
     * @return list<array{key: string, label: string, value: string}>
     */
    public function trackingFacts(array $payload): array
    {
        $fields = [
            'carrier_name' => 'Carrier',
            'tracking_number' => 'Carrier tracking number',
            'status_label' => 'Carrier status',
            'sub_status' => 'Carrier sub-status',
            'last_event' => 'Last carrier scan',
            'last_event_at' => 'Last carrier scan at',
            'days_since_last_scan' => 'Days since last scan',
            'estimated_delivery_at' => 'Estimated delivery (carrier estimate)',
            'delivered_at' => 'Delivered at',
            'last_mile_carrier' => 'Last-mile carrier',
            'last_mile_tracking_number' => 'Last-mile tracking number',
            'last_mile_tracking_url' => 'Last-mile tracking URL',
            'tracking_url' => 'Carrier tracking URL',
        ];

        $facts = [];
        foreach ($fields as $key => $label) {
            $value = $this->scalar($payload[$key] ?? null);
            if ($value !== null) {
                $facts[] = ['key' => 'tracking.'.$key, 'label' => $label, 'value' => $value];
            }
        }

        $proof = is_array($payload['proof_of_delivery'] ?? null) ? $payload['proof_of_delivery'] : null;
        if ($proof !== null) {
            foreach (['label' => 'Proof of delivery', 'detail' => 'Proof detail', 'location' => 'Proof location'] as $key => $label) {
                $value = $this->scalar($proof[$key] ?? null);
                if ($value !== null) {
                    $facts[] = ['key' => 'tracking.proof.'.$key, 'label' => $label, 'value' => $value];
                }
            }
        }

        foreach (array_slice(array_values($payload['events'] ?? []), 0, 8) as $i => $event) {
            $facts[] = [
                'key' => 'tracking.event.'.$i,
                'label' => 'Carrier scan',
                'value' => sprintf(
                    '%s: %s%s%s',
                    $event['occurred_at'] ?? 'unknown time',
                    $event['description'] ?? 'no description',
                    filled($event['location'] ?? null) ? ' ('.$event['location'].')' : '',
                    ($event['last_mile'] ?? false) ? ' [last mile]' : '',
                ),
            ];
        }

        return $facts;
    }

    /**
     * The shipment rules ContextBuilder::formatRules applies, as plain guidance.
     *
     * @param  array<string, mixed>|null  $shopify
     * @param  array<string, mixed>|null  $tracking
     * @return list<string>
     */
    public function shipmentGuidance(?array $shopify, ?array $tracking, int $staleDays): array
    {
        $guidance = [];
        $trackingFound = (bool) ($tracking['found'] ?? false);
        $status = TrackingStatus::tryFrom((string) ($tracking['status'] ?? '')) ?? TrackingStatus::Unknown;

        if ($tracking !== null && ! $trackingFound) {
            $guidance[] = 'The carrier has no record for this shipment yet. Say tracking has no updates yet; do not infer that the parcel is lost, stuck or delayed.';
        }

        $scanAge = $trackingFound ? ($tracking['days_since_last_scan'] ?? null) : null;
        $age = $scanAge ?? ($shopify['tracking_age_days'] ?? null);
        if ($staleDays > 0 && is_numeric($age) && (int) $age >= $staleDays && ! $status->isDelivered()) {
            $what = $scanAge !== null ? 'The carrier last scanned this parcel' : 'The fulfilment was created';
            $guidance[] = "{$what} {$age} days ago, past the {$staleDays}-day threshold. Escalate to a human rather than claiming the parcel is lost; do not invent a carrier investigation.";
        }

        if ($trackingFound) {
            if ($status->needsAttention()) {
                $guidance[] = 'The carrier has flagged this shipment ('.$status->label().'). State the carrier status plainly, do not speculate about the cause, and say a human is reviewing it.';
            }

            if (filled($tracking['last_mile_carrier'] ?? null) || filled($tracking['last_mile_tracking_number'] ?? null)) {
                $guidance[] = 'The parcel was handed to a domestic carrier ('.($tracking['last_mile_carrier'] ?? 'unknown').'). You may give the last-mile tracking number exactly as written in the evidence.';
            }

            $proof = is_array($tracking['proof_of_delivery'] ?? null) ? $tracking['proof_of_delivery'] : null;
            if ($proof !== null) {
                $guidance[] = ($proof['attributable'] ?? false)
                    ? 'The carrier recorded how the parcel was accepted ('.($proof['label'] ?? 'unknown').'). You may state this. Do not claim a signature image or photo is available.'
                    : 'The carrier marked this delivered but did not record who accepted it ('.($proof['label'] ?? 'unknown').'). Do not assert the customer received it and do not claim a signature exists.';
            }
        }

        return $guidance;
    }

    private function scalar(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
