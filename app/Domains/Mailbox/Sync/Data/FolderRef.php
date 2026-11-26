<?php

namespace App\Domains\Mailbox\Sync\Data;

/**
 * A folder the sync reads from.
 *
 * $name is the wire name exactly as LIST returned it (modified UTF-7 on IMAP),
 * because that is what EXAMINE needs; $displayName is the decoded UTF-8 form.
 * Gmail localises "[Gmail]/All Mail", so code must find it by the \All
 * attribute, never by name.
 */
final readonly class FolderRef
{
    public const ROLE_ALL = 'all';

    public const ROLE_INBOX = 'inbox';

    public const ROLE_SENT = 'sent';

    /**
     * @param  list<string>  $attributes  e.g. ['\All', '\HasNoChildren']
     */
    public function __construct(
        public string $name,
        public string $role,
        public array $attributes = [],
        public ?string $displayName = null,
        public ?string $delimiter = null,
    ) {}

    public function hasAttribute(string $attribute): bool
    {
        foreach ($this->attributes as $a) {
            if (strcasecmp($a, $attribute) === 0) {
                return true;
            }
        }

        return false;
    }
}
