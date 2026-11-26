<?php

namespace App\Domains\Commerce\Actions\Jobs;

use App\Domains\Commerce\Actions\ActionExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Runs one approved execution. tries = 1: a mutation is never retried by
 * the queue. Double dispatch is harmless: ActionExecutor claims the row
 * (approved -> executing) before any request and the loser exits.
 */
class ExecuteActionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $executionId)
    {
        $this->onQueue((string) config('agent_actions.queue', 'default'));
    }

    public function handle(ActionExecutor $executor): void
    {
        $executor->execute($this->executionId);
    }
}
