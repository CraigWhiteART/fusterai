<?php

namespace Modules\CommerceAssist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\CommerceAssist\Models\CommerceSetting;
use Modules\CommerceAssist\Services\ShopifyAccessTokenService;
use Throwable;

class RefreshShopifyTokensCommand extends Command
{
    protected $signature = 'commerce-assist:refresh-shopify-tokens';

    protected $description = 'Refresh Shopify client-credentials access tokens that are missing or near expiry';

    public function handle(ShopifyAccessTokenService $tokens): int
    {
        $settings = CommerceSetting::query()
            ->whereNotNull('shopify_client_id')
            ->whereNotNull('shopify_client_secret')
            ->get();

        $refreshed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($settings as $row) {
            if (! $row->hasShopifyCredentials()) {
                $skipped++;

                continue;
            }

            if ($tokens->isFresh($row, ShopifyAccessTokenService::SCHEDULE_BUFFER_MINUTES)) {
                $skipped++;

                continue;
            }

            try {
                $tokens->refresh($row);
                $refreshed++;
                $this->info("Refreshed Shopify token for workspace {$row->workspace_id}.");
            } catch (Throwable $e) {
                $failed++;
                $this->error("Workspace {$row->workspace_id}: {$e->getMessage()}");
                Log::warning('commerce-assist: Shopify token refresh failed', [
                    'workspace_id' => $row->workspace_id,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Shopify tokens: {$refreshed} refreshed, {$skipped} skipped, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
