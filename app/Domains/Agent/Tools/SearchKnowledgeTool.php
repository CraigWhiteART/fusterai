<?php

namespace App\Domains\Agent\Tools;

use App\Domains\Agent\Pipeline\WriterSession;
use App\Domains\Agent\Services\KnowledgeSearch;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class SearchKnowledgeTool extends WriterTool
{
    public function __construct(WriterSession $session, private readonly KnowledgeSearch $search)
    {
        parent::__construct($session);
    }

    public function name(): string
    {
        return 'search_knowledge';
    }

    public function description(): string
    {
        return 'Search the approved knowledge base (policies, facts, product info) scoped to this playbook, the order\'s products and the shipping country. Returns items with ids (K...) to cite.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('What you need to know, in a few words')->required(),
        ];
    }

    protected function run(array $arguments): string
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            return 'Empty query.';
        }

        $items = $this->search->search(
            $this->session->instructions,
            $query,
            $this->session->playbook->slug,
            $this->session->productHaystack,
            $this->session->country,
            $this->session->input->now,
            (int) config('agent.knowledge.search_limit', 5),
        );

        if ($items === []) {
            return 'No approved knowledge matched. Do not state a policy or fact you cannot cite; say a teammate will confirm instead.';
        }

        $this->session->rememberKnowledge($items);

        return implode("\n\n", array_map(
            fn ($item) => "[{$item['id']}] ".($item['title'] ? $item['title'].': ' : '').$item['body'],
            $items,
        ));
    }
}
