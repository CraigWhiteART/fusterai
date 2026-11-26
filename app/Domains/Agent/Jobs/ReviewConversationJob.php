<?php

namespace App\Domains\Agent\Jobs;

use App\Domains\Agent\Qa\Models\QaReview;
use App\Domains\Agent\Qa\QaReviewer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One QA review (first pass, and the full review when flagged / sampled /
 * forced). `ai` queue for closes and forced reviews, `ai-bulk` for the
 * backfill. Idempotent: only a pending review row is worked on.
 */
class ReviewConversationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $reviewId)
    {
        $this->onQueue((string) config('agent_qa.queue', 'ai'));
    }

    public function handle(QaReviewer $reviewer): void
    {
        $review = QaReview::find($this->reviewId);
        if ($review !== null && $review->status === QaReview::STATUS_PENDING) {
            $reviewer->review($review);
        }
    }
}
