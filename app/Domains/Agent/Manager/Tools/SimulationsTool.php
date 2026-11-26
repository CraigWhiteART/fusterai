<?php

namespace App\Domains\Agent\Manager\Tools;

use App\Domains\Agent\Models\Simulation;
use App\Domains\Agent\Models\SimulationResult;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class SimulationsTool extends ManagerTool
{
    public function name(): string
    {
        return 'simulation_results';
    }

    public function description(): string
    {
        return 'Simulations (replays of past tickets against the instructions): without simulation_id, the latest simulations with their totals; with simulation_id, that simulation and its failing / regressed / changed cases with the judge\'s reason.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'simulation_id' => $schema->integer(),
            'proposal_id' => $schema->integer()->description('Only the regression gates of this proposal'),
        ];
    }

    protected function label(array $arguments): string
    {
        return isset($arguments['simulation_id']) ? 'Reading simulation #'.(int) $arguments['simulation_id'] : 'Listing simulations';
    }

    protected function run(array $arguments): string
    {
        $ws = $this->session->workspaceId;
        if (isset($arguments['simulation_id']) && (int) $arguments['simulation_id'] > 0) {
            $simulation = Simulation::where('workspace_id', $ws)->find((int) $arguments['simulation_id']);
            if ($simulation === null) {
                return 'No such simulation in this workspace.';
            }
            $this->session->cite('simulation', $simulation->id, 'Simulation #'.$simulation->id);
            $results = SimulationResult::with('frozenCase:id,conversation_id,subject')->where('simulation_id', $simulation->id)
                ->whereNotIn('verdict', [SimulationResult::VERDICT_PASS])->latest('id')->limit(20)->get();

            return self::json([
                'simulation' => ['id' => $simulation->id, 'kind' => $simulation->kind, 'status' => $simulation->status, 'proposal_id' => $simulation->proposal_id,
                    'totals' => $simulation->totals, 'created_at' => $simulation->created_at?->toIso8601String()],
                'not_passing' => $results->map(fn (SimulationResult $r) => [
                    'result_id' => $r->id,
                    'conversation_id' => $r->frozenCase->conversation_id,
                    'subject' => self::cut($r->frozenCase->subject, 100),
                    'verdict' => $r->effectiveVerdict(),
                    'judge_reason' => self::cut((string) ($r->scores['judge']['reason'] ?? $r->judge_notes ?? ''), 400),
                    'failures' => $r->scores['deterministic']['failures'] ?? [],
                ])->values()->all(),
            ]);
        }

        $query = Simulation::where('workspace_id', $ws)->latest('id')->limit(10);
        if (isset($arguments['proposal_id']) && (int) $arguments['proposal_id'] > 0) {
            $query->where('proposal_id', (int) $arguments['proposal_id']);
        }

        return self::json(['simulations' => $query->get()->map(fn (Simulation $s) => [
            'id' => $s->id, 'kind' => $s->kind, 'status' => $s->status, 'proposal_id' => $s->proposal_id, 'playbook_id' => $s->playbook_id,
            'totals' => $s->totals, 'created_at' => $s->created_at?->toIso8601String(),
        ])->values()->all()]);
    }
}
