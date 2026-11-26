<?php

namespace App\Domains\Agent\Simulation;

use App\Domains\Agent\Jobs\RunSimulationJob;
use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\FrozenCase;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Models\Proposal;
use App\Domains\Agent\Models\Simulation;
use App\Domains\Agent\Models\SimulationResult;
use App\Domains\Agent\Pipeline\InstructionSet;
use App\Domains\Agent\Proposals\ProposalService;
use App\Domains\Agent\Proposals\ProposalStateException;
use InvalidArgumentException;

/**
 * Starts, estimates and controls simulations. Batch runs go to the long
 * queue; gates and playbook tests to `ai`. See SimulationRunner.
 */
class SimulationService
{
    public function __construct(
        private readonly CaseSelector $selector,
        private readonly SimulationConfig $config,
        private readonly ProposalService $proposals,
        private readonly SimulationRunner $runner,
    ) {}

    /**
     * Up-front cost estimate for running these cases.
     *
     * @param  list<array{0: int, 1: int|null}>  $cases
     * @return array{cases: int, cached_cases: int, per_case_micro_usd: int, estimated_micro_usd: int, estimated_usd: float, basis: string}
     */
    public function estimate(int $ws, array $cases, ?array $overlay = null, bool $baseline = false): array
    {
        [$perRun, $basis] = $this->perRunCost($ws);
        $judge = (int) config('agent.simulation.judge_cost_micro_usd', 8000);
        $live = InstructionSet::live($ws);
        $candidateHash = $this->config->hash($ws, $overlay ? $live->withOverlay($overlay) : $live);
        $baselineHash = $baseline ? $this->config->hash($ws, $live) : null;

        $cached = 0;
        $total = 0;
        $keys = FrozenCase::whereIn('id', array_map(fn ($c) => $c[0], $cases))->get()->mapWithKeys(fn ($c) => [$c->id => $c->caseKey()]);
        foreach ($cases as [$frozenId]) {
            $key = $keys[$frozenId] ?? null;
            $candidateCached = $key !== null && $this->config->cached($candidateHash, $key) !== null;
            $cached += $candidateCached ? 1 : 0;
            $total += $candidateCached ? 0 : $perRun + $judge;
            if ($baselineHash !== null && ($key === null || $this->config->cached($baselineHash, $key) === null)) {
                $total += $perRun + $judge;
            }
        }
        if ($baseline) {
            // Flipped cases are re-run once.
            $total = (int) round($total * 1.15);
        }
        $count = count($cases);

        return [
            'cases' => $count,
            'cached_cases' => $cached,
            'per_case_micro_usd' => $count > 0 ? (int) round($total / $count) : 0,
            'estimated_micro_usd' => $total,
            'estimated_usd' => round($total / 1_000_000, 4),
            'basis' => $basis,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters  see CaseSelector::batch
     * @return array{cases: list<array{0: int, 1: int|null}>, estimate: array<string, mixed>}
     */
    public function previewBatch(int $ws, array $filters): array
    {
        $cases = $this->selector->batch($ws, $filters);

        return ['cases' => $cases, 'estimate' => $this->estimate($ws, $cases)];
    }

    /** @param  array<string, mixed>  $filters */
    public function createBatch(int $ws, array $filters, ?float $budgetUsd, ?int $userId): Simulation
    {
        ['cases' => $cases, 'estimate' => $estimate] = $this->previewBatch($ws, $filters);
        if ($cases === []) {
            throw new InvalidArgumentException('No frozen cases match these filters. Build or prepare cases first.');
        }

        return $this->start($ws, Simulation::KIND_BATCH, $cases, $estimate, null, false, [
            'selection' => ['filters' => $filters], 'created_by' => $userId,
            'budget_micro_usd' => $budgetUsd !== null ? (int) round($budgetUsd * 1_000_000) : null,
        ]);
    }

    public function createPlaybookTests(Playbook $playbook, ?int $userId): Simulation
    {
        $cases = $this->selector->playbookTests($playbook);

        return $this->start($playbook->workspace_id, Simulation::KIND_PLAYBOOK_TESTS, $cases, $this->estimate($playbook->workspace_id, $cases), null, false, [
            // playbook_version_id: auto-mode preconditions need "a passing run on the current live version".
            'selection' => ['playbook_id' => $playbook->id, 'playbook_version_id' => $playbook->live_version_id], 'playbook_id' => $playbook->id, 'created_by' => $userId,
        ]);
    }

    /** Regression gate: the proposal's affected cases, live vs live + overlay. */
    public function createGate(Proposal $proposal, ?int $userId): Simulation
    {
        if (! in_array($proposal->status, [Proposal::STATUS_DRAFT, Proposal::STATUS_READY, Proposal::STATUS_TESTING], true)) {
            throw new ProposalStateException("A {$proposal->status} proposal cannot be gated.", 'not_open');
        }
        if ($proposal->gate_simulation_id !== null) {
            $previous = Simulation::find($proposal->gate_simulation_id);
            if ($previous !== null && ! $previous->isFinished()) {
                $this->cancel($previous);
            }
        }

        $overlay = $this->proposals->overlay($proposal->loadMissing('changes'));
        $cases = $this->selector->gate($proposal);
        $simulation = $this->start($proposal->workspace_id, Simulation::KIND_GATE, $cases, $this->estimate($proposal->workspace_id, $cases, $overlay, true), $overlay, true, [
            'selection' => ['proposal_id' => $proposal->id, 'cap' => (int) config('agent.simulation.gate_cap', 40)],
            'proposal_id' => $proposal->id, 'created_by' => $userId,
        ], dispatch: false);

        $proposal->forceFill([
            'gate_simulation_id' => $simulation->id,
            'status' => $cases === [] ? Proposal::STATUS_READY : Proposal::STATUS_TESTING,
        ])->save();
        $this->proposals->broadcast($proposal);

        if ($cases !== []) {
            dispatch(RunSimulationJob::for($simulation));
        }

        return $simulation->fresh() ?? $simulation;
    }

    public function pause(Simulation $simulation): Simulation
    {
        if (in_array($simulation->status, [Simulation::STATUS_PENDING, Simulation::STATUS_RUNNING], true)) {
            $simulation->forceFill(['status' => Simulation::STATUS_PAUSED])->save();
        }

        return $simulation;
    }

    public function resume(Simulation $simulation): Simulation
    {
        if ($simulation->status !== Simulation::STATUS_PAUSED) {
            throw new InvalidArgumentException("Only a paused simulation can be resumed (this one is {$simulation->status}).");
        }
        $simulation->forceFill(['status' => Simulation::STATUS_RUNNING])->save();
        dispatch(RunSimulationJob::for($simulation));

        return $simulation->fresh() ?? $simulation;
    }

    public function cancel(Simulation $simulation): Simulation
    {
        if (! $simulation->isFinished()) {
            $this->runner->finish($simulation, Simulation::STATUS_CANCELLED);
        }

        return $simulation->fresh() ?? $simulation;
    }

    public function override(SimulationResult $result, ?string $verdict, ?string $note, int $userId): SimulationResult
    {
        if ($verdict !== null && ! in_array($verdict, [SimulationResult::VERDICT_PASS, SimulationResult::VERDICT_FAIL], true)) {
            throw new InvalidArgumentException('A human override is pass or fail (or null to clear).');
        }
        $result->forceFill([
            'human_override' => $verdict,
            'human_override_note' => $note,
            'human_override_by' => $verdict !== null ? $userId : null,
        ])->save();

        return $result;
    }

    /**
     * @param  list<array{0: int, 1: int|null}>  $cases
     * @param  array<string, mixed>  $estimate
     * @param  array<string, mixed>|null  $overlay
     * @param  array<string, mixed>  $attributes
     */
    private function start(int $ws, string $kind, array $cases, array $estimate, ?array $overlay, bool $baseline, array $attributes, bool $dispatch = true): Simulation
    {
        $live = InstructionSet::live($ws);
        $simulation = Simulation::create([
            'workspace_id' => $ws,
            'kind' => $kind,
            'status' => $cases === [] ? Simulation::STATUS_COMPLETED : Simulation::STATUS_PENDING,
            'candidate_config_hash' => $this->config->hash($ws, $overlay ? $live->withOverlay($overlay) : $live),
            'baseline_config_hash' => $baseline ? $this->config->hash($ws, $live) : null,
            'overlay' => $overlay,
            'cases' => $cases,
            'totals' => Simulation::emptyTotals(count($cases)),
            'estimated_cost_micro_usd' => (int) $estimate['estimated_micro_usd'],
            'finished_at' => $cases === [] ? now() : null,
            ...$attributes,
        ]);

        if ($dispatch && $cases !== []) {
            dispatch(RunSimulationJob::for($simulation));
        }

        return $simulation->fresh() ?? $simulation;
    }

    /** @return array{0: int, 1: string} */
    private function perRunCost(int $ws): array
    {
        $avg = AgentRun::where('workspace_id', $ws)
            ->where('status', AgentRun::STATUS_COMPLETED)
            ->where('cost_micro_usd', '>', 0)
            ->where('created_at', '>=', now()->subDays(30))
            ->whereIn('purpose', [AgentRun::PURPOSE_LIVE, AgentRun::PURPOSE_SHADOW, AgentRun::PURPOSE_SIMULATION])
            ->avg('cost_micro_usd');

        return $avg !== null && (float) $avg > 0
            ? [(int) round((float) $avg), 'average of this workspace\'s runs in the last 30 days']
            : [(int) config('agent.simulation.run_cost_micro_usd', 60000), 'default (no recent runs to average)'];
    }
}
