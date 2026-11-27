<?php

namespace App\Domains\Agent\Http\Controllers;

use App\Domains\Agent\Http\Presenters\LoopPresenter;
use App\Domains\Agent\Jobs\PreviewProposalJob;
use App\Domains\Agent\Models\Proposal;
use App\Domains\Agent\Proposals\ConflictChecker;
use App\Domains\Agent\Proposals\ConflictResolver;
use App\Domains\Agent\Proposals\InvalidProposalException;
use App\Domains\Agent\Proposals\ProposalService;
use App\Domains\Agent\Proposals\ProposalStateException;
use App\Domains\Agent\Simulation\SimulationService;
use App\Domains\Conversation\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Proposal review (JSON). Listing, approving, rejecting, rebasing,
 * reverting, conflict checks / resolutions and gates need `manage-settings`.
 * Show and preview are also open to anyone who can view the proposal's
 * conversation (the agent who gave the feedback sees the diff and redraft).
 */
class ProposalController extends AgentController
{
    public function index(Request $request): JsonResponse
    {
        $ws = $this->workspaceFor($request);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(Proposal::STATUSES)],
            'origin' => ['nullable', Rule::in(Proposal::ORIGINS)],
            'open' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Proposal::with('changes')->where('workspace_id', $ws)->latest('id');
        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }
        if (filled($filters['origin'] ?? null)) {
            $query->where('origin', $filters['origin']);
        }
        if ((bool) ($filters['open'] ?? false)) {
            $query->whereIn('status', Proposal::OPEN_STATUSES);
        }
        $page = $query->paginate((int) ($filters['per_page'] ?? 25));

        $counts = Proposal::where('workspace_id', $ws)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return response()->json([
            'data' => collect($page->items())->map(fn (Proposal $p) => LoopPresenter::proposal($p))->values()->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'counts' => $counts,
        ]);
    }

    public function show(Request $request, Proposal $proposal): JsonResponse
    {
        $this->authorizeView($request, $proposal);

        return response()->json(['proposal' => LoopPresenter::proposal($proposal, withPreview: true)]);
    }

    public function check(Request $request, Proposal $proposal, ConflictChecker $checker): JsonResponse
    {
        $this->manage($request, $proposal);
        if (! $proposal->isOpen()) {
            return response()->json(['message' => "This proposal is {$proposal->status}.", 'reason' => 'not_open'], 409);
        }
        $checker->check($proposal);

        return response()->json(['proposal' => LoopPresenter::proposal($proposal->fresh() ?? $proposal)]);
    }

    public function resolveConflict(Request $request, Proposal $proposal, ConflictResolver $resolver): JsonResponse
    {
        $this->manage($request, $proposal);
        $data = $request->validate([
            'conflict_id' => ['required', 'string', 'max:100'],
            'resolution' => ['required', Rule::in(ConflictResolver::RESOLUTIONS)],
            'condition' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $resolved = $resolver->resolve($proposal, $data['conflict_id'], $data['resolution'], $data['condition'] ?? null, $request->user()->id, $data['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['resolution' => [$e->getMessage()]]], 422);
        } catch (InvalidProposalException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['ops' => $e->errors]], 422);
        } catch (ProposalStateException $e) {
            return $this->state($e);
        }

        return response()->json(['proposal' => LoopPresenter::proposal($resolved)]);
    }

    public function preview(Request $request, Proposal $proposal): JsonResponse
    {
        $this->authorizeView($request, $proposal);
        abort_if($proposal->run_id === null, 422, 'This proposal has no draft to preview against.');

        $proposal->forceFill(['preview' => ['status' => 'pending', 'run_id' => null, 'baseline_run_id' => $proposal->run_id, 'error' => null, 'at' => now()->toIso8601String()]])->save();
        dispatch(new PreviewProposalJob($proposal->id));

        return response()->json(['proposal' => LoopPresenter::proposal($proposal->fresh() ?? $proposal, withPreview: true)], 202);
    }

    public function gate(Request $request, Proposal $proposal, SimulationService $simulations): JsonResponse
    {
        $this->manage($request, $proposal);

        try {
            $simulation = $simulations->createGate($proposal, $request->user()->id);
        } catch (ProposalStateException $e) {
            return $this->state($e);
        }

        return response()->json(['simulation' => LoopPresenter::simulation($simulation), 'proposal' => LoopPresenter::proposal($proposal->fresh() ?? $proposal)], 202);
    }

    public function approve(Request $request, Proposal $proposal, ProposalService $proposals, ConflictChecker $checker): JsonResponse
    {
        $this->manage($request, $proposal);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        try {
            if ($proposal->conflicts_checked_at === null && $proposal->isOpen() && ! $proposals->isStale($proposal)) {
                $checker->check($proposal);
                $proposal->refresh();
            }
            $applied = $proposals->approve($proposal, $request->user()->id, $data['note'] ?? null);
        } catch (ProposalStateException $e) {
            return $this->state($e, $proposal);
        }

        return response()->json(['proposal' => LoopPresenter::proposal($applied)]);
    }

    /**
     * Partial approval: leave changes / single ops out. What is left is
     * re-validated; the conflict check re-runs, and a preview or gate that
     * existed is started again for the remaining changes (unless
     * `recheck: false`, for a client batching several toggles).
     */
    public function exclude(Request $request, Proposal $proposal, ProposalService $proposals, ConflictChecker $checker, SimulationService $simulations): JsonResponse
    {
        $this->manage($request, $proposal);
        $data = $request->validate([
            'change_ids' => ['nullable', 'array', 'max:200'],
            'change_ids.*' => ['integer'],
            'ops' => ['nullable', 'array', 'max:500'],
            'ops.*.change_id' => ['required', 'integer'],
            'ops.*.op_index' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
            'recheck' => ['nullable', 'boolean'],
        ]);
        $changeIds = array_map('intval', (array) ($data['change_ids'] ?? []));
        $ops = array_map(fn ($o) => ['change_id' => (int) $o['change_id'], 'op_index' => (int) $o['op_index']], (array) ($data['ops'] ?? []));
        if ($changeIds === [] && $ops === []) {
            return response()->json(['message' => 'Choose at least one change or op to leave out.', 'errors' => ['change_ids' => ['Choose at least one change or op to leave out.']]], 422);
        }

        return $this->rewrite($request, $proposal, $checker, $simulations, (bool) ($data['recheck'] ?? true),
            fn () => $proposals->exclude($proposal, $changeIds, $ops, (int) $request->user()->id, $data['note'] ?? null));
    }

    /** Put excluded items back: `ids` = excluded[].id, or every one when omitted. */
    public function restoreExclusions(Request $request, Proposal $proposal, ProposalService $proposals, ConflictChecker $checker, SimulationService $simulations): JsonResponse
    {
        $this->manage($request, $proposal);
        $data = $request->validate([
            'ids' => ['nullable', 'array', 'max:500'],
            'ids.*' => ['string', 'max:40'],
            'recheck' => ['nullable', 'boolean'],
        ]);

        return $this->rewrite($request, $proposal, $checker, $simulations, (bool) ($data['recheck'] ?? true),
            fn () => $proposals->restoreExclusions($proposal, array_values((array) ($data['ids'] ?? []))));
    }

    /**
     * A reviewer rewords one item before approving: the title / body of a
     * create_knowledge op, or the if / then of an add_rule / replace_rule
     * op (`op_index` into the change's `ops`, default 0). Re-validated and
     * re-checked like an exclusion.
     */
    public function editChange(Request $request, Proposal $proposal, string $change, ProposalService $proposals, ConflictChecker $checker, SimulationService $simulations): JsonResponse
    {
        $this->manage($request, $proposal);
        $data = $request->validate([
            'op_index' => ['nullable', 'integer', 'min:0'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'body' => ['sometimes', 'required', 'string', 'max:20000'],
            'if' => ['sometimes', 'nullable', 'string', 'max:500'],
            'then' => ['sometimes', 'required', 'string', 'max:1000'],
            'recheck' => ['nullable', 'boolean'],
        ]);
        $fields = array_intersect_key($data, array_flip(['title', 'body', 'if', 'then']));

        return $this->rewrite($request, $proposal, $checker, $simulations, (bool) ($data['recheck'] ?? true),
            fn () => $proposals->editChange($proposal, (int) $change, (int) ($data['op_index'] ?? 0), $fields),
            ['That edit does not work as it stands.', 'invalid_edit']);
    }

    /**
     * @param  callable(): Proposal  $change
     * @param  array{0: string, 1: string}  $invalid  message and reason when what results does not validate
     */
    private function rewrite(Request $request, Proposal $proposal, ConflictChecker $checker, SimulationService $simulations, bool $recheck, callable $change, array $invalid = ['What would be left does not apply as it stands.', 'invalid_remainder']): JsonResponse
    {
        $hadPreview = $proposal->run_id !== null && $proposal->preview !== null;
        $hadGate = $proposal->gate_simulation_id !== null;

        try {
            $updated = $change();
        } catch (InvalidProposalException $e) {
            return response()->json(['message' => $invalid[0], 'reason' => $invalid[1], 'errors' => ['ops' => $e->errors],
                'proposal' => LoopPresenter::proposal($proposal->fresh() ?? $proposal)], 422);
        } catch (ProposalStateException $e) {
            return $this->state($e, $proposal);
        }

        if ($recheck && $updated->conflicts_checked_at === null) {
            $checker->check($updated);
            $updated->refresh();
            if ($hadPreview) {
                $updated->forceFill(['preview' => ['status' => 'pending', 'run_id' => null, 'baseline_run_id' => $updated->run_id, 'error' => null, 'at' => now()->toIso8601String()]])->save();
                dispatch(new PreviewProposalJob($updated->id));
            }
            if ($hadGate) {
                try {
                    $simulations->createGate($updated->fresh('changes') ?? $updated, (int) $request->user()->id);
                } catch (ProposalStateException) {
                    // not gateable any more: the reviewer can start it by hand
                }
            }
        }

        return response()->json(['proposal' => LoopPresenter::proposal($updated->fresh('changes') ?? $updated, withPreview: true)]);
    }

    public function reject(Request $request, Proposal $proposal, ProposalService $proposals): JsonResponse
    {
        $this->manage($request, $proposal);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        try {
            $rejected = $proposals->reject($proposal, $request->user()->id, $data['note'] ?? null);
        } catch (ProposalStateException $e) {
            return $this->state($e);
        }

        return response()->json(['proposal' => LoopPresenter::proposal($rejected)]);
    }

    public function rebase(Request $request, Proposal $proposal, ProposalService $proposals): JsonResponse
    {
        $this->manage($request, $proposal);

        try {
            $rebased = $proposals->rebase($proposal);
        } catch (InvalidProposalException $e) {
            return response()->json(['message' => 'The proposal no longer applies to the live configuration.', 'reason' => 'rebase_failed', 'errors' => ['ops' => $e->errors],
                'proposal' => LoopPresenter::proposal($proposal->fresh() ?? $proposal)], 422);
        } catch (ProposalStateException $e) {
            return $this->state($e);
        }

        return response()->json(['proposal' => LoopPresenter::proposal($rebased)]);
    }

    public function revert(Request $request, Proposal $proposal, ProposalService $proposals): JsonResponse
    {
        $this->manage($request, $proposal);

        try {
            $reverted = $proposals->revert($proposal, $request->user()->id);
        } catch (ProposalStateException $e) {
            return $this->state($e);
        }

        return response()->json(['proposal' => LoopPresenter::proposal($reverted)]);
    }

    private function manage(Request $request, Proposal $proposal): void
    {
        $this->ensureWorkspace($proposal, $this->workspaceFor($request));
    }

    private function authorizeView(Request $request, Proposal $proposal): void
    {
        if (Gate::allows('manage-settings')) {
            $this->ensureWorkspace($proposal, (int) $request->user()->workspace_id);

            return;
        }
        $conversation = $proposal->conversation_id !== null ? Conversation::find($proposal->conversation_id) : null;
        abort_if($conversation === null, 403);
        $this->authorize('view', $conversation);
    }

    private function state(ProposalStateException $e, ?Proposal $proposal = null): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'reason' => $e->reason,
            'context' => $e->context,
            'proposal' => $proposal !== null ? LoopPresenter::proposal($proposal->fresh() ?? $proposal) : null,
        ], 409);
    }
}
