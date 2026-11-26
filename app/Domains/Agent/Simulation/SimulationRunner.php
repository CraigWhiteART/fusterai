<?php

namespace App\Domains\Agent\Simulation;

use App\Domains\Agent\AutoSend\AutoModeGuard;
use App\Domains\Agent\Events\AgentLoopUpdated;
use App\Domains\Agent\Models\AgentTestCase;
use App\Domains\Agent\Models\FrozenCase;
use App\Domains\Agent\Models\Proposal;
use App\Domains\Agent\Models\Simulation;
use App\Domains\Agent\Models\SimulationResult;
use App\Domains\Agent\Pipeline\InstructionSet;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Executes a simulation in short steps (each job runs until its time
 * budget, then re-dispatches itself), so a pause / cancel / budget stop
 * takes effect between cases and no job outlives its queue timeout.
 *
 * Per case: candidate (live, or live + overlay) run with purpose
 * `simulation`, scored; for gates also the baseline (live) run. Results are
 * reused from the cache by (config hash, case key). When candidate and
 * baseline disagree, the candidate is re-run once: if the re-run flips back
 * the verdict is `changed` (sampling noise, no temperature control),
 * otherwise `regressed` / `improved`.
 */
class SimulationRunner
{
    public function __construct(
        private readonly CaseRunner $runner,
        private readonly CaseScorer $scorer,
        private readonly SimulationConfig $config,
    ) {}

    /** @return bool true when more cases remain and the caller should dispatch another step */
    public function step(Simulation $simulation, ?int $seconds = null): bool
    {
        $simulation->refresh();
        if ($simulation->status === Simulation::STATUS_PENDING) {
            $simulation->forceFill(['status' => Simulation::STATUS_RUNNING, 'started_at' => $simulation->started_at ?? now()])->save();
        }
        if ($simulation->status !== Simulation::STATUS_RUNNING) {
            return false;
        }

        $deadline = microtime(true) + ($seconds ?? $this->stepSeconds($simulation));
        $ws = $simulation->workspace_id;
        $live = InstructionSet::live($ws);
        $candidate = $simulation->overlay ? $live->withOverlay($simulation->overlay) : $live;
        $candidateHash = $this->config->hash($ws, $candidate);
        $baselineHash = $simulation->comparesBaseline() ? $this->config->hash($ws, $live) : null;
        $perCase = $this->perCaseEstimate($simulation);

        while (true) {
            $simulation->refresh();
            if ($simulation->status !== Simulation::STATUS_RUNNING) {
                return false;
            }
            if ($simulation->budget_micro_usd !== null && $simulation->cost_micro_usd + $perCase > $simulation->budget_micro_usd) {
                $this->finish($simulation, Simulation::STATUS_BUDGET_EXHAUSTED, 'Stopped: the next case would exceed the budget.');

                return false;
            }

            $index = $this->claim($simulation);
            if ($index === null) {
                $this->finish($simulation, Simulation::STATUS_COMPLETED);

                return false;
            }

            [$frozenId, $testId] = $simulation->cases[$index];
            $result = $this->processCase($simulation, (int) $frozenId, $testId !== null ? (int) $testId : null, $candidate, $candidateHash, $live, $baselineHash);
            $this->tally($simulation, $result);

            if (microtime(true) >= $deadline) {
                return true;
            }
        }
    }

    /** Next case index, claimed atomically (two overlapping steps never run the same case). */
    private function claim(Simulation $simulation): ?int
    {
        return DB::transaction(function () use ($simulation) {
            $row = Simulation::whereKey($simulation->id)->lockForUpdate()->firstOrFail();
            if ($row->cursor >= count($row->cases)) {
                return null;
            }
            $index = $row->cursor;
            $row->forceFill(['cursor' => $index + 1])->save();

            return $index;
        });
    }

    private function processCase(Simulation $sim, int $frozenId, ?int $testId, InstructionSet $candidate, string $candidateHash, InstructionSet $live, ?string $baselineHash): SimulationResult
    {
        $existing = SimulationResult::where('simulation_id', $sim->id)->where('frozen_case_id', $frozenId)->first();
        if ($existing !== null) {
            return $existing;
        }

        $case = FrozenCase::where('workspace_id', $sim->workspace_id)->find($frozenId);
        $test = $testId !== null ? AgentTestCase::where('workspace_id', $sim->workspace_id)->find($testId) : null;
        $attrs = ['simulation_id' => $sim->id, 'frozen_case_id' => $frozenId, 'test_case_id' => $test?->id, 'config_hash' => $candidateHash];

        if ($case === null) {
            return $this->store([...$attrs, 'frozen_case_id' => $frozenId, 'verdict' => SimulationResult::VERDICT_ERROR, 'error' => 'Frozen case no longer exists.']);
        }
        $attrs['case_key'] = $case->caseKey();
        $cost = 0;

        try {
            $cand = $this->config->cached($candidateHash, $attrs['case_key']);
            $cached = $cand !== null;
            if ($cand === null) {
                $cand = $this->runAndScore($case, $test, $candidate);
                $cost += $cand['cost'];
            }

            $base = null;
            if ($baselineHash !== null) {
                $base = $this->config->cached($baselineHash, $attrs['case_key']);
                if ($base === null) {
                    $base = $this->runAndScore($case, $test, $live);
                    $cost += $base['cost'];
                }
            }

            $verdict = $this->verdict($cand['passed'], $base['passed'] ?? null, $baselineHash !== null);
            $rerun = null;
            if (in_array($verdict, [SimulationResult::VERDICT_REGRESSED, SimulationResult::VERDICT_IMPROVED], true)) {
                // Sampling cannot be pinned: confirm a flip before calling it a regression / improvement.
                $rerun = $this->runAndScore($case, $test, $candidate);
                $cost += $rerun['cost'];
                if ($rerun['passed'] === null || $rerun['passed'] === $base['passed']) {
                    $verdict = SimulationResult::VERDICT_CHANGED;
                }
            }

            $scores = $cand['scores'];
            if ($rerun !== null) {
                $scores['rerun'] = ['passed' => $rerun['passed'], 'judge' => $rerun['scores']['judge'] ?? null, 'deterministic' => $rerun['scores']['deterministic'] ?? null];
            }

            return $this->store([
                ...$attrs,
                'agent_run_id' => $cand['run_id'],
                'baseline_run_id' => $base['run_id'] ?? null,
                'rerun_run_id' => $rerun['run_id'] ?? null,
                'scores' => $scores,
                'baseline_scores' => $base['scores'] ?? null,
                'passed' => $cand['passed'],
                'baseline_passed' => $base['passed'] ?? null,
                'verdict' => $verdict,
                'judge_notes' => $this->notes($cand['scores'], $base['scores'] ?? null, $rerun),
                'cached' => $cached,
                'cost_micro_usd' => $cost,
            ]);
        } catch (Throwable $e) {
            Log::warning('Simulation case failed', ['simulation_id' => $sim->id, 'frozen_case_id' => $frozenId, 'error' => $e->getMessage()]);

            return $this->store([...$attrs, 'verdict' => SimulationResult::VERDICT_ERROR, 'error' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 2000), 'cost_micro_usd' => $cost]);
        }
    }

    /** @return array{run_id: int, scores: array<string, mixed>, passed: bool|null, cost: int} */
    private function runAndScore(FrozenCase $case, ?AgentTestCase $test, InstructionSet $instructions): array
    {
        $run = $this->runner->run($case, $instructions);
        $scores = $this->scorer->score($case, $test, $run, $instructions);

        return ['run_id' => $run->id, 'scores' => $scores, 'passed' => $scores['passed'], 'cost' => $run->cost_micro_usd + (int) $scores['cost_micro_usd']];
    }

    private function verdict(?bool $candidate, ?bool $baseline, bool $compares): string
    {
        if ($candidate === null) {
            return SimulationResult::VERDICT_ERROR;
        }
        if (! $compares || $baseline === null || $candidate === $baseline) {
            return $candidate ? SimulationResult::VERDICT_PASS : SimulationResult::VERDICT_FAIL;
        }

        return $candidate ? SimulationResult::VERDICT_IMPROVED : SimulationResult::VERDICT_REGRESSED;
    }

    /**
     * @param  array<string, mixed>  $cand
     * @param  array<string, mixed>|null  $base
     * @param  array<string, mixed>|null  $rerun
     */
    private function notes(array $cand, ?array $base, ?array $rerun): string
    {
        $lines = [];
        foreach ((array) ($cand['deterministic']['failures'] ?? []) as $failure) {
            $lines[] = $failure['detail'];
        }
        if (($cand['judge']['reason'] ?? '') !== '') {
            $lines[] = 'Judge: '.$cand['judge']['reason'];
        }
        if ($base !== null && ($base['judge']['reason'] ?? '') !== '') {
            $lines[] = 'Baseline judge: '.$base['judge']['reason'];
        }
        if ($rerun !== null) {
            $lines[] = 'Re-run: '.($rerun['passed'] === null ? 'error' : ($rerun['passed'] ? 'passed' : 'failed')).'.';
        }

        return mb_substr(implode("\n", $lines), 0, 4000);
    }

    /** @param  array<string, mixed>  $attrs */
    private function store(array $attrs): SimulationResult
    {
        try {
            return SimulationResult::create($attrs);
        } catch (UniqueConstraintViolationException) {
            return SimulationResult::where('simulation_id', $attrs['simulation_id'])->where('frozen_case_id', $attrs['frozen_case_id'])->firstOrFail();
        }
    }

    private function tally(Simulation $simulation, SimulationResult $result): void
    {
        $updated = DB::transaction(function () use ($simulation) {
            $row = Simulation::whereKey($simulation->id)->lockForUpdate()->firstOrFail();
            $totals = $row->totals ?? Simulation::emptyTotals(count($row->cases));
            $totals['done'] = SimulationResult::where('simulation_id', $row->id)->count();
            foreach (SimulationResult::VERDICTS as $verdict) {
                $totals[$verdict] = SimulationResult::where('simulation_id', $row->id)->where('verdict', $verdict)->count();
            }
            $totals['cached'] = SimulationResult::where('simulation_id', $row->id)->where('cached', true)->count();
            $row->forceFill(['totals' => $totals, 'cost_micro_usd' => (int) SimulationResult::where('simulation_id', $row->id)->sum('cost_micro_usd')])->save();

            return $row;
        });

        AgentLoopUpdated::dispatchSafely($updated->workspace_id, AgentLoopUpdated::SIMULATION_PROGRESS, $this->eventPayload($updated) + ['result_id' => $result->id]);
    }

    public function finish(Simulation $simulation, string $status, ?string $error = null): void
    {
        $simulation->refresh();
        if ($simulation->isFinished()) {
            return;
        }
        $simulation->forceFill(['status' => $status, 'finished_at' => now(), 'error' => $error])->save();

        if ($simulation->kind === Simulation::KIND_GATE && $simulation->proposal_id !== null) {
            Proposal::whereKey($simulation->proposal_id)
                ->where('status', Proposal::STATUS_TESTING)
                ->where('gate_simulation_id', $simulation->id)
                ->update(['status' => Proposal::STATUS_READY]);
        }

        AgentLoopUpdated::dispatchSafely($simulation->workspace_id, AgentLoopUpdated::SIMULATION_FINISHED, $this->eventPayload($simulation));

        // Auto-send (Phase 8): a playbook re-test lifts or demotes the hold.
        if ($simulation->kind === Simulation::KIND_PLAYBOOK_TESTS) {
            app(AutoModeGuard::class)->afterSimulationFinished($simulation);
        }
    }

    /** @return array<string, mixed> */
    public function eventPayload(Simulation $simulation): array
    {
        return [
            'simulation_id' => $simulation->id,
            'kind' => $simulation->kind,
            'status' => $simulation->status,
            'proposal_id' => $simulation->proposal_id,
            'playbook_id' => $simulation->playbook_id,
            'totals' => $simulation->totals ?? Simulation::emptyTotals(count($simulation->cases ?? [])),
            'cost_micro_usd' => $simulation->cost_micro_usd,
        ];
    }

    private function stepSeconds(Simulation $simulation): int
    {
        return $simulation->kind === Simulation::KIND_BATCH
            ? (int) config('agent.simulation.batch_step_seconds', 600)
            : (int) config('agent.simulation.step_seconds', 200);
    }

    private function perCaseEstimate(Simulation $simulation): int
    {
        $cases = max(1, count($simulation->cases ?? []));

        return (int) round($simulation->estimated_cost_micro_usd / $cases);
    }
}
