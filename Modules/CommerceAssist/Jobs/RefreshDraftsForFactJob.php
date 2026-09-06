<?php

namespace Modules\CommerceAssist\Jobs;

use App\Domains\AI\Jobs\GenerateReplySuggestionJob;
use App\Domains\Conversation\Models\Conversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\CommerceAssist\Models\LiveFact;

class RefreshDraftsForFactJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    /**
     * @param  list<int>  $conversationIds
     */
    public function __construct(
        public readonly LiveFact $fact,
        public readonly array $conversationIds,
    ) {
        $this->onQueue('ai');
    }

    public function handle(): void
    {
        $ids = array_values(array_unique(array_filter($this->conversationIds)));

        foreach ($ids as $id) {
            $conversation = Conversation::find($id);
            if (! $conversation) {
                continue;
            }

            GenerateReplySuggestionJob::dispatch($conversation)->onQueue('ai');
        }

        $this->fact->update([
            'refresh_completed_at' => now(),
            'refreshed_conversation_ids' => $ids,
        ]);
    }
}
