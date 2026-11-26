<?php

namespace App\Domains\Agent\Jobs;

use App\Domains\Agent\Events\AgentLoopUpdated;
use App\Domains\Agent\Simulation\FrozenCaseFactory;
use App\Domains\Conversation\Models\Conversation;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Freezes past conversations (default: imported history) into cases, one
 * per customer message a human answered. Database only: no Shopify or
 * carrier call. Long queue; resumes after the last conversation id.
 */
class BuildFrozenCasesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 880;

    /** @param  array<string, mixed>  $filters  date_from, date_to, imported_only (default true), limit (conversations, default 200), per_conversation (default 3) */
    public function __construct(public readonly int $workspaceId, public readonly array $filters = [], public readonly int $afterId = 0, public readonly int $seen = 0)
    {
        $this->onConnection((string) config('agent.simulation.batch_connection', 'redis-long'));
        $this->onQueue((string) config('agent.simulation.batch_queue', 'ai-bulk'));
    }

    public function handle(FrozenCaseFactory $factory): void
    {
        $limit = max(1, min((int) ($this->filters['limit'] ?? 200), 5000));
        $per = max(1, min((int) ($this->filters['per_conversation'] ?? 3), 20));
        $deadline = microtime(true) + (int) config('agent.simulation.batch_step_seconds', 600);
        $seen = $this->seen;
        $cases = 0;

        $query = Conversation::where('workspace_id', $this->workspaceId)->where('id', '>', $this->afterId)->orderBy('id');
        if ((bool) ($this->filters['imported_only'] ?? true)) {
            $query->where('imported', true);
        }
        if (filled($this->filters['date_from'] ?? null)) {
            $query->where('created_at', '>=', CarbonImmutable::parse((string) $this->filters['date_from'])->startOfDay());
        }
        if (filled($this->filters['date_to'] ?? null)) {
            $query->where('created_at', '<=', CarbonImmutable::parse((string) $this->filters['date_to'])->endOfDay());
        }

        foreach ($query->limit(max(0, $limit - $seen))->get() as $conversation) {
            $seen++;
            try {
                $cases += count($factory->allFromConversation($conversation, 'manual', $per));
            } catch (Throwable $e) {
                Log::info('Frozen case build skipped a conversation', ['conversation_id' => $conversation->id, 'error' => $e->getMessage()]);
            }
            if (microtime(true) >= $deadline) {
                dispatch(new self($this->workspaceId, $this->filters, $conversation->id, $seen));

                return;
            }
        }

        AgentLoopUpdated::dispatchSafely($this->workspaceId, AgentLoopUpdated::CASES_PREPARED, ['finished' => true, 'conversations' => $seen, 'cases' => $cases]);
    }
}
