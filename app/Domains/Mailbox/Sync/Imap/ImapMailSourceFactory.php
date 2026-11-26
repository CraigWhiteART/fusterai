<?php

namespace App\Domains\Mailbox\Sync\Imap;

use App\Domains\Mailbox\Models\Mailbox;
use App\Domains\Mailbox\Sync\Contracts\MailSource;
use App\Domains\Mailbox\Sync\Contracts\MailSourceFactory;

final class ImapMailSourceFactory implements MailSourceFactory
{
    public function forMailbox(Mailbox $mailbox): MailSource
    {
        return ImapMailSource::fromMailbox($mailbox);
    }
}
