<?php

namespace App\Domains\Agent\Mining\Http;

use App\Domains\Agent\Mining\MiningAlreadyRunning;
use App\Domains\Agent\Mining\MiningPresenter;
use App\Domains\Agent\Mining\MiningSelector;
use App\Domains\Agent\Mining\MiningService;
use App\Domains\Agent\Mining\Models\MiningRun;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * JSON API for history mining (docs/agent-mining.md). Admins only
 * (manage-settings); runs of another workspace are 404.
 */
class MiningController extends Controller
{
    public function __construct(
        private readonly MiningService $mining,
        private readonly MiningPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('manage-settings');
        $workspaceId = (int) $request->user()->workspace_id;

        $runs = MiningRun::query()->with('user:id,name')->where('workspace_id', $workspaceId)->latest('id')->limit(20)->get();

        return response()->json([
            'runs' => $runs->map(fn (MiningRun $run) => $this->presenter->summary($run))->values(),
            'active_run_id' => $runs->first(fn (MiningRun $run) => $run->isActive())?->id,
            'defaults' => [
                'months' => (int) config('agent_mining.defaults.months', 18),
                'max_conversations' => (int) config('agent_mining.defaults.max_conversations', 3000),
                'budget_usd' => (float) config('agent_mining.defaults.budget_usd', 25),
                'sample_strategy' => (string) config('agent_mining.defaults.sample_strategy', 'stratified'),
            ],
            'limits' => [
                'max_conversations' => (int) config('agent_mining.limits.max_conversations', 20000),
                'budget_usd' => (float) config('agent_mining.limits.budget_usd', 500),
                'months' => 120,
            ],
            'strategies' => MiningSelector::STRATEGIES,
        ]);
    }

    public function estimate(Request $request): JsonResponse
    {
        $this->authorize('manage-settings');

        return response()->json(['estimate' => $this->mining->estimate((int) $request->user()->workspace_id, $this->validated($request))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('manage-settings');

        try {
            $run = $this->mining->start((int) $request->user()->workspace_id, $this->validated($request), $request->user());
        } catch (MiningAlreadyRunning $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['run' => $this->presenter->detail($run->load('user:id,name'))], 201);
    }

    public function show(Request $request, MiningRun $run): JsonResponse
    {
        $this->authorize('manage-settings');
        $this->ensureWorkspace($request, $run);

        return response()->json(['run' => $this->presenter->detail($run->load('user:id,name'))]);
    }

    public function action(Request $request, MiningRun $run, string $action): JsonResponse
    {
        $this->authorize('manage-settings');
        $this->ensureWorkspace($request, $run);

        $data = $request->validate(['budget_usd' => ['nullable', 'numeric', 'min:0.01', 'max:'.(float) config('agent_mining.limits.budget_usd', 500)]]);

        try {
            $ok = match ($action) {
                'pause' => $this->mining->pause($run),
                'resume' => $this->mining->resume($run, isset($data['budget_usd']) ? (float) $data['budget_usd'] : null),
                'cancel' => $this->mining->cancel($run),
                default => abort(404),
            };
        } catch (MiningAlreadyRunning $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        if (! $ok) {
            return response()->json(['message' => "A {$run->status} run cannot be {$action}d.", 'run' => $this->presenter->summary($run)], 409);
        }

        return response()->json(['run' => $this->presenter->detail($run->fresh(['user']) ?? $run)]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'since' => ['nullable', 'date'],
            'until' => ['nullable', 'date'],
            'max_conversations' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('agent_mining.limits.max_conversations', 20000)],
            'budget_usd' => ['nullable', 'numeric', 'min:0.01', 'max:'.(float) config('agent_mining.limits.budget_usd', 500)],
            'sample_strategy' => ['nullable', Rule::in(MiningSelector::STRATEGIES)],
        ]);
    }

    private function ensureWorkspace(Request $request, MiningRun $run): void
    {
        abort_unless($run->workspace_id === (int) $request->user()->workspace_id, 404);
    }
}
