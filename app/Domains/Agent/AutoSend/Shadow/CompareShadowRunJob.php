<?php

namespace App\Domains\Agent\AutoSend\Shadow;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Conversation\Models\Thread;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** One shadow comparison (judge call) on the `ai` queue. Ids only, so a deleted row is a no-op. */
class CompareShadowRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $timeout = 150;

    public function __construct(public readonly int $runId, public readonly int $threadId)
    {
        $this->onQueue('ai');
    }

    public function handle(ShadowComparer $comparer): void
    {
        $run = AgentRun::find($this->runId);
        $thread = Thread::find($this->threadId);
        if ($run !== null && $thread !== null) {
            $comparer->compare($run, $thread);
        }
    }
}
