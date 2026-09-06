<?php

namespace Modules\CommerceAssist\Services\Tracking;

use Modules\CommerceAssist\Models\CommerceSetting;
use Modules\CommerceAssist\Services\Tracking\Providers\AfterShipProvider;
use Modules\CommerceAssist\Services\Tracking\Providers\SeventeenTrackProvider;
use Modules\CommerceAssist\Services\Tracking\Providers\Track123Provider;

/**
 * Resolves the workspace's selected carrier-tracking provider. Returns null when
 * tracking is switched off or unconfigured — callers treat that as "no carrier
 * data", never as an error.
 */
class TrackingProviderFactory
{
    public const NONE = 'none';

    /** @var array<string, string> */
    public const PROVIDERS = [
        self::NONE => 'None',
        'track123' => 'Track123 (Shopify app)',
        '17track' => '17TRACK',
        'aftership' => 'AfterShip',
    ];

    /** @var array<string, TrackingProvider> */
    private array $overrides = [];

    /** Swap in a fake provider (tests, or a future first-party carrier client). */
    public function extend(string $key, TrackingProvider $provider): void
    {
        $this->overrides[$key] = $provider;
    }

    public function make(CommerceSetting $settings): ?TrackingProvider
    {
        $key = $settings->tracking_provider ?: self::NONE;

        if (isset($this->overrides[$key])) {
            return $this->overrides[$key];
        }

        if ($key === self::NONE) {
            return null;
        }

        $apiKey = (string) $settings->decryptTrackingApiKey();
        if ($apiKey === '') {
            return null;
        }

        $timeout = (int) config('commerce-assist.tracking_timeout', 15);

        return match ($key) {
            'track123' => new Track123Provider($apiKey, $settings->trackingStoreUuid() ?? '', $timeout),
            '17track' => new SeventeenTrackProvider($apiKey, $timeout),
            'aftership' => new AfterShipProvider($apiKey, $timeout),
            default => null,
        };
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::PROVIDERS);
    }
}
