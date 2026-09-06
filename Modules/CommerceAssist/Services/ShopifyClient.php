<?php

namespace Modules\CommerceAssist\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Modules\CommerceAssist\Models\CommerceSetting;
use RuntimeException;

class ShopifyClient
{
    public function __construct(
        private readonly CommerceSetting $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function graphql(string $query, array $variables = []): array
    {
        $domain = $this->settings->shopDomain();
        $token = $this->settings->decryptAccessToken();

        if (! $domain || ! $token) {
            throw new RuntimeException('Shopify credentials are not configured.');
        }

        $version = $this->settings->shopify_api_version ?: '2025-01';
        $url = "https://{$domain}/admin/api/{$version}/graphql.json";

        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $token,
                'Content-Type' => 'application/json',
            ])->timeout(20)->post($url, [
                'query' => $query,
                'variables' => $variables,
            ]);

            $response->throw();
        } catch (RequestException $e) {
            throw new RuntimeException('Shopify request failed: '.$e->getMessage(), previous: $e);
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
}
