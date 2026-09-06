import React from 'react';
import { Link } from '@inertiajs/react';

const links = [
    { href: '/settings/commerce-assist', label: 'Settings', exact: true },
    { href: '/settings/commerce-assist/learn', label: 'Learn from history' },
    { href: '/settings/commerce-assist/facts', label: 'Current facts' },
    { href: '/settings/commerce-assist/intents', label: 'Intent rules' },
    { href: '/settings/commerce-assist/examples', label: 'Approved replies' },
    { href: '/settings/commerce-assist/replay', label: 'Replay' },
];

export default function CommerceAssistNav({ current }: { current: string }) {
    return (
        <div className="flex flex-wrap gap-3 text-sm">
            {links.map((link) => {
                const active = link.exact ? current === link.href : current.startsWith(link.href);
                return (
                    <Link
                        key={link.href}
                        href={link.href}
                        className={active ? 'text-primary font-medium' : 'text-muted-foreground hover:text-foreground'}
                    >
                        {link.label}
                    </Link>
                );
            })}
        </div>
    );
}
