<?php

namespace App\Domains\Mailbox\Sync\Data;

/**
 * The RFC 822 source of one message. Parse with MessageParser; this class
 * deliberately does no decoding.
 *
 * Normally the full message (BODY.PEEK[]). From MailSource::fetchTextBodies()
 * it can instead be a stand-in built from the header and the text parts only
 * ($textOnly = true): attachments were not downloaded, and $attachments
 * describes them (name, type, size from BODYSTRUCTURE; no bytes).
 */
final readonly class MailBody
{
    /**
     * @param  list<array{name: string, mime: string, size: int, inline: bool}>  $attachments
     */
    public function __construct(
        public string $folder,
        public int $uidValidity,
        public int $uid,
        public string $raw,
        public bool $textOnly = false,
        public array $attachments = [],
    ) {}
}
