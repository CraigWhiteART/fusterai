<?php

namespace App\Domains\Commerce\Actions\Gateway;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Commerce\Actions\Models\ActionExecution;
use App\Domains\Commerce\Models\CommerceSetting;
use App\Domains\Commerce\Services\ShopifyClient;

/**
 * The single place a live gateway is built. Anything that is not a live
 * execution of a live run (preview / simulation / shadow / test, or an
 * execution flagged dry_run) gets the dry-run gateway, which holds no HTTP
 * client. Tests assert Http::assertNothingSent() for those purposes.
 */
class ShopifyGatewayFactory
{
    public function forExecution(ActionExecution $execution): ShopifyGateway
    {
        $runPurpose = $execution->run_id !== null
            ? AgentRun::whereKey($execution->run_id)->value('purpose')
            : AgentRun::PURPOSE_LIVE;

        $live = ! $execution->dry_run
            && $execution->purpose === AgentRun::PURPOSE_LIVE
            && $runPurpose === AgentRun::PURPOSE_LIVE;

        return $live ? $this->live($execution->workspace_id) : new DryRunShopifyGateway;
    }

    public function forRun(AgentRun $run): ShopifyGateway
    {
        return $run->purpose === AgentRun::PURPOSE_LIVE ? $this->live($run->workspace_id) : new DryRunShopifyGateway;
    }

    private function live(int $workspaceId): ShopifyGateway
    {
        $settings = CommerceSetting::forWorkspace($workspaceId);

        return $settings->hasShopifyCredentials()
            ? new LiveShopifyGateway(new ShopifyClient($settings))
            : new DryRunShopifyGateway;
    }
}
