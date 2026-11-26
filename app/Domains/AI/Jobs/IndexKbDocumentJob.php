<?php

namespace App\Domains\AI\Jobs;

use App\Ai\EmbeddingService;
use App\Domains\Agent\Services\KbKnowledgeSync;
use App\Domains\AI\Models\KbDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IndexKbDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly KbDocument $document,
    ) {
        $this->onQueue('ai');
    }

    public function handle(): void
    {
        $embeddings = app(EmbeddingService::class);
        $workspaceId = $this->document->knowledgeBase->workspace_id;
        $meta = $this->document->meta ?? [];
        unset($meta['index_error']);

        // Keep the agent's chunked copy of this document in step (embeds the
        // changed chunks too when embeddings are configured).
        app(KbKnowledgeSync::class)->sync($this->document);

        // No embeddings configured: the document stays searchable by keyword.
        // Record why it has no vector instead of failing the job.
        if (! $embeddings->isConfigured($workspaceId)) {
            $meta['embedding_skipped'] = 'Embeddings are not configured';
            $this->document->meta = $meta;
            $this->document->embedding = null;
            $this->document->indexed_at = now();
            $this->document->save();

            return;
        }

        $text = mb_substr($this->document->title."\n\n".$this->document->content, 0, 8000);
        $vector = $embeddings->embed($workspaceId, [$text])[0];

        unset($meta['embedding_skipped']);
        $this->document->meta = $meta;
        $this->document->embedding = EmbeddingService::toColumn($vector);
        $this->document->indexed_at = now();
        $this->document->save();
    }

    public function failed(\Throwable $e): void
    {
        $meta = $this->document->meta ?? [];
        $meta['index_error'] = $e->getMessage();
        $this->document->update(['meta' => $meta]);
    }
}
