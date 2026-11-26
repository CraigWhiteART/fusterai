<?php

namespace App\Domains\Agent\AutoSend;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * QA scores of auto-sent replies, for the QA circuit breaker. Reads
 * `agent_qa_reviews` defensively (the QA feature may not be installed):
 * no table / columns, or any error = no signal. Bound in the container, so
 * the QA domain can replace it with its own implementation.
 */
class QaSignal
{
    /**
     * @param  list<int>  $threadIds  auto-sent reply threads
     * @return array{count: int, avg: float|null}|null null = QA not available
     */
    public function forThreads(array $threadIds): ?array
    {
        if ($threadIds === []) {
            return ['count' => 0, 'avg' => null];
        }

        try {
            if (! Schema::hasTable('agent_qa_reviews') || ! Schema::hasColumns('agent_qa_reviews', ['reviewed_thread_id', 'overall', 'status'])) {
                return null;
            }

            $query = DB::table('agent_qa_reviews')
                ->whereIn('reviewed_thread_id', $threadIds)
                ->where('status', 'reviewed')
                ->whereNotNull('overall');
            if (Schema::hasColumn('agent_qa_reviews', 'human_verdict')) {
                // A review a human marked dismissed / incorrect does not count.
                $query->whereNull('human_verdict');
            }

            $row = $query->selectRaw('count(*) as n, avg(overall) as avg')->first();

            return ['count' => (int) ($row->n ?? 0), 'avg' => isset($row->avg) ? (float) $row->avg : null];
        } catch (Throwable $e) {
            Log::warning('QA signal unavailable', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
