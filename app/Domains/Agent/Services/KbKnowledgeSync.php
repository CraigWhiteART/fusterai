<?php

namespace App\Domains\Agent\Services;

use App\Ai\EmbeddingService;
use App\Domains\Agent\AutoSend\AutoModeGuard;
use App\Domains\Agent\Models\KnowledgeItem;
use App\Domains\AI\Models\KbDocument;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a KB document's chunks in agent_knowledge_items in step with the
 * document. Called by IndexKbDocumentJob on create/update; deleting a
 * document deletes its chunks through the foreign key (cascade).
 *
 * KB chunks are scoped explicitly global (every KB document was available to
 * every conversation before), unless a human re-scopes a chunk: an existing
 * chunk keeps its scope and status, only its text changes.
 */
class KbKnowledgeSync
{
    public function __construct(
        private readonly KnowledgeChunker $chunker,
        private readonly EmbeddingService $embeddings,
    ) {}

    /** @return int Number of chunks now stored for the document. */
    public function sync(KbDocument $document, bool $embed = true): int
    {
        $document->loadMissing('knowledgeBase');
        $kb = $document->knowledgeBase;
        if ($kb === null) {
            return 0;
        }

        $workspaceId = (int) $kb->workspace_id;
        $chunks = $this->chunker->chunk((string) $document->content);
        $title = trim((string) $document->title);
        $count = count($chunks);

        $changed = [];
        $removedScopes = [];
        DB::transaction(function () use ($document, $chunks, $title, $count, $workspaceId, $kb, &$changed, &$removedScopes) {
            foreach ($chunks as $index => $body) {
                $hash = hash('sha256', $title."\n".$body);
                $item = KnowledgeItem::where('source_document_id', $document->id)->where('chunk_index', $index)->first();

                if ($item === null) {
                    $item = KnowledgeItem::create([
                        'workspace_id' => $workspaceId,
                        'kind' => 'kb_chunk',
                        'title' => $count > 1 ? "{$title} (part ".($index + 1)." of {$count})" : $title,
                        'body' => $body,
                        'scope' => ['playbooks' => [], 'products' => [], 'countries' => [], 'global' => true],
                        'status' => $kb->active ? KnowledgeItem::STATUS_ACTIVE : KnowledgeItem::STATUS_DRAFT,
                        'source_document_id' => $document->id,
                        'chunk_index' => $index,
                        'source_type' => 'kb_document',
                        'content_hash' => $hash,
                    ]);
                    $changed[] = $item;
                } elseif ($item->content_hash !== $hash) {
                    $item->forceFill([
                        'title' => $count > 1 ? "{$title} (part ".($index + 1)." of {$count})" : $title,
                        'body' => $body,
                        'content_hash' => $hash,
                        'embedding' => null,
                        'embedding_model' => null,
                    ])->save();
                    $changed[] = $item;
                }
            }

            $removed = KnowledgeItem::where('source_document_id', $document->id)->where('chunk_index', '>=', $count);
            $removedScopes = (clone $removed)->where('status', KnowledgeItem::STATUS_ACTIVE)->pluck('scope')->all();
            $removed->delete();
        });

        // Auto playbooks that can retrieve the changed text re-test before auto-sending again.
        $scopes = [...$removedScopes, ...array_map(fn (KnowledgeItem $i) => $i->scope, array_filter($changed, fn (KnowledgeItem $i) => $i->status === KnowledgeItem::STATUS_ACTIVE))];
        if ($scopes !== []) {
            app(AutoModeGuard::class)->afterKnowledgeChanged($workspaceId, $scopes, "knowledge document \"{$title}\" updated");
        }

        if ($embed && $changed !== [] && $this->embeddings->isConfigured($workspaceId)) {
            $this->embed($workspaceId, $changed);
        }

        return $count;
    }

    /** @param  list<KnowledgeItem>  $items */
    public function embed(int $workspaceId, array $items): void
    {
        $resolved = $this->embeddings->resolve($workspaceId);
        foreach (array_chunk($items, 32) as $batch) {
            $vectors = $this->embeddings->embed($workspaceId, array_map(
                fn (KnowledgeItem $item) => mb_substr(($item->title ? $item->title."\n\n" : '').$item->body, 0, 8000),
                $batch,
            ));
            foreach ($batch as $i => $item) {
                if (! isset($vectors[$i])) {
                    continue;
                }
                $item->forceFill([
                    'embedding' => EmbeddingService::toColumn($vectors[$i]),
                    'embedding_model' => $resolved['model'] ?? null,
                ])->save();
            }
        }
    }
}
