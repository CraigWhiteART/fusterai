<?php

namespace App\Domains\Agent\Jobs;

use App\Domains\Agent\Manager\ManagerChatService;
use App\Domains\Agent\Manager\Models\ManagerMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * One manager-chat turn. Interactive: `ai` queue, below its 330s
 * supervisor timeout (the turn itself stops looking things up after
 * agent_manager.turn_seconds). Never retried: a retry would re-run tools.
 */
class ManagerTurnJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 320;

    public function __construct(public readonly int $messageId)
    {
        $this->onQueue((string) config('agent_manager.queue', 'ai'));
    }

    public function handle(ManagerChatService $service): void
    {
        $message = ManagerMessage::find($this->messageId);
        if ($message !== null) {
            $service->runTurn($message);
        }
    }

    public function failed(Throwable $e): void
    {
        ManagerMessage::whereKey($this->messageId)->whereIn('status', ManagerMessage::OPEN_STATUSES)->update([
            'status' => ManagerMessage::STATUS_FAILED,
            'error' => class_basename($e).': '.mb_substr($e->getMessage(), 0, 500),
            'completed_at' => now(),
        ]);
    }
}
