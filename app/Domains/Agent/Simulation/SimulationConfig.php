<?php

namespace App\Domains\Agent\Simulation;

use App\Domains\Agent\Models\SimulationResult;
use App\Domains\Agent\Pipeline\AgentPipeline;
use App\Domains\Agent\Pipeline\InstructionSet;
use App\Domains\Agent\Proposals\ConfigFingerprint;
use App\Services\AiSettingsService;

/**
 * Cache identity for simulated results: the pipeline config hash (models,
 * voice / playbook versions, overlay hash, prompt template) + the knowledge
 * and example fingerprint + the judge model and scorer version. A result is
 * reused when (this hash, the case's content + evidence key) was already
 * scored, so only changed configs re-run.
 */
class SimulationConfig
{
    public function __construct(
        private readonly AgentPipeline $pipeline,
        private readonly ConfigFingerprint $fingerprint,
        private readonly AiSettingsService $ai,
    ) {}

    public function hash(int $workspaceId, InstructionSet $instructions): string
    {
        $judge = $this->ai->resolveCredentials($workspaceId, 'simulation_judge');

        return hash('sha256', implode('|', [
            $this->pipeline->configHash($workspaceId, $instructions),
            $this->fingerprint->forWorkspace($workspaceId),
            (string) ($judge['model'] ?? ''),
            CaseScorer::VERSION,
        ]));
    }

    /**
     * A finished, non-error score for this config + case: as a candidate
     * result, or as the baseline computed by a gate.
     *
     * @return array{run_id: int|null, scores: array<string, mixed>, passed: bool}|null
     */
    public function cached(string $configHash, string $caseKey, ?int $exceptSimulationId = null): ?array
    {
        $hit = SimulationResult::where('config_hash', $configHash)->where('case_key', $caseKey)
            ->whereNotNull('passed')
            ->when($exceptSimulationId !== null, fn ($q) => $q->where('simulation_id', '!=', $exceptSimulationId))
            ->latest('id')->first();
        if ($hit !== null) {
            return ['run_id' => $hit->agent_run_id, 'scores' => (array) $hit->scores, 'passed' => (bool) $hit->passed];
        }

        $base = SimulationResult::query()
            ->join('agent_simulations', 'agent_simulations.id', '=', 'agent_simulation_results.simulation_id')
            ->where('agent_simulations.baseline_config_hash', $configHash)
            ->where('agent_simulation_results.case_key', $caseKey)
            ->whereNotNull('agent_simulation_results.baseline_passed')
            ->orderByDesc('agent_simulation_results.id')
            ->first(['agent_simulation_results.*']);

        return $base === null ? null : ['run_id' => $base->baseline_run_id, 'scores' => (array) $base->baseline_scores, 'passed' => (bool) $base->baseline_passed];
    }
}
