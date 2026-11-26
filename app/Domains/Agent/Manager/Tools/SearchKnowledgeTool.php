<?php

namespace App\Domains\Agent\Manager\Tools;

use App\Ai\EmbeddingService;
use App\Ai\Retrieval\HybridRetriever;
use App\Domains\Agent\Manager\ManagerSession;
use App\Domains\Agent\Models\KnowledgeItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** Keyword (Postgres full-text) search, plus vectors only when embeddings exist. */
class SearchKnowledgeTool extends ManagerTool
{
    public function __construct(
        ManagerSession $session,
        private readonly HybridRetriever $retriever,
        private readonly EmbeddingService $embeddings,
    ) {
        parent::__construct($session);
    }

    public function name(): string
    {
        return 'search_knowledge';
    }

    public function description(): string
    {
        return 'Search the knowledge items (facts, policies, snippets, knowledge-base chunks) with their ids, scope, status and validity. Use it before proposing knowledge changes so you change or retire the right item instead of duplicating it.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('A few words, e.g. "warranty replacement"')->required(),
            'status' => $schema->string()->enum(['active', 'any'])->description('Default: everything not retired; any = include retired'),
            'playbook' => $schema->string()->description('Only items scoped to this playbook slug'),
        ];
    }

    protected function label(array $arguments): string
    {
        return 'Searching knowledge for "'.mb_substr((string) ($arguments['query'] ?? ''), 0, 60).'"';
    }

    protected function run(array $arguments): string
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            return 'Empty query.';
        }
        $ws = $this->session->workspaceId;
        $builder = KnowledgeItem::where('workspace_id', $ws);
        if (($arguments['status'] ?? 'active') !== 'any') {
            $builder->where('status', '!=', KnowledgeItem::STATUS_RETIRED);
        }
        if (filled($arguments['playbook'] ?? null)) {
            $builder->whereJsonContains('scope->playbooks', (string) $arguments['playbook']);
        }

        $vector = (clone $builder)->whereNotNull('embedding')->exists() ? $this->embeddings->embedQuery($ws, $query) : null;
        $rows = $this->retriever->search($builder, ['title', 'body'], $query, $vector, 8, (float) config('agent.knowledge.min_similarity', 0.6),
            ['id', 'kind', 'title', 'body', 'scope', 'status', 'valid_from', 'valid_until'], 'embedding', 'tsv');

        if ($rows->isEmpty()) {
            return 'No knowledge item matched.';
        }

        return self::json(['items' => $rows->map(fn (KnowledgeItem $k) => [
            'id' => $k->id,
            'kind' => $k->kind,
            'status' => $k->status,
            'title' => $k->title,
            'body' => self::cut($k->body, 900),
            'scope' => KnowledgeItem::normalizeScope($k->scope),
            'valid_from' => $k->valid_from?->toDateString(),
            'valid_until' => $k->valid_until?->toDateString(),
        ])->values()->all()]);
    }
}
