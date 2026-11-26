<?php

namespace App\Domains\Commerce\Services;

use App\Domains\Commerce\Models\CommerceSetting;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ShopifyClient
{
    /**
     * Oldest Admin API version the queries in this app are written for
     * (2026-10: Customer.email -> defaultEmailAddress, ShopPlan.displayName ->
     * publicDisplayName, refundCreate requires @idempotent). A stored older
     * version is raised to this; Shopify would otherwise "fall forward" to its
     * oldest supported version anyway.
     */
    public const MIN_API_VERSION = '2026-10';

    private const SHOP_QUERY = <<<'GQL'
    query ShopPing {
      shop {
        name
        myshopifyDomain
        email
        plan { publicDisplayName }
      }
    }
    GQL;

    public function __construct(
        private readonly CommerceSetting $settings,
    ) {}

    /**
     * Cheap Admin API round-trip used by the settings "Test connection" button.
     *
     * @return array{name: string, domain: string, email: ?string, plan: ?string}
     */
    public function ping(): array
    {
        $data = $this->graphql(self::SHOP_QUERY);
        $shop = is_array($data['shop'] ?? null) ? $data['shop'] : [];
        $name = trim((string) ($shop['name'] ?? ''));
        $domain = trim((string) ($shop['myshopifyDomain'] ?? ''));

        if ($name === '' && $domain === '') {
            throw new RuntimeException('Shopify responded but did not return shop details. Check Admin API scopes.');
        }

        $plan = is_array($shop['plan'] ?? null) ? ($shop['plan']['publicDisplayName'] ?? $shop['plan']['displayName'] ?? null) : null;

        return [
            'name' => $name !== '' ? $name : $domain,
            'domain' => $domain !== '' ? $domain : (string) $this->settings->shopDomain(),
            'email' => filled($shop['email'] ?? null) ? (string) $shop['email'] : null,
            'plan' => is_string($plan) && $plan !== '' ? $plan : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function graphql(string $query, array $variables = []): array
    {
        try {
            $response = $this->send($query, $variables);
            $response->throw();
        } catch (RequestException $e) {
            throw new RuntimeException($this->httpErrorMessage($e), previous: $e);
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];

        if (! empty($json['errors'])) {
            $message = is_array($json['errors'])
                ? collect($json['errors'])->pluck('message')->filter()->implode('; ')
                : 'Unknown GraphQL error';

            throw new RuntimeException('Shopify GraphQL error: '.$message);
        }

        /** @var array<string, mixed> $data */
        $data = $json['data'] ?? [];

        return $data;
    }

    /**
     * One POST to the Admin GraphQL endpoint, returning the raw response
     * without throwing on 4xx/5xx (callers classify it). A 401/403 refreshes
     * the token and is sent once more: Shopify rejected it before executing
     * anything. Connection errors and timeouts propagate as
     * Illuminate\Http\Client\ConnectionException.
     *
     * @param  array<string, mixed>  $variables
     */
    public function send(string $query, array $variables = [], bool $retried = false): Response
    {
        $domain = $this->settings->shopDomain();
        if (! $domain) {
            throw new RuntimeException('Shopify credentials are not configured.');
        }

        $token = app(ShopifyAccessTokenService::class)->tokenFor($this->settings);

        // Shopify rejects `variables: []`. An empty PHP array JSON-encodes as a
        // list, so omit the key when unused and send a JSON object otherwise.
        $payload = ['query' => $query];
        if ($variables !== []) {
            $payload['variables'] = (object) $variables;
        }

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $token,
            'Content-Type' => 'application/json',
        ])->timeout(20)->post($this->endpoint(), $payload);

        if (! $retried && in_array($response->status(), [401, 403], true)) {
            app(ShopifyAccessTokenService::class)->tokenFor($this->settings, force: true);

            return $this->send($query, $variables, retried: true);
        }

        return $response;
    }

    public function endpoint(): string
    {
        return 'https://'.$this->settings->shopDomain().'/admin/api/'.$this->apiVersion().'/graphql.json';
    }

    public function apiVersion(): string
    {
        $stored = trim((string) $this->settings->shopify_api_version);
        if ($stored === 'unstable') {
            return $stored;
        }

        $configured = (string) config('commerce.shopify_api_version', self::MIN_API_VERSION);
        $floor = strcmp($configured, self::MIN_API_VERSION) >= 0 ? $configured : self::MIN_API_VERSION;

        return preg_match('/^\d{4}-\d{2}$/', $stored) === 1 && strcmp($stored, $floor) > 0 ? $stored : $floor;
    }

    private function httpErrorMessage(RequestException $e): string
    {
        $status = $e->response->status();

        return match ($status) {
            401, 403 => 'Shopify rejected the access token after a refresh. Check the client ID, secret, and that the app is installed on this shop.',
            404 => 'Shopify shop not found. Check the shop domain (your-store.myshopify.com).',
            default => 'Shopify request failed: '.$e->getMessage(),
        };
    }
}
