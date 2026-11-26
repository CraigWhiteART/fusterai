<?php

namespace App\Ai\Retrieval;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Keyword search over every row, plus vector search over rows that have an
 * embedding, merged with reciprocal rank fusion. Rows without an embedding
 * stay findable by keyword, so retrieval works with embeddings off or only
 * partly indexed.
 *
 * Keyword search on Postgres is full-text search computed at query time
 * (no stored tsvector yet): the query's lexemes are OR-ed together and rows
 * are ranked with ts_rank, so a whole customer email can be the query.
 * Other databases get a plain token LIKE match.
 */
class HybridRetriever
{
    /** Reciprocal rank fusion constant (the usual value from the RRF paper). */
    private const RRF_K = 60;

    private const MAX_QUERY_CHARS = 2000;

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $base  Query already scoped to the rows that may be returned.
     * @param  list<string>  $textColumns  Columns searched by keyword.
     * @param  list<float>|null  $queryVector  Query embedding, or null for keyword only.
     * @param  list<string>  $columns
     * @return Collection<int, TModel>
     */
    public function search(
        Builder $base,
        array $textColumns,
        string $query,
        ?array $queryVector,
        int $limit,
        float $minSimilarity,
        array $columns = ['*'],
        string $embeddingColumn = 'embedding',
        ?string $tsvColumn = null,
    ): Collection {
        $query = mb_substr(trim($query), 0, self::MAX_QUERY_CHARS);
        $limit = max(1, $limit);

        if ($query === '') {
            return collect();
        }

        $vectorHits = $queryVector !== null
            ? $this->vectorSearch(clone $base, $embeddingColumn, $queryVector, $limit, $minSimilarity, $columns)
            : collect();

        $keywordHits = $this->keywordSearch(clone $base, $textColumns, $query, $limit, $columns, $tsvColumn);

        return $this->fuse([$vectorHits, $keywordHits], $limit);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $builder
     * @param  list<string>  $textColumns
     * @param  list<string>  $columns
     * @param  string|null  $tsvColumn  A stored tsvector column (built with the same text-search config) to search instead of computing one.
     * @return Collection<int, TModel>
     */
    public function keywordSearch(Builder $builder, array $textColumns, string $query, int $limit, array $columns = ['*'], ?string $tsvColumn = null): Collection
    {
        $query = mb_substr(trim($query), 0, self::MAX_QUERY_CHARS);

        if ($query === '' || $textColumns === []) {
            return collect();
        }

        if ($builder->getModel()->getConnection()->getDriverName() === 'pgsql') {
            return $this->fullTextSearch($builder, $textColumns, $query, $limit, $columns, $tsvColumn);
        }

        return $this->likeSearch($builder, $textColumns, $query, $limit, $columns);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $builder
     * @param  list<string>  $textColumns
     * @param  list<string>  $columns
     * @return Collection<int, TModel>
     */
    private function fullTextSearch(Builder $builder, array $textColumns, string $query, int $limit, array $columns, ?string $tsvColumn = null): Collection
    {
        $grammar = $builder->getQuery()->getGrammar();
        $config = $this->ftsConfig();

        if ($tsvColumn !== null) {
            $vector = $grammar->wrap($tsvColumn);
            $vectorBindings = [];
        } else {
            $document = implode(" || ' ' || ", array_map(
                fn (string $column) => 'coalesce('.$grammar->wrap($column)."::text, '')",
                $textColumns,
            ));
            $vector = "to_tsvector(?::regconfig, {$document})";
            $vectorBindings = [$config];
        }

        // plainto_tsquery() normalises the text (stemming, stop words) and
        // joins lexemes with AND; swapping & for | gives OR semantics.
        $tsquery = "(replace(plainto_tsquery(?::regconfig, ?)::text, ' & ', ' | '))::tsquery";
        $bindings = [...$vectorBindings, $config, $query];

        /** @var Collection<int, TModel> $rows */
        $rows = $builder
            ->whereRaw("{$vector} @@ {$tsquery}", $bindings)
            ->orderByRaw("ts_rank({$vector}, {$tsquery}, 1) desc", $bindings)
            ->orderByDesc($builder->getModel()->getQualifiedKeyName())
            ->limit($limit)
            ->get($columns);

        return $rows;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $builder
     * @param  list<string>  $textColumns
     * @param  list<string>  $columns
     * @return Collection<int, TModel>
     */
    private function likeSearch(Builder $builder, array $textColumns, string $query, int $limit, array $columns): Collection
    {
        $terms = $this->terms($query);

        if ($terms === []) {
            return collect();
        }

        $rows = $builder
            ->where(function (Builder $q) use ($terms, $textColumns) {
                foreach ($terms as $term) {
                    foreach ($textColumns as $column) {
                        $q->orWhere($column, 'like', '%'.$term.'%');
                    }
                }
            })
            ->limit($limit * 5)
            ->get($columns);

        /** @var Collection<int, TModel> $ranked */
        $ranked = $rows
            ->map(function (Model $row) use ($terms, $textColumns) {
                $haystack = mb_strtolower(implode(' ', array_map(fn ($c) => (string) $row->getAttribute($c), $textColumns)));
                $score = count(array_filter($terms, fn ($term) => str_contains($haystack, $term)));

                return [$score, $row];
            })
            ->sortByDesc(fn (array $pair) => $pair[0])
            ->take($limit)
            ->map(fn (array $pair) => $pair[1])
            ->values();

        return $ranked;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $builder
     * @param  list<float>  $vector
     * @param  list<string>  $columns
     * @return Collection<int, TModel>
     */
    private function vectorSearch(Builder $builder, string $column, array $vector, int $limit, float $minSimilarity, array $columns): Collection
    {
        if ($builder->getModel()->getConnection()->getDriverName() !== 'pgsql') {
            return collect();
        }

        try {
            /** @var Collection<int, TModel> $rows */
            $rows = $builder
                ->whereNotNull($column)
                ->whereVectorSimilarTo($column, $vector, minSimilarity: $minSimilarity)
                ->limit($limit)
                ->get($columns);

            return $rows;
        } catch (QueryException $e) {
            Log::warning('Vector search failed; using keyword results only', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * @template TModel of Model
     *
     * @param  list<Collection<int, TModel>>  $rankings
     * @return Collection<int, TModel>
     */
    private function fuse(array $rankings, int $limit): Collection
    {
        $scores = [];
        $models = [];

        foreach ($rankings as $ranking) {
            foreach ($ranking->values() as $rank => $model) {
                $key = (string) $model->getKey();
                $scores[$key] = ($scores[$key] ?? 0) + 1 / (self::RRF_K + $rank + 1);
                $models[$key] ??= $model;
            }
        }

        // usort is stable, so ties keep first-seen order (vector hits first).
        $order = array_map('strval', array_keys($scores));
        usort($order, fn ($a, $b) => $scores[$b] <=> $scores[$a]);

        return collect(array_slice($order, 0, $limit))->map(fn ($key) => $models[$key])->values();
    }

    /**
     * @return list<string>
     */
    private function terms(string $query): array
    {
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($query), $matches);
        $terms = array_values(array_unique($matches[0]));
        usort($terms, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return array_slice($terms, 0, 12);
    }

    private function ftsConfig(): string
    {
        $config = (string) config('ai.rag.fts_config', 'english');

        return preg_match('/^[a-z_]+$/', $config) === 1 ? $config : 'simple';
    }
}
