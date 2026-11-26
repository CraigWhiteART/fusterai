<?php

namespace App\Domains\Commerce\Actions\Gateway;

use App\Domains\Commerce\Actions\Support\Redactor;
use App\Domains\Commerce\Services\ShopifyClient;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Sends exactly one request per call through ShopifyClient::send (which only
 * re-sends after a 401/403 token refresh, i.e. when Shopify executed
 * nothing). Never retries a mutation; classifies the outcome instead.
 */
final class LiveShopifyGateway implements ShopifyGateway
{
    public function __construct(private readonly ShopifyClient $client) {}

    public function query(string $document, array $variables = []): GatewayResult
    {
        return $this->call($document, $variables);
    }

    public function mutate(string $document, array $variables = []): GatewayResult
    {
        return $this->call($document, $variables);
    }

    public function isLive(): bool
    {
        return true;
    }

    /** @param  array<string, mixed>  $variables */
    private function call(string $document, array $variables): GatewayResult
    {
        $request = Redactor::redact(['query' => $document, 'variables' => $variables, 'api_version' => $this->client->apiVersion()]);

        try {
            $response = $this->client->send($document, $variables);
        } catch (ConnectionException $e) {
            return new GatewayResult(GatewayResult::UNKNOWN, message: 'Connection to Shopify failed or timed out: '.Redactor::string($e->getMessage()), request: $request);
        } catch (Throwable $e) {
            // Token exchange / configuration failures happen before the request is sent.
            return new GatewayResult(GatewayResult::REJECTED, message: Redactor::string($e->getMessage()), request: $request);
        }

        $status = $response->status();
        $json = $response->json();
        $body = is_array($json) ? Redactor::redact($json) : ['raw' => mb_substr(Redactor::string((string) $response->body()), 0, 2000)];
        $stored = ['status' => $status, 'body' => $body];

        if ($status >= 500) {
            return new GatewayResult(GatewayResult::UNKNOWN, status: $status, message: "Shopify returned HTTP {$status}; the outcome is unknown.", request: $request, response: $stored);
        }
        if ($status >= 400) {
            return new GatewayResult(GatewayResult::REJECTED, status: $status, message: "Shopify rejected the request (HTTP {$status}).", request: $request, response: $stored);
        }
        if (! is_array($json)) {
            return new GatewayResult(GatewayResult::UNKNOWN, status: $status, message: 'Shopify returned an unreadable response; the outcome is unknown.', request: $request, response: $stored);
        }

        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $errors = array_values(array_filter((array) ($json['errors'] ?? []), 'is_array'));

        if ($errors !== []) {
            $hasData = array_filter($data, fn ($v) => $v !== null) !== [];

            // Errors with no data (validation, THROTTLED, access denied): nothing ran.
            return new GatewayResult($hasData ? GatewayResult::UNKNOWN : GatewayResult::REJECTED, $data, $errors, $status, null, $request, $stored);
        }

        return new GatewayResult(GatewayResult::OK, $data, [], $status, null, $request, $stored);
    }
}
