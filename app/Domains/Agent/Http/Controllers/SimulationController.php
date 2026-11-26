<?php

namespace App\Domains\Agent\Http\Controllers;

use App\Domains\Agent\Http\Presenters\LoopPresenter;
use App\Domains\Agent\Models\FrozenCase;
use App\Domains\Agent\Models\Simulation;
use App\Domains\Agent\Models\SimulationResult;
use App\Domains\Agent\Simulation\SimulationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** Simulations (JSON, manage-settings). */
class SimulationController extends AgentController
{
    public function estimate(Request $request, SimulationService $simulations): JsonResponse
    {
        $ws = $this->workspaceFor($request);
        ['cases' => $cases, 'estimate' => $estimate] = $simulations->previewBatch($ws, $this->filters($request));

        return response()->json(['estimate' => $estimate, 'frozen_case_ids' => array_map(fn ($c) => $c[0], $cases)]);
    }

    public function store(Request $request, SimulationService $simulations): JsonResponse
    {
        $ws = $this->workspaceFor($request);
        $filters = $this->filters($request);
        $budget = $request->validate(['budget_usd' => ['nullable', 'numeric', 'min:0', 'max:10000']])['budget_usd'] ?? null;

        try {
            $simulation = $simulations->createBatch($ws, $filters, $budget !== null ? (float) $budget : null, $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['filters' => [$e->getMessage()]]], 422);
        }

        return response()->json(['simulation' => LoopPresenter::simulation($simulation)], 202);
    }

    public function index(Request $request): JsonResponse
    {
        $ws = $this->workspaceFor($request);
        $filters = $request->validate([
            'kind' => ['nullable', Rule::in(Simulation::KINDS)],
            'proposal_id' => ['nullable', 'integer'],
            'playbook_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = Simulation::where('workspace_id', $ws)->latest('id');
        foreach (['kind', 'proposal_id', 'playbook_id'] as $key) {
            if (filled($filters[$key] ?? null)) {
                $query->where($key, $filters[$key]);
            }
        }
        $page = $query->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (Simulation $s) => LoopPresenter::simulation($s))->values()->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, Simulation $simulation): JsonResponse
    {
        $this->ensureWorkspace($simulation, $this->workspaceFor($request));

        return response()->json(['simulation' => LoopPresenter::simulation($simulation)]);
    }

    public function results(Request $request, Simulation $simulation): JsonResponse
    {
        $this->ensureWorkspace($simulation, $this->workspaceFor($request));
        $filters = $request->validate([
            'verdict' => ['nullable', Rule::in(SimulationResult::VERDICTS)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $query = SimulationResult::with(['frozenCase', 'testCase.playbook', 'run.playbook', 'baselineRun'])->where('simulation_id', $simulation->id)->orderBy('id');
        if (filled($filters['verdict'] ?? null)) {
            $query->where(fn ($q) => $q->where('human_override', $filters['verdict'])->orWhere(fn ($q) => $q->whereNull('human_override')->where('verdict', $filters['verdict'])));
        }
        $page = $query->paginate((int) ($filters['per_page'] ?? 50));

        return response()->json([
            'data' => collect($page->items())->map(fn (SimulationResult $r) => LoopPresenter::result($r))->values()->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function pause(Request $request, Simulation $simulation, SimulationService $simulations): JsonResponse
    {
        $this->ensureWorkspace($simulation, $this->workspaceFor($request));

        return response()->json(['simulation' => LoopPresenter::simulation($simulations->pause($simulation))]);
    }

    public function resume(Request $request, Simulation $simulation, SimulationService $simulations): JsonResponse
    {
        $this->ensureWorkspace($simulation, $this->workspaceFor($request));

        try {
            return response()->json(['simulation' => LoopPresenter::simulation($simulations->resume($simulation))], 202);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function cancel(Request $request, Simulation $simulation, SimulationService $simulations): JsonResponse
    {
        $this->ensureWorkspace($simulation, $this->workspaceFor($request));

        return response()->json(['simulation' => LoopPresenter::simulation($simulations->cancel($simulation))]);
    }

    public function overrideResult(Request $request, Simulation $simulation, SimulationResult $result, SimulationService $simulations): JsonResponse
    {
        $this->ensureWorkspace($simulation, $this->workspaceFor($request));
        abort_unless($result->simulation_id === $simulation->id, 404);
        $data = $request->validate([
            'verdict' => ['present', 'nullable', Rule::in([SimulationResult::VERDICT_PASS, SimulationResult::VERDICT_FAIL])],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $updated = $simulations->override($result, $data['verdict'], $data['note'] ?? null, $request->user()->id);

        return response()->json(['result' => LoopPresenter::result($updated)]);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'playbook' => ['nullable', 'string', 'max:80'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'only_pinned' => ['nullable', 'boolean'],
            'min_evidence' => ['nullable', Rule::in(FrozenCase::QUALITIES)],
            'sample' => ['nullable', Rule::in(['latest', 'random', 'stratified'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('agent.simulation.max_batch_cases', 500)],
        ]);
    }
}
