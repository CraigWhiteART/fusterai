<?php

namespace App\Domains\Mailbox\Sync\Threading;

use App\Domains\Mailbox\Sync\Enums\MailDirection;

/**
 * What ThreadResolver concluded about one message. It is advice: creating the
 * conversation/thread is the caller's job.
 */
final readonly class ThreadDecision
{
    public const MATCH_ALREADY_LINKED = 'already_linked';

    public const MATCH_GM_THREAD = 'gm_thrid';

    public const MATCH_REFERENCES = 'references';

    public const MATCH_LEGACY_CONVERSATION = 'legacy_conversation';

    public const MATCH_LEGACY_THREAD = 'legacy_thread';

    public const MATCH_NONE = 'new';

    /**
     * @param  int|null  $existingThreadId  set when this message *is* a thread we already have
     *                                      (the Sent copy of a reply fusterai sent); do not create another
     * @param  list<string>  $notes  human-readable reasons, for logs and the import screen
     */
    public function __construct(
        public MailDirection $direction,
        public ?string $customerEmail,
        public ?string $customerName,
        public ?int $conversationId,
        public string $matchedBy,
        public ?int $existingThreadId = null,
        public array $notes = [],
    ) {}

    public function isNewConversation(): bool
    {
        return $this->conversationId === null;
    }
}
