<?php

namespace App\Domains\Mailbox\Sync\Parsing;

use DateTimeImmutable;

/**
 * Ledger-ready view of one RFC 822 message. Produced by MessageParser from
 * either the header block alone (envelope sync) or the full source.
 */
final readonly class ParsedMessage
{
    /** Header names kept in mail_messages.headers (auto-reply / bulk detection, our own markers). */
    public const SIGNAL_HEADERS = [
        'auto-submitted',
        'precedence',
        'list-id',
        'list-unsubscribe',
        'x-auto-response-suppress',
        'x-autoreply',
        'x-autorespond',
        'x-fusterai-autoreply',
        'x-fusterai-conversation',
        'x-fusterai-thread',
        'return-path',
        'feedback-id',
    ];

    /**
     * @param  list<string>  $references  full chain, oldest first, no angle brackets
     * @param  array{email: string, name: string}|null  $from
     * @param  list<array{email: string, name: string}>  $replyTo
     * @param  list<array{email: string, name: string}>  $to
     * @param  list<array{email: string, name: string}>  $cc
     * @param  array<string, string>  $headers  lower-cased SIGNAL_HEADERS that are present
     * @param  list<array{name: string, mime: string, size: int, content_id: ?string, inline: bool, content: string}>  $attachments
     */
    public function __construct(
        public ?string $messageId,
        public bool $messageIdSynthetic,
        public ?string $inReplyTo,
        public array $references,
        public ?array $from,
        public array $replyTo,
        public array $to,
        public array $cc,
        public ?string $subject,
        public ?DateTimeImmutable $sentAt,
        public array $headers,
        public ?string $bodyHtml = null,
        public ?string $bodyText = null,
        public ?string $bodyClean = null,
        public array $attachments = [],
        public bool $hasBody = false,
    ) {}

    public function fromEmail(): ?string
    {
        return $this->from['email'] ?? null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Real attachments, i.e. not inline images referenced from the HTML. */
    public function hasAttachments(): bool
    {
        foreach ($this->attachments as $attachment) {
            if (! $attachment['inline']) {
                return true;
            }
        }

        return false;
    }

    /** The same message with the attachment list emptied (history keeps metadata only). */
    public function withoutAttachments(): self
    {
        return new self(
            $this->messageId, $this->messageIdSynthetic, $this->inReplyTo, $this->references, $this->from,
            $this->replyTo, $this->to, $this->cc, $this->subject, $this->sentAt, $this->headers,
            $this->bodyHtml, $this->bodyText, $this->bodyClean, [], $this->hasBody,
        );
    }

    /**
     * Every Message-ID this message points at, most recent first
     * (In-Reply-To, then References newest to oldest).
     *
     * @return list<string>
     */
    public function referencedIds(): array
    {
        $ids = $this->inReplyTo !== null ? [$this->inReplyTo] : [];
        foreach (array_reverse($this->references) as $ref) {
            $ids[] = $ref;
        }

        return array_values(array_unique($ids));
    }
}
