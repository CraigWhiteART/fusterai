<?php

namespace App\Domains\Agent\Mining\Jobs;

use App\Domains\Agent\Mining\MiningRunner;
use App\Domains\Agent\Mining\MiningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * One step of a mining run (MiningRunner::runStep(): about two minutes of
 * checkpointed work), then the job queues its successor. Runs on the ai-bulk
 * queue of the redis-long connection (timeout 900 s, tries 1). Steps of one
 * run never overlap (cache lock); a step whose chain was superseded by a
 * resume or the watchdog (different job_token) exits.
 */
class RunMiningStepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** Below the ai-bulk supervisor's 900 s; a step normally takes step_seconds plus one model call. */
    public int $timeout = 840;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $runId,
        public readonly string $token,
    ) {
        $this->onConnection((string) config('agent_mining.queue.connection', 'redis-long'));
        $this->onQueue((string) config('agent_mining.queue.queue', 'ai-bulk'));
    }

    public function handle(MiningRunner $runner, MiningService $service): void
    {
        $lock = Cache::lock('agent-mining:run:'.$this->runId, 900);
        if (! $lock->get()) {
            $service->dispatchNext($this->runId, $this->token, 60);

            return;
        }

        try {
            $delay = $runner->runStep($this->runId, $this->token);
        } finally {
            $lock->release();
        }

        if ($delay !== null) {
            $service->dispatchNext($this->runId, $this->token, $delay);
        }
    }

    public function failed(?Throwable $e): void
    {
        $delay = app(MiningRunner::class)->recordFailure($this->runId, $e ?? new \RuntimeException('Mining step failed'), $this->token);
        if ($delay !== null) {
            app(MiningService::class)->dispatchNext($this->runId, $this->token, $delay);
        }
    }
}
