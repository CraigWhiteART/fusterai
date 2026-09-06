<?php

namespace Modules\CommerceAssist\Services\Tracking\Providers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\CommerceAssist\Services\Tracking\TrackingEvent;
use Modules\CommerceAssist\Services\Tracking\TrackingProvider;
use Modules\CommerceAssist\Services\Tracking\TrackingQuery;
use Modules\CommerceAssist\Services\Tracking\TrackingResult;
use Modules\CommerceAssist\Services\Tracking\TrackingStatus;

/**
 * Track123's Shopify App API.
 *
 * Preferred provider for a Shopify store running the Track123 app: lookups are
 * order-keyed reads of data the app already collects, so there is no register-
 * then-poll step and no per-number quota to manage. It also resolves the
 * last-mile handoff (4PX to domestic carrier) in `last_mile_info`.
 *
 * @see https://intercom.help/track123/en/articles/11782016-track123-shopify-app-api-documentation
 */
class Track123Provider implements TrackingProvider
{
    private const BASE_URL = 'https://shp.track123.com/shopify/api/v1';

    /**
     * The docs name the checkpoint array in the singular; accept the plausible
     * aliases so a schema tweak degrades to "no events" rather than an exception.
     */
    private const EVENT_KEYS = ['tracking_details', 'details', 'events', 'trackinfo'];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $storeUuid,
        private readonly int $timeout = 15,
    ) {}

    public function key(): string
    {
        return 'track123';
    }

    public function lookup(TrackingQuery $query): ?TrackingResult
    {
        if ($this->apiKey === '' || $this->storeUuid === '' || $query->isEmpty()) {
            return null;
        }

        foreach ($this->endpoints($query) as $endpoint) {
            $response = $this->get($endpoint);

            if ($response->status() === 404) {
                continue;
            }

            $order = $this->unwrap($response->json());
            if ($order === []) {
                continue;
            }

            return $this->map($order, $query);
        }

        return TrackingResult::notFound(
            $this->key(),
            $query->trackingNumber,
            'Track123 has no record for this order or tracking number.',
        );
    }

    /** @return list<string> */
    private function endpoints(TrackingQuery $query): array
    {
        $uuid = rawurlencode($this->storeUuid);
        $endpoints = [];

        if ($query->orderId !== null) {
            $endpoints[] = "/{$uuid}/orders/".rawurlencode($query->orderId).'.json';
        }
        if ($query->trackingNumber !== null) {
            $endpoints[] = "/{$uuid}/orders/by-tracking/".rawurlencode($query->trackingNumber).'.json';
        }
        // The by-number endpoint documents numeric order numbers only.
        if ($query->orderNumber !== null && ctype_digit($query->orderNumber)) {
            $endpoints[] = "/{$uuid}/orders/by-number/".rawurlencode($query->orderNumber).'.json';
        }

        return $endpoints;
    }

    private function get(string $endpoint): Response
    {
        $response = Http::withHeaders([
            'X-Api-Key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->timeout($this->timeout)->acceptJson()->get(self::BASE_URL.$endpoint);

        if ($response->status() === 404) {
            return $response;
        }

        $response->throw();

        return $response;
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function map(array $order, TrackingQuery $query): TrackingResult
    {
        $fulfillment = $this->selectFulfillment($order, $query->trackingNumber);

        if ($fulfillment === null) {
            return new TrackingResult(
                provider: $this->key(),
                status: TrackingStatus::Pending,
                trackingNumber: $query->trackingNumber,
                statusRaw: $this->str($order['status'] ?? null),
                trackingUrl: $this->str($order['tracking_link'] ?? null),
                raw: $order,
            );
        }

        $lastMile = is_array($fulfillment['last_mile_info'] ?? null) ? $fulfillment['last_mile_info'] : [];
        $courier = is_array($fulfillment['courier'] ?? null) ? $fulfillment['courier'] : [];

        $events = array_merge(
            $this->events($fulfillment, lastMile: false),
            $this->events($lastMile, lastMile: true),
        );
        usort($events, fn (TrackingEvent $a, TrackingEvent $b) => ($b->occurredAt?->timestamp ?? 0) <=> ($a->occurredAt?->timestamp ?? 0));

        $status = TrackingStatus::fromProvider($this->str($fulfillment['transit_status'] ?? null));
        $lastEventAt = TrackingEvent::parseDate($fulfillment['last_event_time'] ?? null)
            ?? ($events[0]->occurredAt ?? null);

        return new TrackingResult(
            provider: $this->key(),
            status: $status,
            trackingNumber: $this->str($fulfillment['tracking_number'] ?? null) ?? $query->trackingNumber,
            statusRaw: $this->str($fulfillment['transit_status'] ?? null),
            subStatus: $this->str($fulfillment['transit_sub_status'] ?? null),
            carrierCode: $this->str($fulfillment['carrier_code'] ?? $courier['code'] ?? null),
            carrierName: $this->str($courier['name'] ?? $fulfillment['tracking_company'] ?? null),
            trackingUrl: $this->str($order['tracking_link'] ?? $courier['query_link'] ?? null),
            lastMileCarrier: $this->str($lastMile['lm_track_no_provider_name'] ?? $lastMile['lm_track_no_provider_code'] ?? null),
            lastMileTrackingNumber: $this->str($lastMile['lm_track_no'] ?? null),
            lastMileTrackingUrl: $this->str($lastMile['query_link'] ?? null),
            lastEvent: $this->str($fulfillment['last_event'] ?? null) ?? ($events[0]->description ?? null),
            lastEventAt: $lastEventAt,
            deliveredAt: $status->isDelivered() ? $lastEventAt : null,
            events: array_slice($events, 0, 30),
            raw: $fulfillment,
        );
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>|null
     */
    private function selectFulfillment(array $order, ?string $trackingNumber): ?array
    {
        $fulfillments = array_values(array_filter(
            is_array($order['fulfillments'] ?? null) ? $order['fulfillments'] : [],
            'is_array',
        ));

        if ($fulfillments === []) {
            return null;
        }

        if ($trackingNumber !== null) {
            foreach ($fulfillments as $fulfillment) {
                if ($this->str($fulfillment['tracking_number'] ?? null) === $trackingNumber) {
                    return $fulfillment;
                }
            }
        }

        return $fulfillments[0];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<TrackingEvent>
     */
    private function events(array $node, bool $lastMile): array
    {
        $rows = [];
        foreach (self::EVENT_KEYS as $key) {
            if (is_array($node[$key] ?? null)) {
                $rows = $node[$key];
                break;
            }
        }

        $events = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $events[] = TrackingEvent::make(
                $row['event_time_utc'] ?? $row['event_time'] ?? null,
                $this->str($row['event_detail'] ?? null),
                $this->str($row['event_location'] ?? null),
                lastMile: $lastMile,
            );
        }

        return $events;
    }

    /**
     * Tolerate a `data` envelope without depending on one.
     *
     * @return array<string, mixed>
     */
    private function unwrap(mixed $json): array
    {
        if (! is_array($json)) {
            return [];
        }

        if (is_array($json['data'] ?? null)) {
            return $json['data'];
        }

        return $json;
    }

    private function str(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
