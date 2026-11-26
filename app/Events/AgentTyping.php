<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AgentTyping implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  string|null  $chatChannel  the conversation's `livechat.{chat_token}`
     *                                    channel (Conversation::chatChannelName()); null = nobody to tell
     */
    public function __construct(
        public readonly int $conversationId,
        public readonly string $agentName,
        public readonly ?string $chatChannel = null,
    ) {}

    public function broadcastOn(): array
    {
        return $this->chatChannel === null ? [] : [new Channel($this->chatChannel)];
    }

    public function broadcastWhen(): bool
    {
        return $this->chatChannel !== null;
    }

    public function broadcastAs(): string
    {
        return 'agent.typing';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'agent_name' => $this->agentName,
        ];
    }
}
