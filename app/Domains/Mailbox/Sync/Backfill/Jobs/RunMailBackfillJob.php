<?php

namespace App\Domains\Mailbox\Sync\Backfill\Jobs;

use App\Domains\Mailbox\Sync\Backfill\BackfillRunner;
use App\Domains\Mailbox\Sync\Backfill\BackfillService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * One step of a history backfill: a couple of minutes of checkpointed work
 * (BackfillRunner::runStep()), then the job queues its successor. Runs on the
 * ai-bulk queue of the redis-long connection (Horizon: timeout 900s, tries 1),
 * so no single job comes near the limit and a killed worker only loses the
 * slice in flight.
 *
 * Steps of one run never overlap (cache lock per run); a step whose chain
 * was superseded by a resume or the watchdog (different job_token) exits.
 */
class RunMailBackfillJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** Below the ai-bulk supervisor's 900s; a step normally takes step_seconds (120s). */
    public int $timeout = 840;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $runId,
        public readonly string $token,
    ) {
        $this->onConnection((string) config('mailsync.backfill.connection', 'redis-long'));
        $this->onQueue((string) config('mailsync.backfill.queue', 'ai-bulk'));
    }

    public function handle(BackfillRunner $runner, BackfillService $service): void
    {
        $lock = Cache::lock('mail-backfill:run:'.$this->runId, 900);
        if (! $lock->get()) {
            // A step of this run is still going (or its worker died holding
            // the lock): try again shortly; the token check drops duplicates.
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

    /** Timeout or killed worker: record it and carry on from the last checkpoint after a backoff. */
    public function failed(?Throwable $e): void
    {
        $delay = app(BackfillRunner::class)->recordFailure($this->runId, $e ?? new \RuntimeException('Backfill step failed'), $this->token);
        if ($delay !== null) {
            app(BackfillService::class)->dispatchNext($this->runId, $this->token, $delay);
        }
    }
}
