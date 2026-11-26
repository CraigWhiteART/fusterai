<?php

namespace App\Domains\Mailbox\Sync\Threading;

use App\Domains\Mailbox\Models\Mailbox;

/**
 * The addresses that count as "us" for a mailbox: its own address plus any
 * aliases staff send from (Gmail "Send mail as"). Comparison ignores case and
 * +tags, and dots in the local part for gmail.com / googlemail.com.
 */
final readonly class MailboxIdentity
{
    /** @var list<string> */
    public array $addresses;

    /** @param list<string> $aliases */
    public function __construct(string $address, array $aliases = [])
    {
        $all = array_map(fn (string $a) => self::canonical($a), array_merge([$address], $aliases));
        $this->addresses = array_values(array_unique(array_filter($all)));
    }

    /**
     * The mailbox address, the IMAP login (when it is an address), the
     * mailbox's configured aliases (mailboxes.aliases) and any extra $aliases.
     *
     * @param  list<string>  $aliases
     */
    public static function forMailbox(Mailbox $mailbox, array $aliases = []): self
    {
        $extra = [];
        $username = $mailbox->imap_config['username'] ?? null;
        if (is_string($username) && str_contains($username, '@')) {
            $extra[] = $username;
        }
        foreach ((array) ($mailbox->aliases ?? []) as $alias) {
            if (is_string($alias) && str_contains($alias, '@')) {
                $extra[] = $alias;
            }
        }

        return new self((string) $mailbox->email, array_merge($extra, $aliases));
    }

    public function owns(?string $email): bool
    {
        return $email !== null && $email !== '' && in_array(self::canonical($email), $this->addresses, true);
    }

    public static function canonical(string $email): string
    {
        $email = strtolower(trim($email, " \t<>\"'"));
        if (! str_contains($email, '@')) {
            return '';
        }
        [$local, $domain] = explode('@', $email, 2);
        $local = explode('+', $local, 2)[0];
        if ($domain === 'gmail.com' || $domain === 'googlemail.com') {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return $local.'@'.$domain;
    }
}
