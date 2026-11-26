<?php

namespace App\Domains\Agent\Http\Controllers;

use App\Ai\EmbeddingService;
use App\Domains\Agent\AutoSend\AutoModeGuard;
use App\Domains\Agent\Models\KnowledgeItem;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Services\KbKnowledgeSync;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Inertia\Inertia;
use Inertia\Response;

class KnowledgeController extends AgentController
{
    public function index(Request $request): Response
    {
        $workspaceId = $this->workspaceFor($request);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(KnowledgeItem::STATUSES)],
            'kind' => ['nullable', Rule::in(KnowledgeItem::KINDS)],
            'playbook' => ['nullable', 'string', 'max:80'],
            'scope' => ['nullable', Rule::in(['global', 'scoped'])],
            'q' => ['nullable', 'string', 'max:200'],
        ]);

        $query = KnowledgeItem::query()->where('workspace_id', $workspaceId);
        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }
        if (filled($filters['kind'] ?? null)) {
            $query->where('kind', $filters['kind']);
        }
        if (filled($filters['playbook'] ?? null)) {
            $query->whereRaw("jsonb_exists(coalesce(scope->'playbooks', '[]'::jsonb), ?)", [$filters['playbook']]);
        }
        if (($filters['scope'] ?? null) === 'global') {
            $query->whereRaw("coalesce((scope->>'global')::boolean, false) = true");
        } elseif (($filters['scope'] ?? null) === 'scoped') {
            $query->whereRaw("coalesce((scope->>'global')::boolean, false) = false");
        }
        if (filled($filters['q'] ?? null)) {
            $term = '%'.mb_strtolower((string) $filters['q']).'%';
            $query->where(fn (Builder $q) => $q->whereRaw('lower(title) like ?', [$term])->orWhereRaw('lower(body) like ?', [$term]));
        }

        $items = $query->orderByRaw("case when status = 'needs_scope' then 0 else 1 end")
            ->orderByDesc('updated_at')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (KnowledgeItem $item) => $item->toUiArray());

        return Inertia::render('Agent/Knowledge/Index', [
            'items' => $items,
            'filters' => $filters,
            'counts' => KnowledgeItem::where('workspace_id', $workspaceId)
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status'),
            ...$this->options($workspaceId),
        ]);
    }

    public function create(Request $request): Response
    {
        $workspaceId = $this->workspaceFor($request);

        return Inertia::render('Agent/Knowledge/Edit', ['item' => null, ...$this->options($workspaceId)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspaceId = $this->workspaceFor($request);
        $data = $this->validated($request);

        $item = KnowledgeItem::create([
            'workspace_id' => $workspaceId,
            'kind' => $data['kind'],
            'title' => $data['title'] ?? null,
            'body' => $data['body'],
            'scope' => KnowledgeItem::normalizeScope($data['scope']),
            'valid_from' => $data['valid_from'] ?? null,
            'valid_until' => $data['valid_until'] ?? null,
            'status' => $data['status'] ?? KnowledgeItem::STATUS_ACTIVE,
            'supersedes_id' => $data['supersedes_id'] ?? null,
            'source_type' => 'manual',
            'content_hash' => hash('sha256', ($data['title'] ?? '')."\n".$data['body']),
            'created_by' => (int) $request->user()->id,
        ]);

        $this->retireSuperseded($item);
        $this->embed($item);
        if ($item->status === KnowledgeItem::STATUS_ACTIVE) {
            $this->retest($item, [$item->scope], "knowledge #{$item->id} added");
        }

        return redirect()->route('agent.knowledge.index')->with('success', 'Knowledge saved.');
    }

    public function edit(Request $request, KnowledgeItem $item): Response
    {
        $workspaceId = $this->workspaceFor($request);
        $this->ensureWorkspace($item, $workspaceId);

        return Inertia::render('Agent/Knowledge/Edit', ['item' => $item->toUiArray(), ...$this->options($workspaceId)]);
    }

    public function update(Request $request, KnowledgeItem $item): RedirectResponse
    {
        $workspaceId = $this->workspaceFor($request);
        $this->ensureWorkspace($item, $workspaceId);
        $data = $this->validated($request);

        $scope = KnowledgeItem::normalizeScope($data['scope']);
        $status = $data['status'] ?? $item->status;
        // Scoping an item from the needs_scope queue activates it.
        if ($item->status === KnowledgeItem::STATUS_NEEDS_SCOPE && ! isset($data['status'])) {
            $status = KnowledgeItem::STATUS_ACTIVE;
        }

        $hash = hash('sha256', ($data['title'] ?? '')."\n".$data['body']);
        $textChanged = $hash !== $item->content_hash;
        $oldScope = $item->scope;
        $oldStatus = $item->status;

        $item->forceFill([
            'kind' => $data['kind'],
            'title' => $data['title'] ?? null,
            'body' => $data['body'],
            'scope' => $scope,
            'valid_from' => $data['valid_from'] ?? null,
            'valid_until' => $data['valid_until'] ?? null,
            'status' => $status,
            'supersedes_id' => $data['supersedes_id'] ?? $item->supersedes_id,
            'content_hash' => $hash,
            ...($textChanged ? ['embedding' => null, 'embedding_model' => null] : []),
        ])->save();

        $this->retireSuperseded($item);
        if ($textChanged) {
            $this->embed($item);
        }
        $relevant = $item->wasChanged(['kind', 'title', 'body', 'scope', 'valid_from', 'valid_until', 'status', 'supersedes_id']);
        if ($relevant && ($oldStatus === KnowledgeItem::STATUS_ACTIVE || $item->status === KnowledgeItem::STATUS_ACTIVE)) {
            $this->retest($item, [$oldScope, $item->scope], "knowledge #{$item->id} edited");
        }

        return back()->with('success', 'Knowledge saved.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $validator = validator($request->all(), [
            'kind' => ['required', Rule::in(KnowledgeItem::KINDS)],
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'scope' => ['required', 'array'],
            'scope.global' => ['required', 'boolean'],
            'scope.playbooks' => ['nullable', 'array'],
            'scope.playbooks.*' => ['string', 'max:80'],
            'scope.products' => ['nullable', 'array'],
            'scope.products.*' => ['string', 'max:120'],
            'scope.countries' => ['nullable', 'array'],
            'scope.countries.*' => ['string', 'size:2'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'status' => ['nullable', Rule::in(KnowledgeItem::STATUSES)],
            'supersedes_id' => ['nullable', 'integer', Rule::exists('agent_knowledge_items', 'id')->where('workspace_id', $request->user()->workspace_id)],
        ]);

        // Scope is required: either explicitly global or naming something.
        $validator->after(function (Validator $v) use ($request) {
            $scope = (array) $request->input('scope', []);
            if (KnowledgeItem::scopeIsEmpty($scope) && $request->input('status') !== KnowledgeItem::STATUS_NEEDS_SCOPE) {
                $v->errors()->add('scope', 'Choose the playbooks, products or countries this applies to, or mark it global.');
            }
        });

        return $validator->validate();
    }

    private function retireSuperseded(KnowledgeItem $item): void
    {
        if ($item->supersedes_id !== null && $item->status === KnowledgeItem::STATUS_ACTIVE) {
            KnowledgeItem::where('id', $item->supersedes_id)
                ->where('workspace_id', $item->workspace_id)
                ->update(['status' => KnowledgeItem::STATUS_RETIRED]);
        }
    }

    /**
     * Auto playbooks this item can reach (before or after the change, and
     * through the item it supersedes) are held and re-tested.
     *
     * @param  list<mixed>  $scopes
     */
    private function retest(KnowledgeItem $item, array $scopes, string $cause): void
    {
        if ($item->supersedes_id !== null) {
            $scopes[] = KnowledgeItem::where('workspace_id', $item->workspace_id)->whereKey($item->supersedes_id)->value('scope');
        }
        app(AutoModeGuard::class)->afterKnowledgeChanged((int) $item->workspace_id, $scopes, $cause);
    }

    private function embed(KnowledgeItem $item): void
    {
        if (! app(EmbeddingService::class)->isConfigured($item->workspace_id)) {
            return;
        }

        try {
            app(KbKnowledgeSync::class)->embed($item->workspace_id, [$item]);
        } catch (\Throwable $e) {
            // Still found by keyword search; re-saving retries the embedding.
            Log::warning('Knowledge item embedding failed', ['id' => $item->id, 'error' => $e->getMessage()]);
        }
    }

    /** @return array<string, mixed> */
    private function options(int $workspaceId): array
    {
        return [
            'kinds' => KnowledgeItem::KINDS,
            'statuses' => KnowledgeItem::STATUSES,
            'playbooks' => Playbook::where('workspace_id', $workspaceId)->orderBy('slug')->get(['slug', 'name']),
        ];
    }
}
