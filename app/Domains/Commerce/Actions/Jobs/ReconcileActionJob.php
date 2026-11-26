<?php

namespace App\Domains\Commerce\Actions\Jobs;

use App\Domains\Commerce\Actions\ActionExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Decides an `unknown` execution by re-querying Shopify (read-only). Never
 * re-sends the mutation. Re-dispatches itself with a delay until it
 * decides or runs out of attempts.
 */
class ReconcileActionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $executionId)
    {
        $this->onQueue((string) config('agent_actions.queue', 'default'));
    }

    public function handle(ActionExecutor $executor): void
    {
        $executor->reconcile($this->executionId);
    }
}
