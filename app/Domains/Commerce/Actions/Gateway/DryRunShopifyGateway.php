<?php

namespace App\Domains\Commerce\Actions\Gateway;

/**
 * Hard dry run for every non-live purpose. Holds no client, token or
 * settings, so it cannot reach HTTP; it only records what would have been
 * sent.
 */
final class DryRunShopifyGateway implements ShopifyGateway
{
    /** @var list<array{document: string, variables: array<string, mixed>}> */
    public array $recorded = [];

    public function query(string $document, array $variables = []): GatewayResult
    {
        return $this->record($document, $variables);
    }

    public function mutate(string $document, array $variables = []): GatewayResult
    {
        return $this->record($document, $variables);
    }

    public function isLive(): bool
    {
        return false;
    }

    /** @param  array<string, mixed>  $variables */
    private function record(string $document, array $variables): GatewayResult
    {
        $this->recorded[] = ['document' => $document, 'variables' => $variables];

        return new GatewayResult(GatewayResult::DRY_RUN, message: 'Dry run: nothing was sent to Shopify.', request: ['query' => $document, 'variables' => $variables, 'dry_run' => true]);
    }
}
