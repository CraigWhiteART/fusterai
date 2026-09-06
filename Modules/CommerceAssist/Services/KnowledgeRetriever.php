<?php

namespace Modules\CommerceAssist\Services;

use App\Domains\AI\Models\KbDocument;
use App\Domains\AI\Models\KnowledgeBase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

class KnowledgeRetriever
{
    /**
     * @return Collection<int, KbDocument>
     */
    public function retrieve(int $workspaceId, string $query, int $limit = 5): Collection
    {
        $kbIds = KnowledgeBase::where('workspace_id', $workspaceId)
            ->where('active', true)
            ->pluck('id');

        if ($kbIds->isEmpty()) {
            return collect();
        }

        $hasEmbeddings = KbDocument::whereIn('kb_id', $kbIds)->whereNotNull('embedding')->exists();

        if ($hasEmbeddings) {
            try {
                return KbDocument::whereIn('kb_id', $kbIds)
                    ->whereNotNull('embedding')
                    ->whereVectorSimilarTo('embedding', $query, minSimilarity: (float) config('ai.rag.min_score', 0.6))
                    ->limit($limit)
                    ->get(['id', 'title', 'content']);
            } catch (QueryException) {
                // Fall through.
            }
        }

        $operator = config('database.default') === 'pgsql' ? 'ilike' : 'like';

        return KbDocument::whereIn('kb_id', $kbIds)
            ->where(function ($q) use ($query, $operator) {
                $q->where('title', $operator, "%{$query}%")
                    ->orWhere('content', $operator, "%{$query}%");
            })
            ->limit($limit)
            ->get(['id', 'title', 'content']);
    }
}
