<?php

namespace App\Domains\Mailbox\Sync;

use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;

/** What MailIngestService did with one ledger row. */
final readonly class IngestResult
{
    /** A new thread was created from the message. */
    public const CREATED = 'created';

    /** The message is a thread we already had (Sent copy of a fusterai reply, or a pre-ledger import); linked, nothing created. */
    public const LINKED = 'linked';

    /** The ledger row was already linked to a thread. */
    public const ALREADY_INGESTED = 'already_ingested';

    /** Not a human support message (bulk, auto-reply, notification, no From). */
    public const SKIPPED_AUTOMATED = 'skipped_automated';

    /** Between our own addresses only. */
    public const SKIPPED_INTERNAL = 'skipped_internal';

    /** No customer address could be determined. */
    public const SKIPPED_NO_CUSTOMER = 'skipped_no_customer';

    /** A Gmail draft (\Draft label): never sent, never a thread. */
    public const SKIPPED_DRAFT = 'skipped_draft';

    /** Ledger row or mailbox no longer exists. */
    public const MISSING = 'missing';

    public function __construct(
        public string $outcome,
        public ?Conversation $conversation = null,
        public ?Thread $thread = null,
        public bool $newConversation = false,
    ) {}

    public function createdThread(): bool
    {
        return $this->outcome === self::CREATED;
    }
}
