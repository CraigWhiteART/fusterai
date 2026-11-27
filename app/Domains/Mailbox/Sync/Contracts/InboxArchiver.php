<?php

namespace App\Domains\Mailbox\Sync\Contracts;

use App\Domains\Mailbox\Models\Mailbox;
use App\Domains\Mailbox\Sync\Exceptions\MailSourceException;
use App\Domains\Mailbox\Sync\Models\MailMessage;

/**
 * The one place that changes mail on the server: marks messages read and
 * takes them out of the Gmail Inbox, once their conversation is dealt with
 * (ArchiveConversationMailJob). Everything else stays read-only (MailSource).
 */
interface InboxArchiver
{
    /**
     * Mark these ledger messages \Seen and remove their \Inbox label. Does
     * nothing on servers without the Gmail extensions.
     *
     * @param  iterable<MailMessage>  $messages
     * @return list<int> ids of the messages that were changed
     *
     * @throws MailSourceException
     */
    public function archive(Mailbox $mailbox, iterable $messages): array;
}
