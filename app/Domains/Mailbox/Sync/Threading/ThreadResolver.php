<?php

namespace App\Domains\Mailbox\Sync\Threading;

use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Mailbox\Sync\Enums\MailDirection;
use App\Domains\Mailbox\Sync\Models\MailMessage;
use App\Domains\Mailbox\Sync\Parsing\MessageClassifier;
use App\Domains\Mailbox\Sync\Parsing\ParsedMessage;

/**
 * Decides direction, customer and target conversation for one message, using
 * only the ledger (mail_messages) and existing conversations. No IMAP, and it
 * never creates anything.
 *
 * Conversation lookup order:
 *  0. the message itself is already linked in the ledger (re-processing);
 *  1. the Sent copy of a reply fusterai sent (Message-ID thread-N-...@fusterai
 *     or X-FusterAI-Thread): that thread already exists;
 *  2. same Gmail thread (X-GM-THRID) AND same customer. Gmail groups by
 *     subject + participants, so two different customers using the same
 *     Shopify contact form ("New customer message on ...") land in one Gmail
 *     thread; the customer check keeps them apart;
 *  3. In-Reply-To / References against message_ids already in the ledger,
 *     then against threads (threads.message_id and pre-ledger thread meta);
 *  4. legacy fusterai ids: conversation-N@fusterai, thread-N-...@fusterai;
 *  5. otherwise a new conversation.
 */
final class ThreadResolver
{
    public const LEGACY_CONVERSATION = '/^conversation-(\d+)@fusterai$/i';

    public const LEGACY_THREAD = '/^thread-(\d+)-[^@]*@fusterai$/i';

    /** Contact-form relays: when they send, the customer is in Reply-To. */
    public const DEFAULT_FORM_RELAY_DOMAINS = [
        'shopify.com', 'shopifyemail.com', 'wix.com', 'wixforms.com', 'squarespace.com',
        'squarespace-mail.com', 'jotform.com', 'typeform.com', 'formspree.io', 'wpforms.com',
    ];

    /** @param list<string> $formRelayDomains */
    public function __construct(private readonly array $formRelayDomains = self::DEFAULT_FORM_RELAY_DOMAINS) {}

    public function direction(ParsedMessage $message, MailboxIdentity $identity): MailDirection
    {
        if (! $identity->owns($message->fromEmail())) {
            return MailDirection::Inbound;
        }

        foreach (array_merge($message->to, $message->cc) as $recipient) {
            if (! $identity->owns($recipient['email'])) {
                return MailDirection::Outbound;
            }
        }

        return MailDirection::Internal;
    }

    /**
     * The customer this message is with: the sender for inbound mail (or the
     * Reply-To address when the sender is a no-reply / contact-form relay),
     * the first external recipient for outbound mail, nobody for internal.
     *
     * @return array{email: string, name: string}|null
     */
    public function customer(ParsedMessage $message, MailboxIdentity $identity, ?MailDirection $direction = null): ?array
    {
        $direction ??= $this->direction($message, $identity);

        if ($direction === MailDirection::Internal) {
            return null;
        }

        if ($direction === MailDirection::Outbound) {
            foreach (array_merge($message->to, $message->cc) as $recipient) {
                if (! $identity->owns($recipient['email'])) {
                    return $recipient;
                }
            }

            return null;
        }

        $from = $message->from;
        $replyTo = null;
        foreach ($message->replyTo as $address) {
            if (! $identity->owns($address['email']) && strcasecmp($address['email'], (string) ($from['email'] ?? '')) !== 0) {
                $replyTo = $address;
                break;
            }
        }

        if ($replyTo !== null && ($from === null || $this->isRelaySender($from['email']))) {
            return $replyTo;
        }

        return $from;
    }

    public function resolve(
        int $mailboxId,
        MailboxIdentity $identity,
        ParsedMessage $message,
        ?string $gmThrid = null,
        ?string $gmMsgid = null,
    ): ThreadDecision {
        $direction = $this->direction($message, $identity);
        $customer = $this->customer($message, $identity, $direction);
        $customerEmail = $customer !== null ? strtolower($customer['email']) : null;
        $customerName = $customer !== null ? ($customer['name'] !== '' ? $customer['name'] : null) : null;

        $decide = fn (?int $conversationId, string $by, ?int $threadId = null, array $notes = []) => new ThreadDecision(
            $direction, $customerEmail, $customerName, $conversationId, $by, $threadId, $notes,
        );

        // 0. Already linked (the message is being processed again).
        $self = $this->selfRow($mailboxId, $message, $gmMsgid);
        if ($self !== null && $self->conversation_id !== null && $this->conversationExists($mailboxId, $self->conversation_id)) {
            return $decide($self->conversation_id, ThreadDecision::MATCH_ALREADY_LINKED, $self->thread_id);
        }

        // 1. Our own outbound reply, seen again as Gmail's Sent copy.
        if ($direction !== MailDirection::Inbound) {
            $threadId = $this->ownThreadId($message);
            if ($threadId !== null) {
                $conversationId = $this->conversationForThread($mailboxId, $threadId);
                if ($conversationId !== null) {
                    return $decide($conversationId, ThreadDecision::MATCH_LEGACY_THREAD, $threadId, ['sent copy of a fusterai reply']);
                }
            }
        }

        $notes = [];

        // 2. Gmail thread, scoped to the same customer.
        if ($gmThrid !== null && $customerEmail !== null) {
            $rows = MailMessage::query()
                ->where('mailbox_id', $mailboxId)
                ->where('gm_thrid', $gmThrid)
                ->whereNotNull('conversation_id')
                ->when($gmMsgid !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('gm_msgid')->orWhere('gm_msgid', '!=', $gmMsgid)))
                ->orderByDesc('sent_at')
                ->orderByDesc('id')
                ->get(['id', 'conversation_id', 'customer_email']);

            foreach ($rows as $row) {
                if ($row->customer_email !== null && strcasecmp($row->customer_email, $customerEmail) === 0
                    && $this->conversationExists($mailboxId, (int) $row->conversation_id)) {
                    return $decide((int) $row->conversation_id, ThreadDecision::MATCH_GM_THREAD);
                }
            }
            if ($rows->isNotEmpty()) {
                $notes[] = 'gmail thread belongs to a different customer; not merged';
            }
        }

        $referenced = $message->referencedIds();

        // 3. In-Reply-To / References against the ledger.
        if ($referenced !== []) {
            $rows = MailMessage::query()
                ->where('mailbox_id', $mailboxId)
                ->whereIn('message_id', $referenced)
                ->whereNotNull('conversation_id')
                ->get(['message_id', 'conversation_id'])
                ->groupBy('message_id');

            foreach ($referenced as $id) {
                foreach ($rows->get($id, collect()) as $row) {
                    if ($this->conversationExists($mailboxId, (int) $row->conversation_id)) {
                        return $decide((int) $row->conversation_id, ThreadDecision::MATCH_REFERENCES, null, $notes);
                    }
                }
            }
        }

        // 3b. The same ids against threads: threads.message_id, plus the meta
        //     written before the ledger existed (ProcessInboundEmailJob's
        //     message_id, SendReplyJob's outbound_message_id).
        if ($referenced !== []) {
            $conversationId = $this->conversationForReferencedThread($mailboxId, $referenced);
            if ($conversationId !== null) {
                return $decide($conversationId, ThreadDecision::MATCH_REFERENCES, null, $notes);
            }
        }

        // 4. Legacy fusterai Message-IDs. These carry only a bare row id, so a
        //    mail from another install (or from before a database reset) can
        //    point at an unrelated conversation: require the same customer.
        foreach ($referenced as $id) {
            if (preg_match(self::LEGACY_CONVERSATION, $id, $m)
                && $this->conversationBelongsTo($mailboxId, (int) $m[1], $customerEmail)) {
                return $decide((int) $m[1], ThreadDecision::MATCH_LEGACY_CONVERSATION, null, $notes);
            }
            if (preg_match(self::LEGACY_THREAD, $id, $m)) {
                $conversationId = $this->conversationForThread($mailboxId, (int) $m[1]);
                if ($conversationId !== null && $this->conversationBelongsTo($mailboxId, $conversationId, $customerEmail)) {
                    return $decide($conversationId, ThreadDecision::MATCH_LEGACY_THREAD, null, $notes);
                }
            }
        }

        return $decide(null, ThreadDecision::MATCH_NONE, null, $notes);
    }

    private function isRelaySender(string $email): bool
    {
        if (MessageClassifier::isNoReplyAddress($email)) {
            return true;
        }

        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
        foreach ($this->formRelayDomains as $relay) {
            $relay = strtolower($relay);
            if ($domain === $relay || str_ends_with($domain, '.'.$relay)) {
                return true;
            }
        }

        return false;
    }

    private function selfRow(int $mailboxId, ParsedMessage $message, ?string $gmMsgid): ?MailMessage
    {
        $query = MailMessage::query()->where('mailbox_id', $mailboxId);

        if ($gmMsgid !== null) {
            return $query->where('gm_msgid', $gmMsgid)->first(['id', 'conversation_id', 'thread_id']);
        }
        if ($message->messageId !== null) {
            return $query->whereNull('gm_msgid')->where('message_id', $message->messageId)->first(['id', 'conversation_id', 'thread_id']);
        }

        return null;
    }

    private function ownThreadId(ParsedMessage $message): ?int
    {
        $header = $message->header('x-fusterai-thread');
        if ($header !== null && ctype_digit(trim($header))) {
            return (int) trim($header);
        }

        if ($message->messageId !== null && preg_match(self::LEGACY_THREAD, $message->messageId, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /** @param list<string> $ids most relevant first */
    private function conversationForReferencedThread(int $mailboxId, array $ids): ?int
    {
        $bracketed = array_map(fn (string $id) => '<'.$id.'>', $ids);

        $threads = Thread::query()
            ->whereHas('conversation', fn ($q) => $q->where('mailbox_id', $mailboxId))
            ->where(fn ($q) => $q->whereIn('message_id', $ids)
                ->orWhereIn('meta->message_id', array_merge($ids, $bracketed))
                ->orWhereIn('meta->outbound_message_id', $bracketed))
            ->get(['id', 'conversation_id', 'message_id', 'meta']);

        foreach ($ids as $id) {
            foreach ($threads as $thread) {
                $meta = $thread->meta ?? [];
                $candidates = [$thread->message_id, $meta['message_id'] ?? null, $meta['outbound_message_id'] ?? null];
                foreach ($candidates as $candidate) {
                    if (is_string($candidate) && trim($candidate, '<> ') === $id) {
                        return (int) $thread->conversation_id;
                    }
                }
            }
        }

        return null;
    }

    private function conversationForThread(int $mailboxId, int $threadId): ?int
    {
        $conversationId = Thread::query()->whereKey($threadId)->value('conversation_id');

        return $conversationId !== null && $this->conversationExists($mailboxId, (int) $conversationId) ? (int) $conversationId : null;
    }

    private function conversationBelongsTo(int $mailboxId, int $conversationId, ?string $customerEmail): bool
    {
        if ($customerEmail === null) {
            return false;
        }

        return Conversation::query()
            ->whereKey($conversationId)
            ->where('mailbox_id', $mailboxId)
            ->whereHas('customer', fn ($q) => $q->whereRaw('lower(email) = ?', [$customerEmail]))
            ->exists();
    }

    private function conversationExists(int $mailboxId, int $conversationId): bool
    {
        return Conversation::query()->whereKey($conversationId)->where('mailbox_id', $mailboxId)->exists();
    }
}
