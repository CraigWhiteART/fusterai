<?php

namespace Modules\CommerceAssist\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Modules\CommerceAssist\Models\CommerceSetting;
use RuntimeException;

/**
 * Exchanges Dev Dashboard client credentials for a 24-hour Admin API token
 * and keeps one cached per workspace. Shopify no longer issues a pasteable
 * shpat_ token for new apps; this is the replacement.
 */
class ShopifyAccessTokenService
{
    /** Refresh this far before expiry so a lookup never presents a token that dies mid-request. */
    public const DEFAULT_BUFFER_MINUTES = 5;

    /** The hourly scheduler refreshes when less than this remains. */
    public const SCHEDULE_BUFFER_MINUTES = 120;

    public function tokenFor(CommerceSetting $settings, bool $force = false): string
    {
        if (! $force && $this->isFresh($settings)) {
            $token = $settings->decryptAccessToken();
            if ($token !== null) {
                return $token;
            }
        }

        return $this->refresh($settings);
    }

    public function isFresh(CommerceSetting $settings, int $bufferMinutes = self::DEFAULT_BUFFER_MINUTES): bool
    {
        $expires = $settings->shopify_access_token_expires_at;
        if ($expires === null || blank($settings->shopify_access_token)) {
            return false;
        }

        return $expires->gt(now()->addMinutes($bufferMinutes));
    }

    public function refresh(CommerceSetting $settings): string
    {
        $domain = $settings->shopDomain();
        $clientId = trim((string) $settings->shopify_client_id);
        $secret = $settings->decryptClientSecret();

        if (! $domain || $clientId === '' || $secret === null) {
            throw new RuntimeException('Shopify client credentials are not configured.');
        }

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->timeout(20)
                ->post("https://{$domain}/admin/oauth/access_token", [
                    'client_id' => $clientId,
                    'client_secret' => $secret,
                    'grant_type' => 'client_credentials',
                ]);

            $response->throw();
        } catch (RequestException $e) {
            throw new RuntimeException($this->tokenErrorMessage($e), previous: $e);
        }

        $token = trim((string) ($response->json('access_token') ?? ''));
        if ($token === '') {
            throw new RuntimeException('Shopify did not return an access token. Check the client ID, secret, and shop domain.');
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 86399);
        $scopes = $response->json('scope');

        $settings->forceFill([
            'shopify_access_token' => Crypt::encryptString($token),
            'shopify_access_token_expires_at' => now()->addSeconds(max(60, $expiresIn)),
            'shopify_granted_scopes' => is_string($scopes) && $scopes !== '' ? $scopes : null,
        ])->save();

        return $token;
    }

    private function tokenErrorMessage(RequestException $e): string
    {
        $status = $e->response?->status();
        $body = (string) ($e->response?->body() ?? '');
        $json = $e->response?->json();
        $description = is_array($json)
            ? trim((string) ($json['error_description'] ?? $json['error'] ?? ''))
            : '';

        if (str_contains($body, 'shop_not_permitted')) {
            return 'Shopify refused client credentials for this shop. The app and the store must belong to the same organisation, and the app must be installed on the store.';
        }

        return match ($status) {
            401, 403 => 'Shopify rejected the client ID or secret'.($description !== '' ? ': '.$description : '.'),
            404 => 'Shopify shop not found. Check the shop domain (your-store.myshopify.com).',
            default => 'Shopify token request failed: '.($description !== '' ? $description : $e->getMessage()),
        };
    }
}
