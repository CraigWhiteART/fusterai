<?php

namespace App\Domains\Conversation\Support;

use App\Domains\Conversation\Models\AiSuggestion;
use App\Domains\Conversation\Models\Conversation;
use App\Enums\ThreadType;
use App\Models\User;
use App\Support\Hooks;

/**
 * The one payload the conversation view is built from, shared by the split
 * inbox (`Conversations/Index` → `selected`) and the deep-link page
 * (`Conversations/Show` → `detail`). Typed on the frontend as
 * `ConversationDetail` in resources/js/Components/conversation/types.ts.
 */
class ConversationDetailPresenter
{
    public function __construct(private readonly RelatedConversations $related) {}

    /**
     * @return array{
     *     conversation: Conversation,
     *     isFollowing: bool,
     *     folderIds: list<int>,
     *     aiSuggestion: array{id: int, content: string, created_at: mixed}|null,
     *     related: list<array<string, mixed>>,
     *     extra: array<string, mixed>,
     * }
     */
    public function present(Conversation $conversation, User $user): array
    {
        $conversation->loadMissing([
            'customer',
            'mailbox',
            'assignedUser',
            'tags',
            'folders',
            'followers:id,name,avatar',
            'threads' => fn ($q) => $q->with(['user', 'customer', 'attachments']),
        ]);

        $suggestion = $this->pendingDraft($conversation);

        return [
            'conversation' => $conversation,
            'isFollowing' => $conversation->followers->contains('id', $user->id),
            'folderIds' => $conversation->folders->pluck('id')->values()->all(),
            'aiSuggestion' => $suggestion ? [
                'id' => $suggestion->id,
                'content' => $suggestion->content,
                'created_at' => $suggestion->created_at,
            ] : null,
            // The same customer's other conversations (strip above the thread, list in the side panel).
            'related' => $this->related->for($conversation, mailboxIds: RelatedConversations::visibleMailboxIds($user)),
            // Module payloads (survey, sla, …). Controllers also spread
            // these at the top level for module components that read usePage().props.
            'extra' => Hooks::applyFilters('conversation.show.extra', [], $conversation),
        ];
    }

    /**
     * The latest reply draft that is still actionable: neither accepted
     * (accepted = true) nor dismissed (accepted = false, set by
     * POST /agent/suggestions/{id}/dismiss), and not superseded by an agent
     * message written after it.
     */
    private function pendingDraft(Conversation $conversation): ?AiSuggestion
    {
        /** @var AiSuggestion|null $suggestion */
        $suggestion = $conversation->aiSuggestions()
            ->where('type', 'reply')
            ->latest()
            ->latest('id')
            ->first();

        // The newest draft decides: once it is accepted or dismissed, an older
        // one is never offered in its place.
        if (! $suggestion || $suggestion->accepted !== null) {
            return null;
        }

        // Agent side = not from the customer: covers app replies and ones sent from Gmail
        // by an address that is not a user (those have no user_id).
        $answeredAfter = $conversation->threads
            ->contains(fn ($thread) => $thread->type === ThreadType::Message
                && $thread->customer_id === null
                && $thread->created_at->gt($suggestion->created_at));

        return $answeredAfter ? null : $suggestion;
    }
}
