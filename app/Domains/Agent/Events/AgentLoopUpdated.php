<?php

namespace App\Domains\Agent\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Feedback-loop progress on the workspace channel (private-workspace.{id}).
 * One class, several event names (broadcastAs): see docs/agent-proposals.md
 * "Events". Payloads are small; the UI re-fetches the resource they name.
 */
class AgentLoopUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public const FEEDBACK_ROUTED = 'agent.feedback.routed';

    public const PREVIEW_READY = 'agent.preview.ready';

    public const PROPOSAL_UPDATED = 'agent.proposal.updated';

    public const SIMULATION_PROGRESS = 'agent.simulation.progress';

    public const SIMULATION_FINISHED = 'agent.simulation.finished';

    public const CASES_PREPARED = 'agent.cases.prepared';

    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public readonly int $workspaceId,
        public readonly string $name,
        public readonly array $payload,
    ) {}

    /** Broadcasting must never break the work that triggered it. */
    public static function dispatchSafely(int $workspaceId, string $name, array $payload): void
    {
        try {
            broadcast(new self($workspaceId, $name, $payload));
        } catch (Throwable $e) {
            Log::warning('Agent loop broadcast failed', ['event' => $name, 'error' => $e->getMessage()]);
        }
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("workspace.{$this->workspaceId}");
    }

    public function broadcastAs(): string
    {
        return $this->name;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
