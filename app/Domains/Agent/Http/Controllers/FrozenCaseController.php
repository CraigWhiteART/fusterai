<?php

namespace App\Domains\Agent\Http\Controllers;

use App\Domains\Agent\Jobs\BuildFrozenCasesJob;
use App\Domains\Agent\Jobs\PrepareFrozenCasesJob;
use App\Domains\Agent\Models\FrozenCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Frozen cases (JSON, manage-settings): list / show, build from past
 * conversations (database only), and the explicit rate-limited "prepare"
 * step that reconstructs evidence from Shopify / the carrier.
 */
class FrozenCaseController extends AgentController
{
    public function index(Request $request): JsonResponse
    {
        $ws = $this->workspaceFor($request);
        $filters = $request->validate([
            'evidence_quality' => ['nullable', Rule::in(FrozenCase::QUALITIES)],
            'source' => ['nullable', Rule::in(FrozenCase::SOURCES)],
            'playbook' => ['nullable', 'string', 'max:80'],
            'unprepared' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $query = FrozenCase::where('workspace_id', $ws)->latest('cut_at');
        if (filled($filters['evidence_quality'] ?? null)) {
            $query->where('evidence_quality', $filters['evidence_quality']);
        }
        if (filled($filters['source'] ?? null)) {
            $query->where('source', $filters['source']);
        }
        if (filled($filters['playbook'] ?? null)) {
            $query->where('playbook_slug', $filters['playbook']);
        }
        if ((bool) ($filters['unprepared'] ?? false)) {
            $query->where('evidence_quality', '!=', FrozenCase::QUALITY_RECORDED)->whereNull('evidence_prepared_at');
        }
        $page = $query->paginate((int) ($filters['per_page'] ?? 50));

        $counts = FrozenCase::where('workspace_id', $ws)->selectRaw('evidence_quality, count(*) as n')->groupBy('evidence_quality')->pluck('n', 'evidence_quality');

        return response()->json([
            'data' => collect($page->items())->map(fn (FrozenCase $c) => $c->toUiArray())->values()->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'counts' => $counts,
            'unprepared' => FrozenCase::where('workspace_id', $ws)->where('evidence_quality', '!=', FrozenCase::QUALITY_RECORDED)->whereNull('evidence_prepared_at')->count(),
        ]);
    }

    public function show(Request $request, FrozenCase $frozenCase): JsonResponse
    {
        $this->ensureWorkspace($frozenCase, $this->workspaceFor($request));

        return response()->json(['frozen_case' => [...$frozenCase->toUiArray(withTranscript: true), 'evidence' => $frozenCase->evidence]]);
    }

    public function build(Request $request): JsonResponse
    {
        $ws = $this->workspaceFor($request);
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'imported_only' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'per_conversation' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        dispatch(new BuildFrozenCasesJob($ws, $filters));

        return response()->json(['queued' => true], 202);
    }

    public function prepare(Request $request): JsonResponse
    {
        $ws = $this->workspaceFor($request);
        $data = $request->validate([
            'frozen_case_ids' => ['nullable', 'array', 'max:1000'],
            'frozen_case_ids.*' => ['integer'],
        ]);

        $ids = isset($data['frozen_case_ids']) ? array_map('intval', $data['frozen_case_ids']) : null;
        if ($ids !== null) {
            // Explicit re-prepare of these cases.
            FrozenCase::where('workspace_id', $ws)->whereIn('id', $ids)->where('evidence_quality', '!=', FrozenCase::QUALITY_RECORDED)
                ->update(['evidence_prepared_at' => null]);
        }
        $pending = FrozenCase::where('workspace_id', $ws)->where('evidence_quality', '!=', FrozenCase::QUALITY_RECORDED)->whereNull('evidence_prepared_at')
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->count();

        dispatch(new PrepareFrozenCasesJob($ws, $ids));

        return response()->json(['queued' => true, 'cases' => $pending, 'per_minute' => (int) config('agent.simulation.prepare_per_minute', 30)], 202);
    }
}
