<?php

namespace App\Domains\Mailbox\Sync\Contracts;

use App\Domains\Mailbox\Models\Mailbox;

interface MailSourceFactory
{
    public function forMailbox(Mailbox $mailbox): MailSource;
}
