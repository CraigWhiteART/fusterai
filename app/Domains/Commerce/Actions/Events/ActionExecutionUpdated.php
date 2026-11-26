<?php

namespace App\Domains\Commerce\Actions\Events;

use App\Domains\Commerce\Actions\Models\ActionExecution;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `agent.action.updated` on private-workspace.{id} (approvals page / queue
 * badge) and private-conversation.{id} (inline action card). Small payload:
 * re-fetch the execution it names.
 */
class ActionExecutionUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public const NAME = 'agent.action.updated';

    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public readonly int $workspaceId,
        public readonly ?int $conversationId,
        public readonly array $payload,
    ) {}

    public static function dispatchFor(ActionExecution $execution): void
    {
        try {
            broadcast(new self($execution->workspace_id, $execution->conversation_id, [
                'execution_id' => $execution->id,
                'conversation_id' => $execution->conversation_id,
                'run_id' => $execution->run_id,
                'action_key' => $execution->action_key,
                'status' => $execution->status,
                'pending_count' => ActionExecution::where('workspace_id', $execution->workspace_id)->where('status', ActionExecution::PENDING_APPROVAL)->count(),
            ]));
        } catch (Throwable $e) {
            Log::warning('Action broadcast failed', ['execution_id' => $execution->id, 'error' => $e->getMessage()]);
        }
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel("workspace.{$this->workspaceId}")];
        if ($this->conversationId !== null) {
            $channels[] = new PrivateChannel("conversation.{$this->conversationId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
