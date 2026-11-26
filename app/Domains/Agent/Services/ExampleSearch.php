<?php

namespace App\Domains\Agent\Services;

use App\Ai\EmbeddingService;
use App\Ai\Retrieval\HybridRetriever;
use App\Domains\Agent\Models\AgentExample;

/** Approved replies for style and strategy, preferring the chosen playbook. */
class ExampleSearch
{
    public function __construct(
        private readonly HybridRetriever $retriever,
        private readonly EmbeddingService $embeddings,
    ) {}

    /**
     * @param  list<int>  $exclude  Example ids hidden by an overlay (a proposal that retires them).
     * @return list<array{id: string, customer_message: string, reply: string}>
     */
    public function search(int $workspaceId, string $query, ?string $playbookSlug, int $limit, array $exclude = []): array
    {
        if (trim($query) === '' || $limit <= 0) {
            return [];
        }

        $builder = AgentExample::query()->where('workspace_id', $workspaceId)->where('status', 'active');
        if ($exclude !== []) {
            $builder->whereNotIn('id', $exclude);
        }
        if ($playbookSlug !== null && (clone $builder)->where('playbook_slug', $playbookSlug)->exists()) {
            $builder->where('playbook_slug', $playbookSlug);
        }

        $vector = (clone $builder)->whereNotNull('embedding')->exists()
            ? $this->embeddings->embedQuery($workspaceId, $query)
            : null;

        return $this->retriever->search(
            $builder,
            ['customer_message', 'reply'],
            $query,
            $vector,
            $limit,
            0.55,
            ['id', 'customer_message', 'reply'],
            'embedding',
            'tsv',
        )->map(fn (AgentExample $example) => [
            'id' => 'X'.$example->id,
            'customer_message' => mb_substr($example->customer_message, 0, 1500),
            'reply' => mb_substr($example->reply, 0, 1500),
        ])->values()->all();
    }
}
