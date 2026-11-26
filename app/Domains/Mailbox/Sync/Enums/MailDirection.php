<?php

namespace App\Domains\Mailbox\Sync\Enums;

enum MailDirection: string
{
    /** From someone else to the mailbox. */
    case Inbound = 'inbound';

    /** From the mailbox (or an alias) to someone else: a staff reply. */
    case Outbound = 'outbound';

    /** From the mailbox to only the mailbox / its aliases. */
    case Internal = 'internal';
}
