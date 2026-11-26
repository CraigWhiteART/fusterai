<?php

namespace App\Domains\Commerce\Actions\Gateway;

/**
 * The only way an action reaches Shopify. Built by ShopifyGatewayFactory:
 * live executions get LiveShopifyGateway, everything else (preview,
 * simulation, shadow, test runs) gets DryRunShopifyGateway, which has no
 * HTTP client at all.
 */
interface ShopifyGateway
{
    /** @param  array<string, mixed>  $variables */
    public function query(string $document, array $variables = []): GatewayResult;

    /** @param  array<string, mixed>  $variables */
    public function mutate(string $document, array $variables = []): GatewayResult;

    public function isLive(): bool;
}
