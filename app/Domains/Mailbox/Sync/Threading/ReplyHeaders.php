<?php

namespace App\Domains\Mailbox\Sync\Threading;

use App\Domains\Conversation\Models\Conversation;
use App\Domains\Mailbox\Sync\Enums\MailDirection;
use App\Domains\Mailbox\Sync\Models\MailMessage;

/**
 * In-Reply-To / References for mail fusterai sends on a conversation, so the
 * customer's client (and Gmail) threads it under their own last message.
 *
 * Uses the customer's most recent inbound ledger message. Without one (an
 * agent-started conversation, or one from before the ledger) it falls back
 * to the legacy <conversation-N@fusterai> id that ThreadResolver still maps.
 */
final class ReplyHeaders
{
    /** References longer than this keep the root and the newest ids (RFC 5322 3.6.4 allows trimming). */
    private const MAX_REFERENCES = 20;

    /**
     * Header values include the angle brackets.
     *
     * @return array{in_reply_to: string, references: string, parent: ?MailMessage}
     */
    public static function forConversation(Conversation $conversation): array
    {
        $parent = MailMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MailDirection::Inbound->value)
            ->whereNotNull('message_id')
            ->where('message_id', 'not like', '%@missing-message-id.invalid')
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->first();

        if ($parent === null) {
            $legacy = self::bracket(self::legacyConversationId($conversation->id));

            return ['in_reply_to' => $legacy, 'references' => $legacy, 'parent' => null];
        }

        $chain = array_values(array_unique(array_merge(
            array_values(array_filter($parent->references ?? [], 'is_string')),
            [(string) $parent->message_id],
        )));
        if (count($chain) > self::MAX_REFERENCES) {
            $chain = array_merge([$chain[0]], array_slice($chain, -(self::MAX_REFERENCES - 1)));
        }

        return [
            'in_reply_to' => self::bracket((string) $parent->message_id),
            'references' => implode(' ', array_map(self::bracket(...), $chain)),
            'parent' => $parent,
        ];
    }

    public static function legacyConversationId(int $conversationId): string
    {
        return 'conversation-'.$conversationId.'@fusterai';
    }

    private static function bracket(string $id): string
    {
        return '<'.trim($id, '<> ').'>';
    }
}
