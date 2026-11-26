<?php

namespace App\Domains\Mailbox\Sync\Contracts;

use App\Domains\Mailbox\Sync\Data\FolderRef;
use App\Domains\Mailbox\Sync\Data\FolderState;
use App\Domains\Mailbox\Sync\Data\MailBody;
use App\Domains\Mailbox\Sync\Data\MailEnvelope;
use App\Domains\Mailbox\Sync\Exceptions\MailSourceException;
use App\Domains\Mailbox\Sync\Exceptions\UidValidityChanged;
use DateTimeInterface;

/**
 * Read-only access to a mailbox. Implementations must never change server
 * state: folders are opened with EXAMINE and bodies fetched with BODY.PEEK, so
 * nothing is marked read in Gmail.
 *
 * Every fetch takes the FolderState the caller obtained from folderState(); if
 * the server's UIDVALIDITY no longer matches, the call throws
 * UidValidityChanged instead of returning UIDs from a different epoch.
 */
interface MailSource
{
    /**
     * Folders to sync. On Gmail this is the single folder with the \All
     * special-use attribute (its name is localised). Without \All it falls back
     * to INBOX plus the \Sent folder.
     *
     * @return list<FolderRef>
     *
     * @throws MailSourceException
     */
    public function syncFolders(): array;

    /** True when the server advertises X-GM-EXT-1 (X-GM-MSGID/THRID/LABELS). */
    public function supportsGmailExtensions(): bool;

    /** @throws MailSourceException */
    public function folderState(FolderRef $folder): FolderState;

    /**
     * Envelopes for UIDs in [$fromUid, $toUid], ascending by UID. Missing
     * (expunged) UIDs are simply absent.
     *
     * @return list<MailEnvelope>
     *
     * @throws UidValidityChanged
     * @throws MailSourceException
     */
    public function fetchEnvelopes(FolderState $state, int $fromUid, int $toUid): array;

    /**
     * Full RFC 822 source for specific UIDs, keyed by UID.
     *
     * @param  list<int>  $uids
     * @return array<int, MailBody>
     *
     * @throws UidValidityChanged
     * @throws MailSourceException
     */
    public function fetchBodies(FolderState $state, array $uids): array;

    /**
     * Like fetchBodies(), but without downloading attachments: the header and
     * the text/plain and text/html parts only, re-assembled into a small
     * RFC 822 message (MailBody::$textOnly) with the attachments described in
     * MailBody::$attachments. Used for history, where attachment bytes would
     * eat Gmail's daily IMAP download allowance. An implementation may return
     * the full message for small or single-part messages.
     *
     * @param  list<int>  $uids
     * @return array<int, MailBody>
     *
     * @throws UidValidityChanged
     * @throws MailSourceException
     */
    public function fetchTextBodies(FolderState $state, array $uids): array;

    /**
     * Lowest UID whose INTERNALDATE is on or after $date (IMAP SEARCH SINCE is
     * date-granular), or null when there is none.
     *
     * @throws UidValidityChanged
     * @throws MailSourceException
     */
    public function firstUidOnOrAfter(FolderState $state, DateTimeInterface $date): ?int;

    /** Release the connection. Safe to call more than once. */
    public function close(): void;
}
