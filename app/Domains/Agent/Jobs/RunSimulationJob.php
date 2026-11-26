<?php

namespace App\Domains\Agent\Jobs;

use App\Domains\Agent\Models\Simulation;
use App\Domains\Agent\Simulation\SimulationRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * One short step of a simulation; re-dispatches itself while cases remain.
 * Batch simulations run on the long queue (agent.simulation.batch_*:
 * redis-long / ai-bulk, 900s); gates and playbook tests on `ai` (330s).
 * Never retried blind: a failed step marks the simulation failed.
 */
class RunSimulationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public readonly int $simulationId, public readonly string $kind)
    {
        if ($kind === Simulation::KIND_BATCH) {
            $this->onConnection((string) config('agent.simulation.batch_connection', 'redis-long'));
            $this->onQueue((string) config('agent.simulation.batch_queue', 'ai-bulk'));
            $this->timeout = 880;
        } else {
            if (filled(config('agent.simulation.gate_connection'))) {
                $this->onConnection((string) config('agent.simulation.gate_connection'));
            }
            $this->onQueue((string) config('agent.simulation.gate_queue', 'ai'));
            $this->timeout = 320;
        }
    }

    public static function for(Simulation $simulation): self
    {
        return new self($simulation->id, $simulation->kind);
    }

    public function handle(SimulationRunner $runner): void
    {
        $simulation = Simulation::find($this->simulationId);
        if ($simulation === null) {
            return;
        }

        if ($runner->step($simulation)) {
            dispatch(self::for($simulation));
        }
    }

    public function failed(Throwable $e): void
    {
        $simulation = Simulation::find($this->simulationId);
        if ($simulation !== null && ! $simulation->isFinished()) {
            app(SimulationRunner::class)->finish($simulation, Simulation::STATUS_FAILED, mb_substr(class_basename($e).': '.$e->getMessage(), 0, 1000));
        }
    }
}
