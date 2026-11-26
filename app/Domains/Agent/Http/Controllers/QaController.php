<?php

namespace App\Domains\Agent\Http\Controllers;

use App\Domains\Agent\Qa\Models\QaGap;
use App\Domains\Agent\Qa\Models\QaReview;
use App\Domains\Agent\Qa\QaAggregates;
use App\Domains\Agent\Qa\QaService;
use App\Domains\Conversation\Models\Conversation;
use App\Support\PlainText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** QA reviews API (JSON, manage-settings). docs/agent-qa.md. */
class QaController extends AgentController
{
    public function __construct(
        private readonly QaService $qa,
        private readonly QaAggregates $aggregates,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $ws = $this->workspaceFor($request);
        $f = $request->validate([
            'failure_class' => ['nullable', Rule::in(QaReview::FAILURE_CLASSES)],
            'author_type' => ['nullable', Rule::in(QaReview::AUTHOR_TYPES)],
            'playbook_id' => ['nullable', 'integer'],
            'conversation_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(QaReview::STATUSES)],
            'min_overall' => ['nullable', 'numeric', 'between:1,5'],
            'max_overall' => ['nullable', 'numeric', 'between:1,5'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'include_dismissed' => ['nullable', 'boolean'],
            'trigger' => ['nullable', Rule::in(QaReview::TRIGGERS)],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $query = QaReview::with(['playbook:id,slug,name', 'conversation:id,subject'])->where('workspace_id', $ws)->latest('id');
        $query->whereIn('status', isset($f['status']) ? [$f['status']] : [QaReview::STATUS_REVIEWED, QaReview::STATUS_TRIAGED]);
        foreach (['failure_class', 'author_type', 'playbook_id', 'conversation_id', 'trigger'] as $key) {
            if (isset($f[$key])) {
                $query->where($key, $f[$key]);
            }
        }
        if (isset($f['min_overall'])) {
            $query->whereRaw('coalesce(overall, triage_score) >= ?', [(float) $f['min_overall']]);
        }
        if (isset($f['max_overall'])) {
            $query->whereRaw('coalesce(overall, triage_score) <= ?', [(float) $f['max_overall']]);
        }
        if (isset($f['from'])) {
            $query->whereRaw('coalesce(replied_at, created_at) >= ?', [Carbon::parse($f['from'])->startOfDay()]);
        }
        if (isset($f['to'])) {
            $query->whereRaw('coalesce(replied_at, created_at) <= ?', [Carbon::parse($f['to'])->endOfDay()]);
        }
        if (! ($f['include_dismissed'] ?? false)) {
            $query->whereNull('human_verdict');
        }

        $page = $query->paginate((int) ($f['per_page'] ?? 30));
        $counts = QaReview::where('workspace_id', $ws)->whereNull('human_verdict')->selectRaw('status, count(*) as n')->groupBy('status')->toBase()->pluck('n', 'status');

        return response()->json([
            'data' => $page->getCollection()->map(fn (QaReview $r) => self::review($r))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'counts' => $counts,
        ]);
    }

    public function show(Request $request, int $review): JsonResponse
    {
        $ws = $this->workspaceFor($request);
        $model = QaReview::with(['playbook:id,slug,name', 'conversation:id,subject', 'reviewedThread', 'customerThread', 'gapGroup'])->where('workspace_id', $ws)->find($review);
        abort_if($model === null, 404);

        return response()->json(['review' => self::review($model, true)]);
    }

    public function verdict(Request $request, int $review): JsonResponse
    {
        $ws = $this->workspaceFor($request);
        $model = QaReview::where('workspace_id', $ws)->find($review);
        abort_if($model === null, 404);
        $data = $request->validate([
            'verdict' => ['present', 'nullable', Rule::in(QaReview::HUMAN_VERDICTS)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $updated = $this->qa->setHumanVerdict($model, $data['verdict'], $data['note'] ?? null, (int) $request->user()->id);
        // Same payload as GET /agent/qa/reviews/{review} (detail), so the client can replace the row.
        $updated->load(['playbook:id,slug,name', 'conversation:id,subject', 'reviewedThread', 'customerThread', 'gapGroup']);

        return response()->json(['review' => self::review($updated, true)]);
    }

    public function reviewConversation(Request $request, int $conversation): JsonResponse
    {
        $ws = $this->workspaceFor($request);
        $model = Conversation::where('workspace_id', $ws)->find($conversation);
        abort_if($model === null, 404);
        if (($reason = $this->qa->disabledReason($ws)) !== null) {
            return response()->json(['message' => 'QA does not run for this workspace.', 'reason' => $reason], 409);
        }
        $review = $this->qa->forceReview($model, null, 'manual');
        if ($review === null) {
            return response()->json(['message' => 'This conversation has no staff reply to review.', 'reason' => 'no_staff_reply'], 422);
        }

        return response()->json(['review' => self::review($review->fresh() ?? $review)], 202);
    }

    public function capabilityRequests(Request $request): JsonResponse
    {
        $ws = $this->workspaceFor($request);
        $gaps = QaGap::with('playbook:id,slug,name')->where('workspace_id', $ws)->where('failure_class', 'missing_tool')
            ->where('review_count', '>', 0)->orderByDesc('review_count')->orderByDesc('last_seen_at')->limit(100)->get();

        return response()->json(['data' => $gaps->map(fn (QaGap $g) => [
            'id' => $g->id,
            'label' => $g->label,
            'topic' => $g->topic,
            'playbook' => $g->playbook === null ? null : ['id' => $g->playbook->id, 'slug' => $g->playbook->slug, 'name' => $g->playbook->name],
            'count' => $g->review_count,
            'conversation_ids' => $g->conversation_ids ?? [],
            'suggested_fixes' => QaReview::where('gap_id', $g->id)->whereNull('human_verdict')->whereNotNull('suggested_fix')->latest('id')->limit(5)->pluck('suggested_fix')->all(),
            'last_seen_at' => $g->last_seen_at?->toIso8601String(),
            'first_seen_at' => $g->created_at?->toIso8601String(),
        ])->values()]);
    }

    public function gaps(Request $request): JsonResponse
    {
        $ws = $this->workspaceFor($request);
        $gaps = QaGap::with('playbook:id,slug,name')->where('workspace_id', $ws)->latest('last_seen_at')->limit(200)->get();
        $threshold = (int) config('agent_qa.gaps.proposal_threshold', 3);

        return response()->json([
            'data' => $gaps->map(fn (QaGap $g) => [
                'id' => $g->id, 'failure_class' => $g->failure_class, 'status' => $g->status, 'label' => $g->label, 'topic' => $g->topic,
                'playbook' => $g->playbook === null ? null : ['id' => $g->playbook->id, 'slug' => $g->playbook->slug, 'name' => $g->playbook->name],
                'count' => $g->review_count, 'conversation_ids' => $g->conversation_ids ?? [], 'proposal_id' => $g->proposal_id,
                'error' => $g->error, 'cost_usd' => round($g->cost_micro_usd / 1_000_000, 4), 'last_seen_at' => $g->last_seen_at?->toIso8601String(),
                'threshold' => $threshold,
            ])->values(),
            'threshold' => $threshold,
        ]);
    }

    public function aggregates(Request $request): JsonResponse
    {
        $ws = $this->workspaceFor($request);
        $f = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'playbook_id' => ['nullable', 'integer']]);
        $to = isset($f['to']) ? Carbon::parse($f['to'])->endOfDay() : now()->endOfDay();
        $from = isset($f['from']) ? Carbon::parse($f['from'])->startOfDay() : $to->copy()->subDays(29)->startOfDay();

        return response()->json($this->aggregates->compute($ws, $from, $to, isset($f['playbook_id']) ? (int) $f['playbook_id'] : null));
    }

    /** @return array<string, mixed> */
    public static function review(QaReview $r, bool $detail = false): array
    {
        $out = [
            'id' => $r->id,
            'conversation_id' => $r->conversation_id,
            'subject' => $r->relationLoaded('conversation') ? $r->conversation?->subject : null,
            'reviewed_thread_id' => $r->reviewed_thread_id,
            'customer_thread_id' => $r->customer_thread_id,
            'author_type' => $r->author_type,
            'author_user_id' => $r->author_user_id,
            'run_id' => $r->run_id,
            'playbook' => $r->relationLoaded('playbook') && $r->playbook !== null ? ['id' => $r->playbook->id, 'slug' => $r->playbook->slug, 'name' => $r->playbook->name] : null,
            'playbook_id' => $r->playbook_id,
            'status' => $r->status,
            'skip_reason' => $r->skip_reason,
            'trigger' => $r->trigger,
            'pass' => $r->status === QaReview::STATUS_REVIEWED ? 'full' : ($r->status === QaReview::STATUS_TRIAGED ? 'first' : null),
            'sampled' => $r->sampled,
            'forced' => $r->forced,
            'triage_score' => $r->triage_score,
            'triage' => $r->triage,
            'scores' => $r->scores,
            'overall' => $r->overall !== null ? (float) $r->overall : null,
            'failure_class' => $r->failure_class,
            'summary' => $r->summary,
            'gap' => $r->gap,
            'gap_topic' => $r->gap_topic,
            'suggested_fix' => $r->suggested_fix,
            'gap_id' => $r->gap_id,
            'proposal_id' => $r->proposal_id,
            'evidence_source' => $r->evidence_source,
            'reviewer_model' => $r->reviewer_model,
            'reviewer_prompt_version' => $r->reviewer_prompt_version,
            'cost_micro_usd' => $r->cost_micro_usd,
            'cost_usd' => round($r->cost_micro_usd / 1_000_000, 5),
            'human_verdict' => $r->human_verdict,
            'human_note' => $r->human_note,
            'human_by' => $r->human_by,
            'human_at' => $r->human_at?->toIso8601String(),
            'error' => $r->error,
            'replied_at' => $r->replied_at?->toIso8601String(),
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(),
        ];
        if ($detail) {
            $out['reviewed_reply'] = $r->reviewedThread !== null ? PlainText::from($r->reviewedThread->body_plain ?: $r->reviewedThread->body) : null;
            $out['customer_message'] = $r->customerThread !== null ? PlainText::from($r->customerThread->body_plain ?: $r->customerThread->body) : null;
            $out['gap_group'] = $r->gapGroup === null ? null : ['id' => $r->gapGroup->id, 'status' => $r->gapGroup->status, 'count' => $r->gapGroup->review_count,
                'label' => $r->gapGroup->label, 'proposal_id' => $r->gapGroup->proposal_id, 'threshold' => (int) config('agent_qa.gaps.proposal_threshold', 3)];
        }

        return $out;
    }
}
