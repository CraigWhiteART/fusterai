<?php

namespace Modules\CommerceAssist\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Modules\CommerceAssist\Models\ApprovedResponse;

class ApprovedResponseRetriever
{
    /**
     * @return Collection<int, ApprovedResponse>
     */
    public function retrieve(int $workspaceId, string $query, ?string $intent = null, int $limit = 5): Collection
    {
        $builder = ApprovedResponse::query()->where('workspace_id', $workspaceId);

        if ($intent) {
            $builder->where(function ($q) use ($intent) {
                $q->where('intent', $intent)->orWhere('subtype', $intent);
            });
        }

        $hasEmbeddings = (clone $builder)->whereNotNull('embedding')->exists();

        if ($hasEmbeddings) {
            try {
                return (clone $builder)
                    ->whereNotNull('embedding')
                    ->whereVectorSimilarTo('embedding', $query, minSimilarity: 0.55)
                    ->limit($limit)
                    ->get();
            } catch (QueryException) {
                // Fall through to keyword search.
            }
        }

        $operator = config('database.default') === 'pgsql' ? 'ilike' : 'like';

        return $builder
            ->where(function ($q) use ($query, $operator) {
                $q->where('customer_message', $operator, "%{$query}%")
                    ->orWhere('final_reply', $operator, "%{$query}%")
                    ->orWhere('intent', $operator, "%{$query}%");
            })
            ->latest()
            ->limit($limit)
            ->get();
    }
}
