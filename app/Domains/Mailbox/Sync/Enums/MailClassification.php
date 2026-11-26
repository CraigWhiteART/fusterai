<?php

namespace App\Domains\Mailbox\Sync\Enums;

enum MailClassification: string
{
    case Support = 'support';
    case Bulk = 'bulk';
    case Auto = 'auto';
    case Notification = 'notification';
    case Unknown = 'unknown';

    /** Mail that should never open or reopen a support conversation. */
    public function isAutomated(): bool
    {
        return $this === self::Bulk || $this === self::Auto || $this === self::Notification;
    }
}
