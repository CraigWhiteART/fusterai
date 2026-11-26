<?php

namespace App\Domains\Agent\Jobs;

use App\Domains\Agent\Feedback\FeedbackService;
use App\Domains\Agent\Models\FeedbackEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Routes one feedback comment (manager call, proposal, conflict check,
 * preview). Interactive: `ai` queue, below its 330s supervisor timeout.
 */
class RouteFeedbackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 320;

    public function __construct(public readonly int $feedbackId)
    {
        $this->onQueue('ai');
    }

    public function handle(FeedbackService $feedback): void
    {
        $event = FeedbackEvent::find($this->feedbackId);
        if ($event !== null) {
            $feedback->process($event);
        }
    }
}
