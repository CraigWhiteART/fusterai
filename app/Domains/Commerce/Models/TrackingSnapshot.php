<?php

namespace App\Domains\Commerce\Models;

use App\Domains\Commerce\Services\Tracking\TrackingStatus;
use App\Domains\Conversation\Models\Conversation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A point-in-time carrier reading for one conversation, stored alongside the
 * Shopify snapshot so a generation can be replayed against exactly what the
 * carrier said at the time.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $conversation_id
 * @property string $provider
 * @property string|null $lookup_key
 * @property string|null $tracking_number
 * @property string|null $carrier_code
 * @property string|null $carrier_name
 * @property string|null $last_mile_carrier
 * @property string|null $last_mile_tracking_number
 * @property string|null $status
 * @property string|null $sub_status
 * @property Carbon|null $last_event_at
 * @property int|null $days_since_last_scan
 * @property Carbon|null $delivered_at
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $fetched_at
 * @property string|null $error
 */
class TrackingSnapshot extends Model
{
    protected $table = 'commerce_tracking_snapshots';

    protected $fillable = [
        'workspace_id',
        'conversation_id',
        'provider',
        'lookup_key',
        'tracking_number',
        'carrier_code',
        'carrier_name',
        'last_mile_carrier',
        'last_mile_tracking_number',
        'status',
        'sub_status',
        'last_event_at',
        'days_since_last_scan',
        'delivered_at',
        'payload',
        'fetched_at',
        'error',
    ];

    protected $casts = [
        'payload' => 'array',
        'last_event_at' => 'datetime',
        'delivered_at' => 'datetime',
        'fetched_at' => 'datetime',
        'days_since_last_scan' => 'integer',
    ];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function found(): bool
    {
        return (bool) ($this->payload['found'] ?? false);
    }

    public function trackingStatus(): TrackingStatus
    {
        return TrackingStatus::tryFrom((string) $this->status) ?? TrackingStatus::Unknown;
    }

    public function isDelivered(): bool
    {
        return $this->trackingStatus()->isDelivered();
    }

    public function needsAttention(): bool
    {
        return $this->trackingStatus()->needsAttention();
    }

    /** In the network but not scanned for longer than the workspace threshold. */
    public function isStalled(int $staleDays): bool
    {
        $status = $this->trackingStatus();

        if ($status->isTerminal() || $status->isUnknown()) {
            return false;
        }

        return $this->days_since_last_scan !== null && $this->days_since_last_scan >= $staleDays;
    }

    public function hasLastMileHandoff(): bool
    {
        return filled($this->last_mile_carrier) || filled($this->last_mile_tracking_number);
    }

    /** @return array<string, mixed>|null */
    public function proof(): ?array
    {
        $proof = $this->payload['proof_of_delivery'] ?? null;

        return is_array($proof) ? $proof : null;
    }

    /** True when the carrier already tells us who took the parcel. */
    public function proofIsAttributable(): bool
    {
        return (bool) ($this->proof()['attributable'] ?? false);
    }

    /**
     * Everything Tier 2 needs to chase a signature or photo with the domestic
     * carrier — or to hand a browser job a real starting point.
     *
     * @return array<string, mixed>|null
     */
    public function lastMileHandle(): ?array
    {
        if (! $this->hasLastMileHandoff()) {
            return null;
        }

        return [
            'carrier' => $this->last_mile_carrier,
            'tracking_number' => $this->last_mile_tracking_number,
            'url' => $this->payload['last_mile_tracking_url'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public function toUiArray(): array
    {
        return array_merge($this->payload ?? [], [
            'id' => $this->id,
            'fetched_at' => $this->fetched_at?->toIso8601String(),
            'error' => $this->error,
        ]);
    }
}
