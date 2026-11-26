<?php

namespace App\Domains\Conversation\Support;

use App\Domains\AI\Jobs\CategorizeConversationJob;
use App\Domains\AI\Jobs\GenerateReplySuggestionJob;
use App\Domains\Automation\Jobs\RunAutomationRulesJob;
use App\Domains\Conversation\Jobs\SendAutoReplyJob;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Customer\Models\Customer;
use App\Enums\ConversationStatus;
use App\Events\ConversationUpdated;
use App\Events\NewThreadReceived;
use App\Models\User;
use App\Notifications\NewCustomerReplyNotification;
use App\Services\AiSettingsService;
use App\Support\Hooks;

/**
 * What happens after a message has been stored on a conversation by mail
 * (IngestMailMessageJob). Runs after the database transaction, never
 * inside it.
 */
final class InboundMessageEffects
{
    public function __construct(private readonly AiSettingsService $ai) {}

    /**
     * A customer wrote. Order matters: a blocked sender's conversation is
     * marked spam before anything else runs, and nothing that acts on the
     * message (automation, AI, notification, auto-reply) runs for it.
     */
    public function afterCustomerMessage(Conversation $conversation, Thread $thread, bool $isNewConversation, bool $allowAutoReply = true): void
    {
        $sender = $thread->customer_id !== null ? Customer::find($thread->customer_id) : null;
        $owner = $conversation->customer_id === $thread->customer_id ? $sender : Customer::find($conversation->customer_id);
        $blocked = (bool) ($sender?->is_blocked || ($isNewConversation && $owner?->is_blocked));

        if ($blocked && $conversation->status !== ConversationStatus::Spam) {
            $conversation->update(['status' => ConversationStatus::Spam]);
        }

        if ($isNewConversation) {
            Hooks::doAction('conversation.created', $conversation);
        }
        Hooks::doAction('thread.created', $thread);

        broadcast(new NewThreadReceived($thread->load(['user', 'customer', 'attachments'])));
        broadcast(new ConversationUpdated($conversation->fresh() ?? $conversation));

        if ($blocked) {
            return;
        }

        RunAutomationRulesJob::dispatch($isNewConversation ? 'conversation.created' : 'conversation.replied', $conversation);

        if ($this->ai->isFeatureEnabled($conversation->workspace_id, 'reply_suggestions')) {
            GenerateReplySuggestionJob::dispatch($conversation)->onQueue('ai');
        }
        if ($isNewConversation && $this->ai->isFeatureEnabled($conversation->workspace_id, 'auto_categorization')) {
            CategorizeConversationJob::dispatch($conversation)->onQueue('ai');
        }

        if ($conversation->assigned_user_id) {
            User::find($conversation->assigned_user_id)?->notify(new NewCustomerReplyNotification($conversation, $thread));
        }

        if ($isNewConversation && $allowAutoReply) {
            SendAutoReplyJob::dispatch($conversation)->onQueue('email-outbound');
        }
    }

    /**
     * Staff replied outside fusterai (Gmail). Hooks and broadcasts only: the
     * conversation is not reopened, and no AI draft, automation or auto-reply
     * runs. thread.created lets listeners treat it like a sent reply (e.g.
     * EditLearningService closes the pending draft when the thread has a user).
     */
    public function afterAgentMessage(Conversation $conversation, Thread $thread): void
    {
        Hooks::doAction('thread.created', $thread);

        broadcast(new NewThreadReceived($thread->load(['user', 'customer', 'attachments'])));
        broadcast(new ConversationUpdated($conversation->fresh() ?? $conversation));
    }
}
