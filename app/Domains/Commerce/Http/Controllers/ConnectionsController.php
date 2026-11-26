<?php

namespace App\Domains\Commerce\Http\Controllers;

use App\Domains\Commerce\Http\Requests\UpdateConnectionsRequest;
use App\Domains\Commerce\Models\CommerceSetting;
use App\Domains\Commerce\Services\ShopifyAccessTokenService;
use App\Domains\Commerce\Services\ShopifyClient;
use App\Domains\Commerce\Services\Tracking\TrackingProviderFactory;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * /agent/connections: Shopify client credentials and carrier tracking for the
 * agent pipeline (replaces the Commerce Assist module settings screen).
 *
 * Stored secrets never reach the browser: the page gets `has_*` booleans and a
 * blank field keeps the current value. The page never tests a connection by
 * itself (each Shopify test mints a new access token); the admin clicks Test.
 */
class ConnectionsController extends Controller
{
    public function show(Request $request): Response
    {
        $this->authorize('manage-settings');

        $settings = CommerceSetting::forWorkspace((int) $request->user()->workspace_id);

        return Inertia::render('Agent/Connections', [
            'providers' => TrackingProviderFactory::PROVIDERS,
            'settings' => self::present($settings),
        ]);
    }

    public function update(UpdateConnectionsRequest $request): RedirectResponse
    {
        $settings = CommerceSetting::forWorkspace((int) $request->user()->workspace_id);
        $data = $request->validated();

        // An omitted field keeps the current provider: a partial save must never
        // silently switch carrier tracking off.
        $provider = $data['tracking_provider'] ?? $settings->tracking_provider ?? TrackingProviderFactory::NONE;
        $domain = self::normaliseDomain($data['shopify_shop_domain'] ?? null);
        $clientId = trim((string) ($data['shopify_client_id'] ?? ''));

        $payload = [
            'shopify_shop_domain' => $domain,
            'shopify_client_id' => $clientId !== '' ? $clientId : $settings->shopify_client_id,
            'shopify_api_version' => filled($data['shopify_api_version'] ?? null)
                ? (string) $data['shopify_api_version']
                : (string) config('commerce.shopify_api_version', '2026-10'),
            'tracking_provider' => $provider,
            'tracking_store_uuid' => filled($data['tracking_store_uuid'] ?? null) ? trim((string) $data['tracking_store_uuid']) : null,
            'tracking_stale_days' => (int) $data['tracking_stale_days'],
            'preorder_tags' => array_values(array_unique(array_filter(array_map(
                fn (string $tag) => strtolower(trim($tag)),
                explode(',', (string) ($data['preorder_tags'] ?? '')),
            )))),
        ];

        $secretPasted = filled($data['shopify_client_secret'] ?? null);
        $clientIdChanged = $clientId !== '' && $clientId !== trim((string) $settings->shopify_client_id);
        $domainChanged = $domain !== $settings->shopify_shop_domain;

        // New credentials (or another shop) make the cached token meaningless.
        if ($secretPasted || $clientIdChanged || $domainChanged) {
            $payload['shopify_access_token'] = null;
            $payload['shopify_access_token_expires_at'] = null;
            $payload['shopify_granted_scopes'] = null;
        }
        if ($secretPasted) {
            $payload['shopify_client_secret'] = Crypt::encryptString((string) $data['shopify_client_secret']);
        }

        if (filled($data['tracking_api_key'] ?? null)) {
            $payload['tracking_api_key'] = Crypt::encryptString((string) $data['tracking_api_key']);
        }
        // Switching the provider off clears the stored key rather than leaving a
        // credential for a source we no longer call.
        if ($provider === TrackingProviderFactory::NONE) {
            $payload['tracking_api_key'] = null;
        }

        $settings->update($payload);
        $settings->refresh();

        if (($secretPasted || $clientIdChanged || $domainChanged) && $settings->hasShopifyCredentials()) {
            try {
                app(ShopifyAccessTokenService::class)->refresh($settings);
            } catch (Throwable $e) {
                return back()->with('error', 'Connections saved, but Shopify did not issue a token: '.$e->getMessage());
            }

            return back()->with('success', 'Connections saved. Shopify issued a new access token.');
        }

        return back()->with('success', 'Connections saved.');
    }

    public function testShopify(Request $request): JsonResponse
    {
        $this->authorize('manage-settings');

        $settings = CommerceSetting::forWorkspace((int) $request->user()->workspace_id);

        if (! $settings->hasShopifyCredentials()) {
            return response()->json([
                'ok' => false,
                'message' => 'Save a shop domain, client ID, and client secret first.',
            ]);
        }

        try {
            app(ShopifyAccessTokenService::class)->refresh($settings);
            $shop = (new ShopifyClient($settings))->ping();
            $label = $shop['name'];
            if ($shop['domain'] !== '' && strcasecmp($shop['domain'], $shop['name']) !== 0) {
                $label .= ' ('.$shop['domain'].')';
            }

            $scopes = $settings->shopify_granted_scopes;

            return response()->json([
                'ok' => true,
                'message' => 'Connected to '.$label.'.',
                'shop' => $shop,
                'scopes' => self::scopeList($scopes),
                'expires_at' => $settings->shopify_access_token_expires_at?->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function testTracking(Request $request): JsonResponse
    {
        $this->authorize('manage-settings');

        $settings = CommerceSetting::forWorkspace((int) $request->user()->workspace_id);
        $providerKey = $settings->tracking_provider ?: TrackingProviderFactory::NONE;

        if ($providerKey === TrackingProviderFactory::NONE) {
            return response()->json([
                'ok' => false,
                'message' => 'Carrier tracking is switched off. Choose a provider and save an API key first.',
            ]);
        }

        if (! $settings->trackingEnabled()) {
            return response()->json([
                'ok' => false,
                'message' => 'Save a tracking API key first.',
            ]);
        }

        $provider = app(TrackingProviderFactory::class)->make($settings);
        if ($provider === null) {
            return response()->json([
                'ok' => false,
                'message' => 'Tracking provider could not be created. Check the API key and store subdomain.',
            ]);
        }

        try {
            $result = $provider->ping();

            return response()->json([
                'ok' => true,
                'message' => $result['message'],
                'provider' => $provider->key(),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * What the page may see. Never the client secret, access token or tracking key.
     *
     * @return array<string, mixed>
     */
    public static function present(CommerceSetting $settings): array
    {
        return [
            'shopify_shop_domain' => $settings->shopify_shop_domain,
            'shopify_resolved_domain' => $settings->shopDomain(),
            'shopify_client_id' => $settings->shopify_client_id,
            'has_shopify_client_secret' => $settings->secretIsSet(),
            'shopify_configured' => $settings->hasShopifyCredentials(),
            'shopify_token_expires_at' => $settings->shopify_access_token_expires_at?->toIso8601String(),
            'shopify_granted_scopes' => self::scopeList($settings->shopify_granted_scopes),
            'shopify_api_version' => $settings->shopify_api_version ?: (string) config('commerce.shopify_api_version', '2026-10'),
            'tracking_provider' => $settings->tracking_provider ?: TrackingProviderFactory::NONE,
            'has_tracking_api_key' => $settings->trackingKeyIsSet(),
            'tracking_configured' => $settings->trackingEnabled(),
            'tracking_store_uuid' => $settings->tracking_store_uuid,
            'tracking_store_uuid_default' => $settings->trackingStoreUuid(),
            'tracking_stale_days' => $settings->tracking_stale_days ?: (int) config('commerce.tracking_stale_days', 14),
            'preorder_tags' => implode(', ', $settings->preorderTagList()),
        ];
    }

    /** @return list<string> */
    private static function scopeList(?string $scopes): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $scopes))));
    }

    private static function normaliseDomain(mixed $value): ?string
    {
        $domain = strtolower(trim((string) $value));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = rtrim($domain, '/');

        return $domain !== '' ? $domain : null;
    }
}
