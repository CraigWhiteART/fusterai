import { Link } from '@inertiajs/react';
import { MessagesSquareIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { capitalise } from './agentLabels';
import { relativeTime } from './format';
import type { RelatedConversation } from './types';

const isOpen = (related: RelatedConversation) => related.status === 'open' || related.status === 'pending';

/** "This customer has 2 other open conversations", or the recent-only wording when none is open. */
export function relatedHeadline(related: RelatedConversation[]): string {
    const open = related.filter(isOpen).length;
    if (open > 0) return `This customer has ${open} other open conversation${open === 1 ? '' : 's'}`;
    return `This customer has ${related.length} other recent conversation${related.length === 1 ? '' : 's'}`;
}

function spokeLast(related: RelatedConversation): string {
    if (related.waiting_on_us) return 'Waiting on us';
    if (related.last_speaker === 'customer') return 'Customer wrote last';
    if (related.last_speaker === 'staff') return 'We replied last';
    return 'No messages yet';
}

function when(related: RelatedConversation): string {
    const age = relativeTime(related.last_activity_at ?? undefined);
    return age === '' || age === 'just now' ? age : `${age} ago`;
}

function StatusPill({ related }: { related: RelatedConversation }) {
    return (
        <span
            className={cn(
                'shrink-0 rounded-md px-1.5 py-0.5 text-[11px] font-medium',
                related.status === 'open' && 'bg-emerald-500/12 text-emerald-700 dark:text-emerald-400',
                related.status === 'pending' && 'bg-amber-400/12 text-amber-700 dark:text-amber-400',
                related.status === 'closed' && 'bg-muted/60 text-foreground/70',
            )}
        >
            {capitalise(related.status)}
        </span>
    );
}

/**
 * Compact strip above the thread: the same customer's other conversations,
 * so a duplicate question is noticed before anyone answers it twice.
 */
export function RelatedStrip({ related }: { related: RelatedConversation[] }) {
    if (related.length === 0) return null;
    const anyWaiting = related.some((r) => r.waiting_on_us);

    return (
        <div
            className={cn(
                'shrink-0 max-h-40 overflow-y-auto border-b px-4 py-2 text-xs',
                anyWaiting ? 'border-warning/30 bg-warning/10' : 'border-border/60 bg-muted/30',
            )}
        >
            <p className="flex items-center gap-1.5 font-medium text-foreground">
                <MessagesSquareIcon className="h-3.5 w-3.5 shrink-0" />
                {relatedHeadline(related)}
            </p>
            <ul className="mt-1 space-y-0.5">
                {related.map((r) => (
                    <li key={r.id}>
                        <Link
                            href={`/conversations/${r.id}`}
                            className="flex items-center gap-2 rounded px-1 py-0.5 -mx-1 hover:bg-background/70"
                            title={r.last_customer_excerpt ?? undefined}
                        >
                            <span className="shrink-0 tabular-nums text-muted-foreground">#{r.id}</span>
                            <span className="min-w-0 flex-1 truncate font-medium text-foreground">{r.subject || '(no subject)'}</span>
                            <StatusPill related={r} />
                            <span className={cn('shrink-0', r.waiting_on_us ? 'font-medium text-warning' : 'text-muted-foreground')}>
                                {spokeLast(r)}
                            </span>
                            <span className="shrink-0 tabular-nums text-muted-foreground">{when(r)}</span>
                        </Link>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/** The same list in the right-hand panel, under the customer. */
export function RelatedList({ related }: { related: RelatedConversation[] }) {
    if (related.length === 0) return null;

    return (
        <div className="px-4 py-4 border-b border-border">
            <p className="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted-foreground/60 mb-2">
                Other conversations from this customer
            </p>
            <ul className="space-y-1.5">
                {related.map((r) => (
                    <li key={r.id}>
                        <Link href={`/conversations/${r.id}`} className="block rounded-lg p-2 -mx-2 hover:bg-muted/40 transition-colors">
                            <span className="flex items-center gap-1.5">
                                <span className="min-w-0 flex-1 truncate text-[12px] font-medium text-foreground">
                                    {r.subject || '(no subject)'}
                                </span>
                                <StatusPill related={r} />
                            </span>
                            <span className="mt-0.5 block text-[11px] text-muted-foreground">
                                #{r.id} · {spokeLast(r)}
                                {when(r) ? ` · ${when(r)}` : ''}
                                {r.via === 'contact_form' ? ' · contact form' : ''}
                            </span>
                            {r.last_customer_excerpt && (
                                <span className="mt-0.5 block truncate text-[11px] text-muted-foreground/80">
                                    {r.last_customer_excerpt}
                                </span>
                            )}
                        </Link>
                    </li>
                ))}
            </ul>
        </div>
    );
}
