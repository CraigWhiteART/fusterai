<?php

namespace App\Domains\Agent\Services;

use App\Ai\EmbeddingService;
use App\Ai\Retrieval\HybridRetriever;
use App\Domains\Agent\Models\KnowledgeItem;
use App\Domains\Agent\Pipeline\InstructionSet;
use Illuminate\Database\Eloquent\Builder;

/**
 * Scoped knowledge retrieval. An item is eligible only when it is active,
 * inside its validity window at the run's "now", and its scope matches:
 * explicitly global, or naming the chosen playbook, a product in the order /
 * customer text, or the shipping country. Ranking is HybridRetriever
 * (stored tsvector + pgvector when embeddings exist).
 */
class KnowledgeSearch
{
    public function __construct(
        private readonly HybridRetriever $retriever,
        private readonly EmbeddingService $embeddings,
    ) {}

    /**
     * @param  string  $productHaystack  Lower-cased product titles + customer text, matched against scope.products.
     * @return list<array{id: string, kind: string, title: string|null, body: string, scope: array<string, mixed>}>
     */
    public function search(
        InstructionSet $instructions,
        string $query,
        ?string $playbookSlug,
        string $productHaystack,
        ?string $country,
        \DateTimeInterface $now,
        int $limit,
        bool $allowEmbedding = true,
    ): array {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $workspaceId = $instructions->workspaceId;
        $builder = $this->eligible($workspaceId, $playbookSlug, $productHaystack, $country, $now);
        if ($instructions->knowledgeRemove !== []) {
            $builder->whereNotIn('id', $instructions->knowledgeRemove);
        }

        $vector = null;
        if ($allowEmbedding && (clone $builder)->whereNotNull('embedding')->exists()) {
            $vector = $this->embeddings->embedQuery($workspaceId, $query);
        }

        $rows = $this->retriever->search(
            $builder,
            ['title', 'body'],
            $query,
            $vector,
            $limit,
            (float) config('agent.knowledge.min_similarity', 0.6),
            ['id', 'kind', 'title', 'body', 'scope'],
            'embedding',
            'tsv',
        );

        $results = $rows->map(fn (KnowledgeItem $item) => [
            'id' => 'K'.$item->id,
            'kind' => $item->kind,
            'title' => $item->title,
            'body' => $item->body,
            'scope' => KnowledgeItem::normalizeScope($item->scope),
        ])->values()->all();

        foreach ($this->overlayMatches($instructions, $query, $playbookSlug, $productHaystack, $country) as $item) {
            array_unshift($results, $item);
        }

        return array_slice($results, 0, $limit);
    }

    /** @return Builder<KnowledgeItem> */
    public function eligible(int $workspaceId, ?string $playbookSlug, string $productHaystack, ?string $country, \DateTimeInterface $now): Builder
    {
        return KnowledgeItem::query()
            ->where('workspace_id', $workspaceId)
            ->usableAt($now)
            ->where(function (Builder $q) use ($playbookSlug, $productHaystack, $country) {
                $q->whereRaw("coalesce((scope->>'global')::boolean, false) = true");

                if ($playbookSlug !== null) {
                    $q->orWhereRaw("jsonb_exists(coalesce(scope->'playbooks', '[]'::jsonb), ?)", [$playbookSlug]);
                }
                if (trim($productHaystack) !== '') {
                    $q->orWhereRaw(
                        "exists (select 1 from jsonb_array_elements_text(coalesce(scope->'products', '[]'::jsonb)) as p(kw) where length(p.kw) > 1 and ? like '%' || lower(p.kw) || '%')",
                        [mb_strtolower($productHaystack)],
                    );
                }
                if ($country !== null && trim($country) !== '') {
                    $q->orWhereRaw("jsonb_exists(coalesce(scope->'countries', '[]'::jsonb), ?)", [strtoupper(trim($country))]);
                }
            });
    }

    /**
     * In-memory overlay items (preview): simple term overlap, same scope rules.
     *
     * @return list<array{id: string, kind: string, title: string|null, body: string, scope: array<string, mixed>}>
     */
    private function overlayMatches(InstructionSet $instructions, string $query, ?string $playbookSlug, string $productHaystack, ?string $country): array
    {
        preg_match_all('/[\p{L}\p{N}]{4,}/u', mb_strtolower($query), $m);
        $terms = array_unique($m[0]);
        $out = [];

        foreach ($instructions->knowledgeAdd as $item) {
            $scope = KnowledgeItem::normalizeScope($item['scope']);
            $inScope = $scope['global']
                || ($playbookSlug !== null && in_array($playbookSlug, $scope['playbooks'], true))
                || ($country !== null && in_array(strtoupper($country), $scope['countries'], true))
                || array_filter($scope['products'], fn ($kw) => $kw !== '' && str_contains(mb_strtolower($productHaystack), $kw)) !== [];
            if (! $inScope) {
                continue;
            }

            $text = mb_strtolower(($item['title'] ?? '').' '.$item['body']);
            $hits = array_filter($terms, fn ($term) => str_contains($text, $term));
            if ($hits !== []) {
                $out[] = [...$item, 'scope' => $scope];
            }
        }

        return $out;
    }
}
