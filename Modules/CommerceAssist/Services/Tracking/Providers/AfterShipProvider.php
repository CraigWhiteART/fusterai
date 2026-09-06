<?php

namespace Modules\CommerceAssist\Services\Tracking\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Modules\CommerceAssist\Services\Tracking\TrackingEvent;
use Modules\CommerceAssist\Services\Tracking\TrackingProvider;
use Modules\CommerceAssist\Services\Tracking\TrackingQuery;
use Modules\CommerceAssist\Services\Tracking\TrackingResult;
use Modules\CommerceAssist\Services\Tracking\TrackingStatus;

/**
 * AfterShip Tracking v4.
 *
 * Register-then-poll like 17TRACK. Its `signed_by` field feeds proof-of-delivery
 * directly, which is the one thing it does better than the others here.
 *
 * Endpoint shapes follow AfterShip's published v4 API. Confirm against a live
 * response before enabling this provider in production.
 */
class AfterShipProvider implements TrackingProvider
{
    private const BASE_URL = 'https://api.aftership.com/v4';

    public function __construct(
        private readonly string $apiKey,
        private readonly int $timeout = 15,
    ) {}

    public function key(): string
    {
        return 'aftership';
    }

    public function lookup(TrackingQuery $query): ?TrackingResult
    {
        if ($this->apiKey === '' || $query->trackingNumber === null) {
            return null;
        }

        $path = '/trackings/'.rawurlencode($query->trackingNumber);
        $response = $this->request()->get(self::BASE_URL.$path);

        if ($response->status() === 404) {
            $this->request()->post(self::BASE_URL.'/trackings', [
                'tracking' => array_filter([
                    'tracking_number' => $query->trackingNumber,
                    'slug' => $query->carrierCode,
                    'destination_country_iso3' => null,
                ], fn ($value) => $value !== null),
            ]);

            return TrackingResult::notFound(
                $this->key(),
                $query->trackingNumber,
                'Registered with AfterShip; no carrier scans returned yet.',
            );
        }

        $response->throw();

        $tracking = $response->json('data.tracking');

        if (! is_array($tracking)) {
            return TrackingResult::notFound($this->key(), $query->trackingNumber, 'AfterShip returned no tracking record.');
        }

        return $this->map($tracking, $query);
    }

    /**
     * @param  array<string, mixed>  $tracking
     */
    private function map(array $tracking, TrackingQuery $query): TrackingResult
    {
        $checkpoints = array_values(array_filter(
            is_array($tracking['checkpoints'] ?? null) ? $tracking['checkpoints'] : [],
            'is_array',
        ));

        $firstSlug = $this->str($tracking['slug'] ?? null);
        $lastCheckpoint = $checkpoints === [] ? [] : $checkpoints[count($checkpoints) - 1];
        $lastMileSlug = $this->str($lastCheckpoint['slug'] ?? null);
        $handedOff = $lastMileSlug !== null && $lastMileSlug !== $firstSlug;

        $events = [];
        foreach ($checkpoints as $checkpoint) {
            $slug = $this->str($checkpoint['slug'] ?? null);
            $events[] = TrackingEvent::make(
                $checkpoint['checkpoint_time'] ?? null,
                $this->str($checkpoint['message'] ?? null),
                $this->str($checkpoint['location'] ?? $checkpoint['city'] ?? $checkpoint['country_name'] ?? null),
                $this->str($checkpoint['tag'] ?? null),
                lastMile: $handedOff && $slug === $lastMileSlug,
            );
        }
        usort($events, fn (TrackingEvent $a, TrackingEvent $b) => ($b->occurredAt?->timestamp ?? 0) <=> ($a->occurredAt?->timestamp ?? 0));

        $status = TrackingStatus::fromProvider($this->str($tracking['tag'] ?? null));
        $lastEventAt = TrackingEvent::parseDate($tracking['last_updated_at'] ?? null) ?? ($events[0]->occurredAt ?? null);

        return new TrackingResult(
            provider: $this->key(),
            status: $status,
            trackingNumber: $this->str($tracking['tracking_number'] ?? null) ?? $query->trackingNumber,
            statusRaw: $this->str($tracking['tag'] ?? null),
            // signed_by is the strongest proof signal any of these providers returns.
            subStatus: $this->str($tracking['signed_by'] ?? null) !== null
                ? 'Signed by '.$this->str($tracking['signed_by'] ?? null)
                : $this->str($tracking['subtag_message'] ?? null),
            carrierCode: $firstSlug,
            carrierName: $this->str($tracking['courier_name'] ?? $firstSlug),
            trackingUrl: $this->str($tracking['courier_tracking_link'] ?? null),
            lastMileCarrier: $handedOff ? $lastMileSlug : null,
            lastMileTrackingNumber: $handedOff ? $this->str($tracking['tracking_number'] ?? null) : null,
            lastEvent: $this->str($lastCheckpoint['message'] ?? null) ?? ($events[0]->description ?? null),
            lastEventAt: $lastEventAt,
            deliveredAt: TrackingEvent::parseDate($tracking['shipment_delivery_date'] ?? null)
                ?? ($status->isDelivered() ? $lastEventAt : null),
            estimatedDeliveryAt: $this->str($tracking['expected_delivery'] ?? null),
            events: array_slice($events, 0, 30),
            raw: $tracking,
        );
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders([
            'aftership-api-key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->timeout($this->timeout)->acceptJson();
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
