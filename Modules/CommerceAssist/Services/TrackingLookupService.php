<?php

namespace Modules\CommerceAssist\Services;

use App\Domains\Conversation\Models\Conversation;
use Illuminate\Support\Facades\Log;
use Modules\CommerceAssist\Models\CommerceSetting;
use Modules\CommerceAssist\Models\ShopifySnapshot;
use Modules\CommerceAssist\Models\TrackingSnapshot;
use Modules\CommerceAssist\Services\Tracking\TrackingProviderFactory;
use Modules\CommerceAssist\Services\Tracking\TrackingQuery;
use Modules\CommerceAssist\Services\Tracking\TrackingResult;
use Throwable;

/**
 * Preemptive carrier lookup. Runs on every generation right after the Shopify
 * lookup, so a draft is written against what the carrier actually reports rather
 * than against a bare tracking number.
 *
 * Cheap by construction: it only fires when Shopify gave us something to look
 * up, and it reuses a recent snapshot instead of re-billing the provider when a
 * conversation is regenerated.
 */
class TrackingLookupService
{
    public function __construct(
        private readonly TrackingProviderFactory $providers,
    ) {}

    /**
     * Returns null when tracking is switched off, unconfigured, or there is no
     * key to look up. Null means "no carrier data" — never an error state.
     */
    public function lookup(Conversation $conversation, ShopifySnapshot $shopify, bool $force = false): ?TrackingSnapshot
    {
        $settings = CommerceSetting::forWorkspace($conversation->workspace_id);
        $provider = $this->providers->make($settings);

        if ($provider === null) {
            return null;
        }

        $query = TrackingQuery::fromShopify($shopify);
        if ($query->isEmpty()) {
            return null;
        }

        if (! $force) {
            $fresh = $this->freshSnapshot($conversation, $provider->key(), $query);
            if ($fresh !== null) {
                return $fresh;
            }
        }

        $snapshot = new TrackingSnapshot([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'provider' => $provider->key(),
            'lookup_key' => $query->cacheKey(),
            'tracking_number' => $query->trackingNumber,
        ]);

        try {
            $result = $provider->lookup($query);
        } catch (Throwable $e) {
            Log::warning('Commerce Assist tracking lookup failed', [
                'conversation_id' => $conversation->id,
                'provider' => $provider->key(),
                'error' => $e->getMessage(),
            ]);

            $snapshot->fill([
                'status' => 'unknown',
                'payload' => ['found' => false, 'reason' => 'Carrier lookup failed.'],
                'error' => $e->getMessage(),
                'fetched_at' => now(),
            ])->save();

            return $snapshot;
        }

        if ($result === null) {
            return null;
        }

        $this->fill($snapshot, $result)->save();

        return $snapshot;
    }

    /** The latest stored reading for a conversation, for UI and replay. */
    public function latestFor(Conversation $conversation): ?TrackingSnapshot
    {
        return TrackingSnapshot::where('conversation_id', $conversation->id)->latest('id')->first();
    }

    private function fill(TrackingSnapshot $snapshot, TrackingResult $result): TrackingSnapshot
    {
        $payload = $result->toPayload();

        $snapshot->fill([
            'tracking_number' => $result->trackingNumber ?? $snapshot->tracking_number,
            'carrier_code' => $result->carrierCode,
            'carrier_name' => $result->carrierName,
            'last_mile_carrier' => $result->lastMileCarrier,
            'last_mile_tracking_number' => $result->lastMileTrackingNumber,
            'status' => $result->status->value,
            'sub_status' => $result->subStatus,
            'last_event_at' => $result->lastEventAt,
            'days_since_last_scan' => $result->daysSinceLastScan(),
            'delivered_at' => $result->deliveredAt,
            'payload' => $payload,
            'fetched_at' => now(),
            'error' => null,
        ]);

        return $snapshot;
    }

    /**
     * Carrier scans move slowly, so a reading from the last TTL window is still
     * the truth. Matching on the lookup key means a newly fulfilled order (new
     * tracking number) forces a fresh call.
     */
    private function freshSnapshot(Conversation $conversation, string $providerKey, TrackingQuery $query): ?TrackingSnapshot
    {
        $ttl = (int) config('commerce-assist.tracking_ttl_minutes', 120);
        if ($ttl <= 0) {
            return null;
        }

        return TrackingSnapshot::where('conversation_id', $conversation->id)
            ->where('provider', $providerKey)
            ->where('lookup_key', $query->cacheKey())
            ->whereNull('error')
            ->where('fetched_at', '>=', now()->subMinutes($ttl))
            ->latest('id')
            ->first();
    }
}
