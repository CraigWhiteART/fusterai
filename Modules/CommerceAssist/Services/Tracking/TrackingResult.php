<?php

namespace Modules\CommerceAssist\Services\Tracking;

use Carbon\CarbonImmutable;

/**
 * The normalised shape every provider adapter returns. Nothing downstream —
 * prompt, scorer, UI — is allowed to know which vendor produced it.
 */
class TrackingResult
{
    /**
     * @param  list<TrackingEvent>  $events
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $provider,
        public readonly TrackingStatus $status,
        public readonly ?string $trackingNumber = null,
        public readonly ?string $statusRaw = null,
        public readonly ?string $subStatus = null,
        public readonly ?string $carrierCode = null,
        public readonly ?string $carrierName = null,
        public readonly ?string $trackingUrl = null,
        public readonly ?string $lastMileCarrier = null,
        public readonly ?string $lastMileTrackingNumber = null,
        public readonly ?string $lastMileTrackingUrl = null,
        public readonly ?string $lastEvent = null,
        public readonly ?CarbonImmutable $lastEventAt = null,
        public readonly ?CarbonImmutable $deliveredAt = null,
        public readonly ?string $estimatedDeliveryAt = null,
        public readonly array $events = [],
        public readonly array $raw = [],
    ) {}

    public static function notFound(string $provider, ?string $trackingNumber, string $reason): self
    {
        return new self(
            provider: $provider,
            status: TrackingStatus::NotFound,
            trackingNumber: $trackingNumber,
            statusRaw: $reason,
        );
    }

    public function found(): bool
    {
        return $this->status !== TrackingStatus::NotFound;
    }

    /**
     * Days since the carrier last scanned the parcel — the number customers are
     * actually asking about. Distinct from Shopify's fulfilment age, which only
     * says when the label was created.
     */
    public function daysSinceLastScan(): ?int
    {
        if ($this->lastEventAt === null) {
            return null;
        }

        // Clamped: a carrier clock running ahead should read as "just scanned",
        // not as a negative age that trips the stale check the wrong way.
        return max(0, (int) $this->lastEventAt->diffInDays(CarbonImmutable::now()));
    }

    /** In the network, but nothing has moved for longer than the configured threshold. */
    public function isStalled(int $staleDays): bool
    {
        if ($this->status->isTerminal() || $this->status->isUnknown()) {
            return false;
        }

        $days = $this->daysSinceLastScan();

        return $days !== null && $days >= $staleDays;
    }

    /** True once 4PX has handed off to a domestic carrier. */
    public function hasLastMileHandoff(): bool
    {
        return filled($this->lastMileCarrier) || filled($this->lastMileTrackingNumber);
    }

    /**
     * What the carrier recorded at the door. Null unless actually delivered —
     * we never speculate about proof for a parcel still in transit.
     */
    public function proof(): ?DeliveryProof
    {
        if (! $this->status->isDelivered()) {
            return null;
        }

        $deliveryEvent = null;
        foreach ($this->events as $event) {
            if ($event->description !== null) {
                $deliveryEvent = $event;
                break;
            }
        }

        return DeliveryProof::fromPhrases(
            [$this->subStatus, $this->lastEvent, $deliveryEvent?->description],
            $deliveryEvent?->location,
        );
    }

    /**
     * The handle Tier 2 needs: which carrier to ask for a signature or photo,
     * and under which number. Null when 4PX has not handed off yet.
     *
     * @return array{carrier: ?string, tracking_number: ?string, url: ?string}|null
     */
    public function lastMileHandle(): ?array
    {
        if (! $this->hasLastMileHandoff()) {
            return null;
        }

        return [
            'carrier' => $this->lastMileCarrier,
            'tracking_number' => $this->lastMileTrackingNumber,
            'url' => $this->lastMileTrackingUrl,
        ];
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'found' => $this->found(),
            'provider' => $this->provider,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_raw' => $this->statusRaw,
            'sub_status' => $this->subStatus,
            'tracking_number' => $this->trackingNumber,
            'tracking_url' => $this->trackingUrl,
            'carrier_code' => $this->carrierCode,
            'carrier_name' => $this->carrierName,
            'last_mile_carrier' => $this->lastMileCarrier,
            'last_mile_tracking_number' => $this->lastMileTrackingNumber,
            'last_mile_tracking_url' => $this->lastMileTrackingUrl,
            'last_mile_handoff' => $this->hasLastMileHandoff(),
            'last_event' => $this->lastEvent,
            'last_event_at' => $this->lastEventAt?->toIso8601String(),
            'days_since_last_scan' => $this->daysSinceLastScan(),
            'delivered_at' => $this->deliveredAt?->toIso8601String(),
            'estimated_delivery_at' => $this->estimatedDeliveryAt,
            'needs_attention' => $this->status->needsAttention(),
            'proof_of_delivery' => $this->proof()?->toArray(),
            'events' => array_map(fn (TrackingEvent $event) => $event->toArray(), $this->events),
        ];
    }
}
