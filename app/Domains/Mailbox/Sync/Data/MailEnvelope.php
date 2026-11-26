<?php

namespace App\Domains\Mailbox\Sync\Data;

use DateTimeImmutable;

/**
 * Metadata for one message: everything except the body. Cheap enough to fetch
 * for the whole mailbox history.
 *
 * Gmail ids are unsigned 64-bit and kept as decimal strings (PHP ints are
 * signed and overflow above 9223372036854775807).
 */
final readonly class MailEnvelope
{
    /**
     * @param  list<string>  $labels  X-GM-LABELS, decoded to UTF-8 (empty when not Gmail)
     * @param  list<string>  $flags  IMAP flags, e.g. ['\Seen']
     * @param  string  $rawHeaders  the RFC 5322 header block (BODY.PEEK[HEADER])
     */
    public function __construct(
        public string $folder,
        public int $uidValidity,
        public int $uid,
        public string $rawHeaders,
        public ?string $gmMsgid = null,
        public ?string $gmThrid = null,
        public array $labels = [],
        public array $flags = [],
        public ?DateTimeImmutable $internalDate = null,
        public ?int $size = null,
    ) {}
}
