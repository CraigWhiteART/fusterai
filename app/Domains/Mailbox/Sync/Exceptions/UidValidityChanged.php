<?php

namespace App\Domains\Mailbox\Sync\Exceptions;

/**
 * The folder's UIDVALIDITY changed between folderState() and a fetch: every
 * stored UID for that folder is now meaningless.
 */
class UidValidityChanged extends MailSourceException
{
    public function __construct(
        public readonly string $folder,
        public readonly int $expected,
        public readonly int $actual,
    ) {
        parent::__construct("UIDVALIDITY of {$folder} changed from {$expected} to {$actual}");
    }
}
