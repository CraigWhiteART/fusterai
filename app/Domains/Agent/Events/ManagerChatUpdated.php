<?php

namespace App\Domains\Agent\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Manager-chat progress on the requesting user's private channel
 * (private-App.Models.User.{id}). Names: see docs/agent-manager.md "Events".
 */
class ManagerChatUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public const STARTED = 'agent.manager.started';

    public const STEP = 'agent.manager.step';

    public const FINISHED = 'agent.manager.finished';

    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public readonly int $userId,
        public readonly string $name,
        public readonly array $payload,
    ) {}

    /** @param  array<string, mixed>  $payload */
    public static function dispatchSafely(?int $userId, string $name, array $payload): void
    {
        if ($userId === null) {
            return;
        }
        try {
            broadcast(new self($userId, $name, $payload));
        } catch (Throwable $e) {
            Log::warning('Manager chat broadcast failed', ['event' => $name, 'error' => $e->getMessage()]);
        }
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("App.Models.User.{$this->userId}");
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
