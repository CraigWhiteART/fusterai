<?php

namespace App\Services;

use App\Domains\Channel\Jobs\HandleLiveChatMessageJob;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Customer\Models\Customer;
use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LiveChatService
{
    /**
     * Resolve (or create) the customer and their open chat conversation,
     * then dispatch the message job. Returns the conversation id and the
     * chat token: the widget subscribes to `livechat.{chat_token}` and sends
     * the token back on the history and typing calls.
     *
     * An open conversation is only reused for the same visitor id, so posting
     * with somebody else's email never hands over their chat (or its token).
     *
     * @return array{status: string, conversation_id: int, chat_token: string}
     */
    public function store(array $validated): array
    {
        $email = ! empty($validated['visitor_email'])
            ? $validated['visitor_email']
            : "visitor_{$validated['visitor_id']}@livechat.local";

        $customer = Customer::firstOrCreate(
            ['workspace_id' => $validated['workspace_id'], 'email' => $email],
            ['name' => $validated['visitor_name'] ?? 'Visitor'],
        );

        $conversation = DB::transaction(function () use ($validated, $customer) {
            $existing = Conversation::where('workspace_id', $validated['workspace_id'])
                ->where('customer_id', $customer->id)
                ->where('channel_type', ChannelType::Chat)
                ->where('channel_id', $validated['visitor_id'])
                ->where('status', ConversationStatus::Open)
                ->latest()
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            return Conversation::create([
                'workspace_id' => $validated['workspace_id'],
                'customer_id' => $customer->id,
                'subject' => 'Live chat with '.($validated['visitor_name'] ?? 'Visitor'),
                'status' => ConversationStatus::Open,
                'channel_type' => ChannelType::Chat,
                'channel_id' => $validated['visitor_id'],
                'last_reply_at' => now(),
            ]);
        });

        $token = $conversation->ensureChatToken();

        HandleLiveChatMessageJob::dispatch(
            $conversation,
            $customer,
            $validated['message'],
        )->onQueue('default');

        return [
            'status' => 'sent',
            'conversation_id' => $conversation->id,
            'chat_token' => $token,
        ];
    }

    /**
     * The visitor's chat conversation, or null. Proof is the chat token, or
     * (for widget sessions started before tokens existed) the visitor id the
     * conversation was opened with / the visitor's placeholder email.
     */
    public function findForVisitor(int $conversationId, int $workspaceId, ?string $chatToken, ?string $visitorId): ?Conversation
    {
        $conversation = Conversation::where('id', $conversationId)
            ->where('workspace_id', $workspaceId)
            ->where('channel_type', ChannelType::Chat)
            ->first();

        if (! $conversation) {
            return null;
        }

        if (filled($chatToken)) {
            return filled($conversation->chat_token) && hash_equals((string) $conversation->chat_token, (string) $chatToken)
                ? $conversation
                : null;
        }

        if (! filled($visitorId)) {
            return null;
        }

        if ($conversation->channel_id !== null && hash_equals((string) $conversation->channel_id, (string) $visitorId)) {
            return $conversation;
        }

        $email = "visitor_{$visitorId}@livechat.local";
        $matchesCustomer = Customer::where('id', $conversation->customer_id)
            ->where('workspace_id', $workspaceId)
            ->where('email', $email)
            ->exists();

        return $matchesCustomer ? $conversation : null;
    }

    /** Message threads of a visitor's conversation. */
    public function messages(Conversation $conversation): Collection
    {
        return $conversation->threads()
            ->with(['user:id,name,avatar', 'customer:id,name'])
            ->where('type', 'message')
            ->orderBy('created_at')
            ->get(['id', 'user_id', 'customer_id', 'body', 'created_at']);
    }
}
