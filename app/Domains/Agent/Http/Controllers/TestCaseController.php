<?php

namespace App\Domains\Agent\Http\Controllers;

use App\Domains\Agent\Http\Presenters\LoopPresenter;
use App\Domains\Agent\Models\AgentTestCase;
use App\Domains\Agent\Models\FrozenCase;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Simulation\FrozenCaseFactory;
use App\Domains\Agent\Simulation\SimulationService;
use App\Domains\Agent\Simulation\TestCaseService;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** Test cases (JSON, manage-settings). */
class TestCaseController extends AgentController
{
    public function index(Request $request): JsonResponse
    {
        $ws = $this->workspaceFor($request);
        $filters = $request->validate([
            'playbook_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(AgentTestCase::STATUSES)],
            'pinned' => ['nullable', 'boolean'],
            'source' => ['nullable', Rule::in(AgentTestCase::SOURCES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = AgentTestCase::with(['playbook', 'frozenCase', 'lastResult'])->where('workspace_id', $ws)->latest('id');
        foreach (['playbook_id', 'status', 'source'] as $key) {
            if (filled($filters[$key] ?? null)) {
                $query->where($key, $filters[$key]);
            }
        }
        if (array_key_exists('pinned', $filters) && $filters['pinned'] !== null) {
            $query->where('pinned', (bool) $filters['pinned']);
        }
        $page = $query->paginate((int) ($filters['per_page'] ?? 50));

        return response()->json([
            'data' => collect($page->items())->map(fn (AgentTestCase $t) => $t->toUiArray())->values()->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request, TestCaseService $tests, FrozenCaseFactory $factory): JsonResponse
    {
        $ws = $this->workspaceFor($request);
        $data = $request->validate([
            'frozen_case_id' => ['required_without:conversation_id', 'nullable', 'integer'],
            'conversation_id' => ['required_without:frozen_case_id', 'nullable', 'integer'],
            'cut_thread_id' => ['required_with:conversation_id', 'nullable', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'playbook_id' => ['nullable', 'integer'],
            'pinned' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::in(AgentTestCase::STATUSES)],
            'expectation' => ['nullable', 'array'],
        ]);

        if (filled($data['frozen_case_id'] ?? null)) {
            $case = FrozenCase::where('workspace_id', $ws)->findOrFail($data['frozen_case_id']);
        } else {
            $conversation = Conversation::where('workspace_id', $ws)->findOrFail($data['conversation_id']);
            $thread = Thread::where('conversation_id', $conversation->id)->findOrFail($data['cut_thread_id']);
            try {
                $case = $factory->fromConversation($conversation, $thread, 'manual');
            } catch (InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage(), 'errors' => ['cut_thread_id' => [$e->getMessage()]]], 422);
            }
        }

        try {
            $test = $tests->create($case, $data['name'], (array) ($data['expectation'] ?? []), isset($data['playbook_id']) ? (int) $data['playbook_id'] : null,
                (bool) ($data['pinned'] ?? false), 'manual', $data['status'] ?? AgentTestCase::STATUS_ACTIVE, $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['playbook_id' => [$e->getMessage()]]], 422);
        }

        return response()->json(['test_case' => $test->toUiArray()], 201);
    }

    public function show(Request $request, AgentTestCase $testCase): JsonResponse
    {
        $this->ensureWorkspace($testCase, $this->workspaceFor($request));
        $testCase->load(['playbook', 'frozenCase']);

        return response()->json(['test_case' => [...$testCase->toUiArray(), 'frozen_case' => $testCase->frozenCase->toUiArray(withTranscript: true)]]);
    }

    public function update(Request $request, AgentTestCase $testCase, TestCaseService $tests): JsonResponse
    {
        $this->ensureWorkspace($testCase, $this->workspaceFor($request));
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'playbook_id' => ['sometimes', 'nullable', 'integer'],
            'pinned' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(AgentTestCase::STATUSES)],
            'expectation' => ['sometimes', 'array'],
        ]);

        try {
            $test = $tests->update($testCase, $data);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['playbook_id' => [$e->getMessage()]]], 422);
        }

        return response()->json(['test_case' => $test->toUiArray()]);
    }

    /** Archives (test cases are never hard-deleted: results reference them). */
    public function destroy(Request $request, AgentTestCase $testCase, TestCaseService $tests): JsonResponse
    {
        $this->ensureWorkspace($testCase, $this->workspaceFor($request));

        return response()->json(['test_case' => $tests->archive($testCase)->toUiArray()]);
    }

    public function runPlaybook(Request $request, Playbook $playbook, SimulationService $simulations): JsonResponse
    {
        $this->ensureWorkspace($playbook, $this->workspaceFor($request));

        return response()->json(['simulation' => LoopPresenter::simulation($simulations->createPlaybookTests($playbook, $request->user()->id))], 202);
    }
}
