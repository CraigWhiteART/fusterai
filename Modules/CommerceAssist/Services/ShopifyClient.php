<?php

namespace Modules\CommerceAssist\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Modules\CommerceAssist\Models\CommerceSetting;
use RuntimeException;

class ShopifyClient
{
    private const SHOP_QUERY = <<<'GQL'
    query ShopPing {
      shop {
        name
        myshopifyDomain
        email
        plan { displayName }
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

        $plan = is_array($shop['plan'] ?? null) ? ($shop['plan']['displayName'] ?? null) : null;

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
        return $this->requestGraphql($query, $variables, retried: false);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function requestGraphql(string $query, array $variables, bool $retried): array
    {
        $domain = $this->settings->shopDomain();
        if (! $domain) {
            throw new RuntimeException('Shopify credentials are not configured.');
        }

        $token = app(ShopifyAccessTokenService::class)->tokenFor($this->settings);

        $version = $this->settings->shopify_api_version ?: '2025-01';
        $url = "https://{$domain}/admin/api/{$version}/graphql.json";

        // Shopify rejects `variables: []`. An empty PHP array JSON-encodes as a
        // list, so omit the key when unused and send a JSON object otherwise.
        $payload = ['query' => $query];
        if ($variables !== []) {
            $payload['variables'] = (object) $variables;
        }

        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $token,
                'Content-Type' => 'application/json',
            ])->timeout(20)->post($url, $payload);

            $response->throw();
        } catch (RequestException $e) {
            $status = $e->response?->status();
            if (! $retried && ($status === 401 || $status === 403)) {
                app(ShopifyAccessTokenService::class)->tokenFor($this->settings, force: true);

                return $this->requestGraphql($query, $variables, retried: true);
            }

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

    private function httpErrorMessage(RequestException $e): string
    {
        $status = $e->response?->status();

        return match ($status) {
            401, 403 => 'Shopify rejected the access token after a refresh. Check the client ID, secret, and that the app is installed on this shop.',
            404 => 'Shopify shop not found. Check the shop domain (your-store.myshopify.com).',
            default => 'Shopify request failed: '.$e->getMessage(),
        };
    }
}
