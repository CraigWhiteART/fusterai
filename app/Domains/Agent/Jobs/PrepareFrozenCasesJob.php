<?php

namespace App\Domains\Agent\Jobs;

use App\Domains\Agent\Events\AgentLoopUpdated;
use App\Domains\Agent\Models\FrozenCase;
use App\Domains\Agent\Simulation\EvidenceReconstructor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The explicit, rate-limited "prepare cases" step: reconstruct as-of
 * evidence for frozen cases without recorded evidence. This, not a
 * simulation, is the only simulation-related code path that calls Shopify
 * or a carrier. Short steps on the long queue; re-dispatches itself (delayed
 * when the per-minute limit is reached) until no unprepared case is left.
 * Re-preparing specific cases = clear their evidence_prepared_at first.
 */
class PrepareFrozenCasesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 880;

    /** @param  list<int>|null  $caseIds  null = every unprepared case of the workspace */
    public function __construct(public readonly int $workspaceId, public readonly ?array $caseIds = null)
    {
        $this->onConnection((string) config('agent.simulation.batch_connection', 'redis-long'));
        $this->onQueue((string) config('agent.simulation.batch_queue', 'ai-bulk'));
    }

    public function handle(EvidenceReconstructor $reconstructor): void
    {
        $perMinute = max(1, (int) config('agent.simulation.prepare_per_minute', 30));
        $key = 'agent-prepare-cases:'.$this->workspaceId;
        $deadline = microtime(true) + (int) config('agent.simulation.batch_step_seconds', 600);
        $done = ['reconstructed' => 0, 'none' => 0, 'recorded' => 0];

        while (microtime(true) < $deadline) {
            $case = $this->next();
            if ($case === null) {
                AgentLoopUpdated::dispatchSafely($this->workspaceId, AgentLoopUpdated::CASES_PREPARED, ['finished' => true, ...$done]);

                return;
            }
            if (RateLimiter::tooManyAttempts($key, $perMinute)) {
                dispatch((new self($this->workspaceId, $this->caseIds))->delay(RateLimiter::availableIn($key) + 1));
                AgentLoopUpdated::dispatchSafely($this->workspaceId, AgentLoopUpdated::CASES_PREPARED, ['finished' => false, ...$done]);

                return;
            }
            RateLimiter::hit($key, 60);
            $quality = $reconstructor->prepare($case);
            $done[$quality] = ($done[$quality] ?? 0) + 1;
            if ($case->fresh()?->evidence_prepared_at === null) {
                // Attempted: never loop on the same case.
                $case->forceFill(['evidence_prepared_at' => now()])->save();
            }
        }

        dispatch(new self($this->workspaceId, $this->caseIds));
    }

    private function next(): ?FrozenCase
    {
        return FrozenCase::where('workspace_id', $this->workspaceId)
            ->where('evidence_quality', '!=', FrozenCase::QUALITY_RECORDED)
            ->whereNull('evidence_prepared_at')
            ->when($this->caseIds !== null, fn ($q) => $q->whereIn('id', $this->caseIds))
            ->orderBy('id')
            ->first();
    }
}
