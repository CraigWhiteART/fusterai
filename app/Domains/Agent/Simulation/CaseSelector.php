<?php

namespace App\Domains\Agent\Simulation;

use App\Domains\Agent\Models\AgentExample;
use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\AgentTestCase;
use App\Domains\Agent\Models\FrozenCase;
use App\Domains\Agent\Models\KnowledgeItem;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Models\Proposal;
use App\Domains\Agent\Models\ProposalChange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which cases a simulation runs. Every selection is an ordered list of
 * [frozen_case_id, test_case_id|null].
 *
 * Gate (per proposal, capped at agent.simulation.gate_cap, pinned first):
 *   playbook change   the playbook's active test cases
 *   voice change      pinned core set + a sample stratified by playbook
 *   knowledge change  cases whose runs cited the item, plus test cases of
 *                     the playbooks in its scope
 *   example change    test cases of the example's playbook
 */
class CaseSelector
{
    /**
     * @param  array<string, mixed>  $filters  playbook (slug), date_from, date_to, only_pinned, min_evidence (recorded|reconstructed|none), sample (latest|random|stratified), limit
     * @return list<array{0: int, 1: int|null}>
     */
    public function batch(int $ws, array $filters): array
    {
        $limit = max(1, min((int) ($filters['limit'] ?? 50), (int) config('agent.simulation.max_batch_cases', 500)));
        $sample = (string) ($filters['sample'] ?? 'stratified');

        if ((bool) ($filters['only_pinned'] ?? false)) {
            $query = AgentTestCase::query()->where('workspace_id', $ws)->where('status', AgentTestCase::STATUS_ACTIVE)->where('pinned', true)
                ->whereHas('frozenCase', fn (Builder $q) => $this->caseFilters($q, $filters));

            return $query->orderBy('id')->limit($limit)->get(['id', 'frozen_case_id'])
                ->map(fn (AgentTestCase $t) => [(int) $t->frozen_case_id, (int) $t->id])->values()->all();
        }

        $query = FrozenCase::query()->where('workspace_id', $ws)->whereNotNull('human_reply');
        $this->caseFilters($query, $filters);

        $rows = match ($sample) {
            'random' => $query->inRandomOrder()->limit($limit)->get(['id', 'playbook_slug']),
            'latest' => $query->orderByDesc('cut_at')->limit($limit)->get(['id', 'playbook_slug']),
            default => $this->stratified($query->orderByDesc('cut_at')->limit($limit * 10)->get(['id', 'playbook_slug'])->all(), fn ($c) => (string) $c->playbook_slug, $limit),
        };

        return $this->withTestCases($ws, array_map(fn ($c) => (int) $c->id, is_array($rows) ? $rows : $rows->all()));
    }

    /** @return list<array{0: int, 1: int|null}> */
    public function playbookTests(Playbook $playbook): array
    {
        return AgentTestCase::where('workspace_id', $playbook->workspace_id)
            ->where('playbook_id', $playbook->id)
            ->where('status', AgentTestCase::STATUS_ACTIVE)
            ->orderByDesc('pinned')->orderBy('id')
            ->limit((int) config('agent.simulation.max_batch_cases', 500))
            ->get(['id', 'frozen_case_id'])
            ->map(fn (AgentTestCase $t) => [(int) $t->frozen_case_id, (int) $t->id])->values()->all();
    }

    /** @return list<array{0: int, 1: int|null}> */
    public function gate(Proposal $proposal): array
    {
        $ws = $proposal->workspace_id;
        $cap = (int) config('agent.simulation.gate_cap', 40);
        $proposal->loadMissing('changes');

        $active = fn () => AgentTestCase::query()->where('workspace_id', $ws)->where('status', AgentTestCase::STATUS_ACTIVE);
        $picked = [];
        $add = function (iterable $rows) use (&$picked): void {
            foreach ($rows as $row) {
                $key = $row[0].':'.($row[1] ?? '');
                $picked[$key] ??= $row;
            }
        };
        $pairs = fn ($q) => $q->orderByDesc('pinned')->orderBy('id')->get(['id', 'frozen_case_id'])->map(fn (AgentTestCase $t) => [(int) $t->frozen_case_id, (int) $t->id])->all();

        $touchedPlaybooks = [];
        $voice = false;
        $knowledgeIds = [];
        $knowledgeScopes = [];

        foreach ($proposal->changes as $change) {
            switch ($change->target_type) {
                case ProposalChange::TARGET_PLAYBOOK:
                    if ($change->target_id !== null) {
                        $touchedPlaybooks[] = (int) $change->target_id;
                    }
                    break;
                case ProposalChange::TARGET_VOICE:
                    $voice = true;
                    break;
                case ProposalChange::TARGET_KNOWLEDGE:
                    if ($change->target_id !== null) {
                        $knowledgeIds[] = (int) $change->target_id;
                        $knowledgeScopes = [...$knowledgeScopes, ...(array) (KnowledgeItem::find($change->target_id)?->scope['playbooks'] ?? [])];
                    }
                    $knowledgeScopes = [...$knowledgeScopes, ...(array) ($change->after['scope']['playbooks'] ?? [])];
                    break;
                case ProposalChange::TARGET_EXAMPLE:
                    $slug = AgentExample::find($change->target_id)?->playbook_slug;
                    if ($slug !== null) {
                        $knowledgeScopes[] = $slug;
                    }
                    break;
            }
        }

        // Pinned core set always comes first for voice changes.
        if ($voice) {
            $add($pairs($active()->where('pinned', true)));
        }
        if ($touchedPlaybooks !== []) {
            $add($pairs($active()->whereIn('playbook_id', $touchedPlaybooks)));
        }
        if ($knowledgeIds !== []) {
            $add($this->casesCiting($ws, $knowledgeIds));
        }
        if ($knowledgeScopes !== []) {
            $ids = Playbook::where('workspace_id', $ws)->whereIn('slug', array_unique($knowledgeScopes))->pluck('id')->all();
            $add($pairs($active()->whereIn('playbook_id', $ids)));
        }
        if ($voice && count($picked) < $cap) {
            $rest = $active()->where('pinned', false)->orderBy('id')->get(['id', 'frozen_case_id', 'playbook_id'])->all();
            $add(array_map(fn (AgentTestCase $t) => [(int) $t->frozen_case_id, (int) $t->id], $this->stratified($rest, fn ($t) => (string) $t->playbook_id, $cap)));
        }

        return array_slice(array_values($picked), 0, $cap);
    }

    /**
     * Frozen cases whose simulated run, or whose live run for the cut
     * message, cited one of the knowledge items.
     *
     * @param  list<int>  $knowledgeIds
     * @return list<array{0: int, 1: int|null}>
     */
    private function casesCiting(int $ws, array $knowledgeIds): array
    {
        $runs = AgentRun::where('workspace_id', $ws)->where('status', AgentRun::STATUS_COMPLETED)
            ->where(function (Builder $q) use ($knowledgeIds) {
                foreach ($knowledgeIds as $id) {
                    $q->orWhereRaw("coalesce(evidence->'knowledge', '[]'::jsonb) @> ?::jsonb", [json_encode([['id' => 'K'.$id]])]);
                }
            })
            ->latest('id')->limit(500)->get(['frozen_case_id', 'customer_thread_id']);

        $frozen = $runs->pluck('frozen_case_id')->filter()->map(fn ($id) => (int) $id)->all();
        $threads = $runs->whereNull('frozen_case_id')->pluck('customer_thread_id')->filter()->all();
        if ($threads !== []) {
            $frozen = [...$frozen, ...FrozenCase::where('workspace_id', $ws)->whereIn('cut_thread_id', $threads)->pluck('id')->map(fn ($id) => (int) $id)->all()];
        }

        return $this->withTestCases($ws, array_values(array_unique($frozen)));
    }

    /**
     * @param  list<int>  $frozenIds
     * @return list<array{0: int, 1: int|null}>
     */
    private function withTestCases(int $ws, array $frozenIds): array
    {
        if ($frozenIds === []) {
            return [];
        }
        $tests = AgentTestCase::where('workspace_id', $ws)->where('status', AgentTestCase::STATUS_ACTIVE)->whereIn('frozen_case_id', $frozenIds)
            ->orderBy('id')->get(['id', 'frozen_case_id'])->groupBy('frozen_case_id');

        return array_map(fn (int $id) => [$id, isset($tests[$id]) ? (int) $tests[$id]->first()->id : null], $frozenIds);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $q
     */
    private function caseFilters(Builder $q, array $filters): void
    {
        if (filled($filters['playbook'] ?? null)) {
            $q->where('playbook_slug', (string) $filters['playbook']);
        }
        if (filled($filters['date_from'] ?? null)) {
            $q->where('cut_at', '>=', CarbonImmutable::parse((string) $filters['date_from'])->startOfDay());
        }
        if (filled($filters['date_to'] ?? null)) {
            $q->where('cut_at', '<=', CarbonImmutable::parse((string) $filters['date_to'])->endOfDay());
        }
        $min = (string) ($filters['min_evidence'] ?? 'none');
        if ($min === FrozenCase::QUALITY_RECORDED) {
            $q->where('evidence_quality', FrozenCase::QUALITY_RECORDED);
        } elseif ($min === FrozenCase::QUALITY_RECONSTRUCTED) {
            $q->whereIn('evidence_quality', [FrozenCase::QUALITY_RECORDED, FrozenCase::QUALITY_RECONSTRUCTED]);
        }
    }

    /**
     * Round-robin across groups so every group is represented.
     *
     * @template T
     *
     * @param  list<T>  $items
     * @param  callable(T): string  $group
     * @return list<T>
     */
    private function stratified(array $items, callable $group, int $limit): array
    {
        $groups = [];
        foreach ($items as $item) {
            $groups[$group($item)][] = $item;
        }
        $out = [];
        while (count($out) < $limit && $groups !== []) {
            foreach (array_keys($groups) as $key) {
                $next = array_shift($groups[$key]);
                if ($next !== null) {
                    $out[] = $next;
                }
                if ($groups[$key] === []) {
                    unset($groups[$key]);
                }
                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }
}
