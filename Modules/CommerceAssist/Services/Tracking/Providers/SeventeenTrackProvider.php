<?php

namespace Modules\CommerceAssist\Services\Tracking\Providers;

use Illuminate\Support\Facades\Http;
use Modules\CommerceAssist\Services\Tracking\TrackingEvent;
use Modules\CommerceAssist\Services\Tracking\TrackingProvider;
use Modules\CommerceAssist\Services\Tracking\TrackingQuery;
use Modules\CommerceAssist\Services\Tracking\TrackingResult;
use Modules\CommerceAssist\Services\Tracking\TrackingStatus;

/**
 * 17TRACK v2.2.
 *
 * Register-then-poll: a number is unknown until it has been registered, and the
 * first poll after registration usually returns nothing. We register on miss and
 * report Pending, so the next generation picks up real data.
 *
 * Endpoint shapes follow 17TRACK's published v2.2 API. Confirm against a live
 * response before enabling this provider in production.
 */
class SeventeenTrackProvider implements TrackingProvider
{
    private const BASE_URL = 'https://api.17track.net/track/v2.2';

    public function __construct(
        private readonly string $apiKey,
        private readonly int $timeout = 15,
    ) {}

    public function key(): string
    {
        return '17track';
    }

    public function lookup(TrackingQuery $query): ?TrackingResult
    {
        if ($this->apiKey === '' || $query->trackingNumber === null) {
            return null;
        }

        $accepted = $this->post('/gettrackinfo', [['number' => $query->trackingNumber]]);
        $info = $accepted[0]['track_info'] ?? null;

        if (! is_array($info) || ($info['latest_status'] ?? null) === null) {
            $this->post('/register', [array_filter([
                'number' => $query->trackingNumber,
                'carrier' => $query->carrierCode !== null ? (int) $query->carrierCode : null,
            ], fn ($value) => $value !== null)]);

            return TrackingResult::notFound(
                $this->key(),
                $query->trackingNumber,
                'Registered with 17TRACK; no carrier scans returned yet.',
            );
        }

        return $this->map($info, $query);
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function map(array $info, TrackingQuery $query): TrackingResult
    {
        $latestStatus = is_array($info['latest_status'] ?? null) ? $info['latest_status'] : [];
        $latestEvent = is_array($info['latest_event'] ?? null) ? $info['latest_event'] : [];
        $providers = array_values(array_filter(
            is_array($info['tracking']['providers'] ?? null) ? $info['tracking']['providers'] : [],
            'is_array',
        ));

        // With a handoff there are two legs; the last one is the domestic carrier.
        $lastLeg = count($providers) > 1 ? end($providers) : null;
        $firstLeg = $providers[0] ?? null;

        $events = [];
        foreach ($providers as $index => $leg) {
            $isLastMile = count($providers) > 1 && $index === count($providers) - 1;
            foreach (is_array($leg['events'] ?? null) ? $leg['events'] : [] as $event) {
                if (! is_array($event)) {
                    continue;
                }
                $events[] = TrackingEvent::make(
                    $event['time_iso'] ?? $event['time_utc'] ?? null,
                    $this->str($event['description'] ?? null),
                    $this->str($event['location'] ?? null),
                    $this->str($event['stage'] ?? null),
                    lastMile: $isLastMile,
                );
            }
        }
        usort($events, fn (TrackingEvent $a, TrackingEvent $b) => ($b->occurredAt?->timestamp ?? 0) <=> ($a->occurredAt?->timestamp ?? 0));

        $status = TrackingStatus::fromProvider($this->str($latestStatus['status'] ?? null));
        $lastEventAt = TrackingEvent::parseDate($latestEvent['time_iso'] ?? null) ?? ($events[0]->occurredAt ?? null);

        return new TrackingResult(
            provider: $this->key(),
            status: $status,
            trackingNumber: $this->str($info['number'] ?? null) ?? $query->trackingNumber,
            statusRaw: $this->str($latestStatus['status'] ?? null),
            subStatus: $this->str($latestStatus['sub_status_descr'] ?? $latestStatus['sub_status'] ?? null),
            carrierCode: $this->str($firstLeg['provider']['key'] ?? null),
            carrierName: $this->str($firstLeg['provider']['name'] ?? null),
            trackingUrl: $this->str($firstLeg['provider']['homepage'] ?? null),
            lastMileCarrier: $this->str($lastLeg['provider']['name'] ?? null),
            lastMileTrackingNumber: $this->str($lastLeg['provider']['tracking_number'] ?? null),
            lastMileTrackingUrl: $this->str($lastLeg['provider']['homepage'] ?? null),
            lastEvent: $this->str($latestEvent['description'] ?? null) ?? ($events[0]->description ?? null),
            lastEventAt: $lastEventAt,
            deliveredAt: $status->isDelivered() ? $lastEventAt : null,
            estimatedDeliveryAt: $this->str($info['time_metrics']['estimated_delivery_date']['from'] ?? null),
            events: array_slice($events, 0, 30),
            raw: $info,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $body
     * @return list<array<string, mixed>>
     */
    private function post(string $endpoint, array $body): array
    {
        $response = Http::withHeaders([
            '17token' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->timeout($this->timeout)->post(self::BASE_URL.$endpoint, $body);

        $response->throw();

        $accepted = $response->json('data.accepted');

        return is_array($accepted) ? array_values(array_filter($accepted, 'is_array')) : [];
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
