<?php

namespace App\Domains\Agent\Simulation;

use App\Domains\Agent\Models\FrozenCase;
use App\Domains\Commerce\Models\CommerceSetting;
use App\Domains\Commerce\Models\ShopifySnapshot;
use App\Domains\Commerce\Services\ShopifyClient;
use App\Domains\Commerce\Services\ShopifyLookupService;
use App\Domains\Commerce\Services\Tracking\TrackingEvent;
use App\Domains\Commerce\Services\Tracking\TrackingProviderFactory;
use App\Domains\Commerce\Services\Tracking\TrackingQuery;
use App\Domains\Commerce\Services\Tracking\TrackingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The explicit "prepare cases" step (never part of a simulation run):
 * for a frozen case without recorded evidence, fetch the order from Shopify
 * now and rebuild what it looked like at the cut from the order's own
 * timestamps (created, fulfilments, refunds, cancellation), and the carrier
 * events up to the cut. The result is stored on the case (quality
 * `reconstructed`), or quality stays `none` with the reason when the order
 * cannot be retrieved. Rate limiting is done by PrepareFrozenCasesJob.
 */
class EvidenceReconstructor
{
    private const ORDERS_QUERY = <<<'GQL'
    query AgentReconstructOrders($query: String!) {
      orders(first: 10, query: $query, sortKey: CREATED_AT, reverse: true) {
        edges {
          node {
            id
            name
            email
            createdAt
            cancelledAt
            displayFinancialStatus
            displayFulfillmentStatus
            tags
            customer { firstName lastName email }
            shippingAddress { country countryCodeV2 }
            fulfillments { createdAt trackingInfo { number url company } }
            refunds { createdAt }
            lineItems(first: 50) { edges { node { title quantity variantTitle sku } } }
          }
        }
      }
    }
    GQL;

    public function __construct(
        private readonly ShopifyLookupService $lookup,
        private readonly TrackingProviderFactory $providers,
        private readonly FrozenCaseFactory $factory,
    ) {}

    /** @return string the case's evidence quality afterwards */
    public function prepare(FrozenCase $case): string
    {
        if ($case->evidence_quality === FrozenCase::QUALITY_RECORDED) {
            return $case->evidence_quality;
        }

        $settings = CommerceSetting::forWorkspace($case->workspace_id);
        if (! $settings->hasShopifyCredentials()) {
            return $this->none($case, 'Shopify is not configured.');
        }

        $cut = CarbonImmutable::instance($case->cut_at);

        try {
            $order = $this->findOrder(new ShopifyClient($settings), $case, $cut);
        } catch (Throwable $e) {
            Log::warning('Frozen case reconstruction failed', ['case_id' => $case->id, 'error' => $e->getMessage()]);
            $case->forceFill(['evidence_error' => mb_substr('Shopify lookup failed: '.$e->getMessage(), 0, 1000), 'evidence_prepared_at' => now()])->save();

            return $case->evidence_quality;
        }

        if ($order === null) {
            return $this->none($case, 'No Shopify order placed before this message could be found.');
        }

        $shopify = $this->shopifyAsOf($order, $cut);
        $tracking = null;
        $notes = [];
        if (filled($shopify['tracking_number'] ?? null) && ($provider = $this->providers->make($settings)) !== null) {
            try {
                $result = $provider->lookup(new TrackingQuery(
                    trackingNumber: (string) $shopify['tracking_number'],
                    orderId: preg_replace('/\D+/', '', (string) $shopify['shopify_order_id']) ?: null,
                    orderNumber: ltrim((string) $shopify['order_number'], '#'),
                    carrierName: $shopify['tracking_company'] ?? null,
                    destinationCountry: $shopify['shipping_country'] ?? null,
                ));
                $tracking = $result !== null ? $this->trackingAsOf($result->toPayload(), $cut) : null;
            } catch (Throwable $e) {
                $notes[] = 'Carrier history could not be retrieved.';
                Log::info('Frozen case tracking reconstruction failed', ['case_id' => $case->id, 'error' => $e->getMessage()]);
            }
        }

        $case->forceFill($this->factory->evidenceAttributes([
            'source' => FrozenCase::QUALITY_RECONSTRUCTED,
            'shopify' => $shopify,
            'tracking' => $tracking,
            'shopify_snapshot_id' => null,
            'tracking_snapshot_id' => null,
            'as_of' => $cut->toIso8601String(),
            'notes' => $notes,
        ], FrozenCase::QUALITY_RECONSTRUCTED))->save();

        return FrozenCase::QUALITY_RECONSTRUCTED;
    }

    /** @return array<string, mixed>|null */
    private function findOrder(ShopifyClient $client, FrozenCase $case, CarbonImmutable $cut): ?array
    {
        $numbers = [];
        if ($case->conversation_id !== null) {
            foreach (ShopifySnapshot::where('conversation_id', $case->conversation_id)->whereNotNull('order_number')->pluck('order_number') as $number) {
                $numbers[] = ltrim((string) $number, '#');
            }
        }
        $text = (string) $case->subject;
        foreach ($case->transcript as $turn) {
            if (($turn['role'] ?? '') === 'customer') {
                $text .= "\n".($turn['text'] ?? '');
            }
        }
        $numbers = array_values(array_unique([...$numbers, ...$this->lookup->extractOrderNumbers($text)]));

        $candidates = [];
        foreach (array_slice($numbers, 0, 3) as $number) {
            $candidates = [...$candidates, ...$this->orders($client, 'name:'.$number.' OR name:#'.$number)];
        }
        if ($candidates === [] && filled($case->customer_email)) {
            $candidates = $this->orders($client, 'email:'.$case->customer_email.' created_at:<='.$cut->toIso8601String());
        }

        // Only orders that existed at the cut.
        $candidates = array_values(array_filter($candidates, fn ($o) => isset($o['createdAt']) && CarbonImmutable::parse($o['createdAt'])->lte($cut)));
        foreach ($candidates as $order) {
            $name = strtoupper(ltrim((string) ($order['name'] ?? ''), '#'));
            if (in_array($name, array_map('strtoupper', $numbers), true)) {
                return $order;
            }
        }

        return $candidates[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    private function orders(ShopifyClient $client, string $query): array
    {
        $out = [];
        foreach ((array) ($client->graphql(self::ORDERS_QUERY, ['query' => $query])['orders']['edges'] ?? []) as $edge) {
            if (is_array($edge['node'] ?? null)) {
                $out[] = $edge['node'];
            }
        }

        return $out;
    }

    /**
     * ShopifyLookupService payload shape, as of the cut.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function shopifyAsOf(array $order, CarbonImmutable $cut): array
    {
        $before = fn (?string $at) => $at !== null && CarbonImmutable::parse($at)->lte($cut);
        $created = CarbonImmutable::parse((string) $order['createdAt']);

        $fulfilments = array_values((array) ($order['fulfillments'] ?? []));
        $fulfilledBefore = array_values(array_filter($fulfilments, fn ($f) => $before($f['createdAt'] ?? null)));
        $fulfilment = match (true) {
            $fulfilledBefore === [] => 'UNFULFILLED',
            count($fulfilledBefore) < count($fulfilments) => 'PARTIALLY_FULFILLED',
            default => (string) ($order['displayFulfillmentStatus'] ?? 'FULFILLED'),
        };

        $refunds = (array) ($order['refunds'] ?? []);
        $refundedBefore = array_filter($refunds, fn ($r) => $before($r['createdAt'] ?? null)) !== [];
        $payment = (string) ($order['displayFinancialStatus'] ?? 'UNKNOWN');
        if (! $refundedBefore && in_array($payment, ['REFUNDED', 'PARTIALLY_REFUNDED'], true)) {
            $payment = 'PAID';
        }

        $tracking = ['number' => null, 'url' => null, 'company' => null, 'at' => null];
        foreach ($fulfilledBefore as $f) {
            foreach ((array) ($f['trackingInfo'] ?? []) as $info) {
                if (filled($info['number'] ?? null) || filled($info['url'] ?? null)) {
                    $tracking = ['number' => $info['number'] ?? null, 'url' => $info['url'] ?? null, 'company' => $info['company'] ?? null, 'at' => $f['createdAt'] ?? null];
                    break 2;
                }
            }
        }

        $products = [];
        foreach ((array) ($order['lineItems']['edges'] ?? []) as $edge) {
            $item = (array) ($edge['node'] ?? []);
            $products[] = ['title' => $item['title'] ?? null, 'variant' => $item['variantTitle'] ?? null, 'quantity' => $item['quantity'] ?? null, 'sku' => $item['sku'] ?? null, 'preorder' => null];
        }
        $customer = (array) ($order['customer'] ?? []);
        $name = trim(($customer['firstName'] ?? '').' '.($customer['lastName'] ?? ''));

        return [
            'found' => true,
            'reconstructed' => true,
            'customer_name' => $name !== '' ? $name : null,
            'customer_email' => $customer['email'] ?? null,
            'shopify_customer_id' => null,
            'shopify_order_id' => $order['id'] ?? null,
            'order_number' => $order['name'] ?? null,
            'order_email' => $order['email'] ?? null,
            'order_date' => $created->toDateString(),
            'order_age_days' => (int) $created->diffInDays($cut),
            'products' => $products,
            'payment_status' => $payment,
            'fulfilment_status' => $fulfilment,
            'tracking_number' => $tracking['number'],
            'tracking_url' => $tracking['url'],
            'tracking_company' => $tracking['company'],
            'tracking_age_days' => $tracking['at'] !== null ? (int) CarbonImmutable::parse($tracking['at'])->diffInDays($cut) : null,
            'shipping_country' => $order['shippingAddress']['country'] ?? null,
            'shipping_country_code' => $order['shippingAddress']['countryCodeV2'] ?? null,
            'cancelled' => $before($order['cancelledAt'] ?? null),
            'refunded' => $refundedBefore,
            'order_tags' => array_values((array) ($order['tags'] ?? [])),
            // Order notes are current, not as-of: never replayed.
            'notes' => null,
            'has_preorder_items' => null,
            'has_current_stock_items' => null,
            'preorder_determined' => false,
        ];
    }

    /**
     * A carrier payload cut at the given instant: only events up to it, the
     * status they imply, and ages relative to it.
     *
     * @param  array<string, mixed>  $payload  TrackingResult::toPayload()
     * @return array<string, mixed>
     */
    public function trackingAsOf(array $payload, CarbonImmutable $cut): array
    {
        $events = array_values(array_filter((array) ($payload['events'] ?? []), function ($event) use ($cut) {
            $at = TrackingEvent::parseDate($event['occurred_at'] ?? null);

            return $at !== null && $at->lte($cut);
        }));
        usort($events, fn ($a, $b) => strcmp((string) $b['occurred_at'], (string) $a['occurred_at']));

        $delivered = TrackingEvent::parseDate($payload['delivered_at'] ?? null);
        $deliveredBefore = $delivered !== null && $delivered->lte($cut);
        $last = $events[0] ?? null;
        $lastAt = $last !== null ? TrackingEvent::parseDate($last['occurred_at']) : null;

        $status = match (true) {
            $deliveredBefore => TrackingStatus::Delivered,
            $last === null => TrackingStatus::InfoReceived,
            TrackingStatus::tryFrom((string) ($payload['status'] ?? '')) === TrackingStatus::Delivered => TrackingStatus::InTransit,
            default => TrackingStatus::tryFrom((string) ($payload['status'] ?? '')) ?? TrackingStatus::InTransit,
        };

        // A domestic hand-off only counts once a last-mile event had happened by the cut.
        $lastMile = array_filter($events, fn ($e) => (bool) ($e['last_mile'] ?? false)) !== [];

        return [
            ...$payload,
            'found' => true,
            'reconstructed' => true,
            'last_mile_handoff' => $lastMile,
            'last_mile_carrier' => $lastMile ? ($payload['last_mile_carrier'] ?? null) : null,
            'last_mile_tracking_number' => $lastMile ? ($payload['last_mile_tracking_number'] ?? null) : null,
            'last_mile_tracking_url' => $lastMile ? ($payload['last_mile_tracking_url'] ?? null) : null,
            'status' => $status->value,
            'status_label' => $status->label(),
            'status_raw' => null,
            'sub_status' => null,
            'last_event' => $last['description'] ?? null,
            'last_event_at' => $lastAt?->toIso8601String(),
            'days_since_last_scan' => $lastAt !== null ? max(0, (int) $lastAt->diffInDays($cut)) : null,
            'delivered_at' => $deliveredBefore ? $delivered->toIso8601String() : null,
            'estimated_delivery_at' => null,
            'needs_attention' => $status->needsAttention(),
            'proof_of_delivery' => $deliveredBefore ? ($payload['proof_of_delivery'] ?? null) : null,
            'events' => $events,
        ];
    }

    private function none(FrozenCase $case, string $reason): string
    {
        $case->forceFill([
            'evidence_quality' => FrozenCase::QUALITY_NONE,
            'evidence_error' => $reason,
            'evidence_prepared_at' => now(),
        ])->save();

        return FrozenCase::QUALITY_NONE;
    }
}
