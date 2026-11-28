import { useState, type ReactNode } from 'react';
import { CalendarIcon, ChevronDownIcon, MailboxIcon, SparklesIcon, XIcon } from 'lucide-react';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/Components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type { Mailbox, Tag } from '@/types';

// A type alias (not an interface) so it stays assignable to Record<string, string | undefined>.
export type InboxFilterValues = {
    status?: string;
    mailbox?: string;
    assigned?: string;
    tag?: string;
    priority?: string;
    conversation?: string;
    date_from?: string;
    date_to?: string;
    view?: string;
    starred?: string;
    category?: string;
    draft_ready?: string;
};

/** A category the AI agent sorts conversations into, with how many of the current list are in it. */
export type InboxCategory = { key: string; label: string; count: number };

const chip = 'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-medium transition-all whitespace-nowrap';
const chipOn = 'border-primary/30 bg-primary/10 text-primary';
const chipOff = 'border-border bg-background text-muted-foreground hover:text-foreground';

interface Props {
    filters: InboxFilterValues;
    counts: Record<string, number>;
    categories: InboxCategory[];
    isAgent: boolean;
    mailboxes: Pick<Mailbox, 'id' | 'name'>[];
    tags: Tag[];
    go: (params: Record<string, string | undefined>) => void;
    /** Rendered at the start of the filters row (the select-all checkbox). */
    leading?: ReactNode;
}

/** Status tabs, assignment / mailbox / date filters, category chips and the active tag chip above the inbox list. */
export default function InboxFilters({ filters, counts, categories, isAgent, mailboxes, tags, go, leading }: Props) {
    const categoryTotal = categories.reduce((sum, category) => sum + category.count, 0);
    const draftReady = filters.draft_ready === '1' || filters.draft_ready === 'true';
    const hasDate = !!(filters.date_from || filters.date_to);
    // The date range is rarely used: tucked behind a button, and always shown while it filters the list.
    const [dateOpen, setDateOpen] = useState(hasDate);
    const showDate = dateOpen || hasDate;
    const activeStatus = filters.status ?? 'open';
    const activeTag = tags.find((tag) => String(tag.id) === filters.tag);
    const statusTabs = [
        { key: 'open', label: 'Open', count: counts.open },
        { key: 'pending', label: 'Pending', count: counts.pending },
        { key: 'snoozed', label: 'Snoozed', count: counts.snoozed },
        { key: 'closed', label: 'Closed', count: undefined },
    ];

    return (
        <>
            {/* Status tabs — pill style */}
            <div className="flex items-center gap-1 px-3 pb-3 border-b border-border shrink-0">
                {statusTabs.map(({ key, label, count }) => (
                    <button
                        key={key}
                        onClick={() => go({ status: key, page: undefined })}
                        className={cn(
                            'px-3 py-1.5 rounded-lg text-xs font-semibold transition-all',
                            activeStatus === key
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:text-foreground hover:bg-muted/60',
                        )}
                    >
                        {label}
                        {count !== undefined && count > 0 && (
                            <span className={cn('ml-1.5 text-[10px] tabular-nums', activeStatus === key ? 'opacity-75' : 'opacity-55')}>
                                {count}
                            </span>
                        )}
                    </button>
                ))}
            </div>

            {/* Filters row */}
            <div className="px-3 py-2 border-b border-border shrink-0 flex items-center gap-2">
                {leading}
                {/* Assignment segmented control */}
                <div className="flex items-center bg-muted/60 rounded-lg p-0.5 gap-0.5">
                    {[
                        { value: 'all', label: 'All' },
                        { value: 'me', label: 'Mine' },
                        { value: 'none', label: 'Unassigned' },
                    ].map(({ value, label }) => {
                        const current = filters.assigned ?? (isAgent ? 'me' : 'all');
                        return (
                            <button
                                key={value}
                                onClick={() => go({ assigned: value === 'all' ? undefined : value, page: undefined })}
                                className={cn(
                                    'rounded-md px-2.5 py-1 text-[11px] font-medium transition-all whitespace-nowrap',
                                    current === value
                                        ? 'bg-background text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {label}
                            </button>
                        );
                    })}
                </div>

                <span className="flex-1" />

                {/* Mailbox filter — popover */}
                {mailboxes.length > 1 &&
                    (() => {
                        const activeMb = mailboxes.find((mb) => filters.mailbox === String(mb.id));
                        return (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <button
                                        className={cn(
                                            'flex items-center gap-1 rounded-lg px-2.5 py-1 text-[11px] font-medium transition-all border',
                                            activeMb
                                                ? 'border-primary/30 bg-primary/5 text-primary'
                                                : 'border-border bg-background text-muted-foreground hover:text-foreground',
                                        )}
                                    >
                                        <MailboxIcon className="h-3 w-3" />
                                        <span>{activeMb ? activeMb.name : 'All'}</span>
                                        <ChevronDownIcon className="h-2.5 w-2.5 opacity-60" />
                                    </button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" className="w-44">
                                    <DropdownMenuItem onClick={() => go({ mailbox: undefined, page: undefined })}>
                                        <span className={cn(!activeMb && 'text-primary font-medium')}>All mailboxes</span>
                                    </DropdownMenuItem>
                                    {mailboxes.map((mb) => (
                                        <DropdownMenuItem key={mb.id} onClick={() => go({ mailbox: String(mb.id), page: undefined })}>
                                            <span className={cn(filters.mailbox === String(mb.id) && 'text-primary font-medium')}>
                                                {mb.name}
                                            </span>
                                        </DropdownMenuItem>
                                    ))}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        );
                    })()}

                <button
                    type="button"
                    onClick={() => setDateOpen((v) => !v)}
                    disabled={hasDate}
                    title={hasDate ? 'Filtered by date' : 'Filter by date'}
                    aria-label="Filter by date"
                    aria-expanded={showDate}
                    className={cn(
                        'flex h-6 w-6 shrink-0 items-center justify-center rounded-lg border transition-all',
                        showDate
                            ? 'border-primary/30 bg-primary/5 text-primary'
                            : 'border-border bg-background text-muted-foreground hover:text-foreground',
                    )}
                >
                    <CalendarIcon className="h-3 w-3" />
                </button>
            </div>

            {/* Category chips (set by the AI agent) and the "Draft ready" toggle */}
            <div className="px-3 py-2 border-b border-border shrink-0 flex flex-wrap items-center gap-1">
                <button
                    type="button"
                    onClick={() => go({ category: undefined, page: undefined })}
                    aria-pressed={!filters.category}
                    className={cn(chip, !filters.category ? chipOn : chipOff)}
                >
                    All
                    <span className="tabular-nums opacity-60">{categoryTotal}</span>
                </button>
                {categories
                    .filter((category) => category.count > 0 || filters.category === category.key)
                    .map((category) => (
                        <button
                            key={category.key}
                            type="button"
                            onClick={() => go({ category: filters.category === category.key ? undefined : category.key, page: undefined })}
                            aria-pressed={filters.category === category.key}
                            className={cn(chip, filters.category === category.key ? chipOn : chipOff)}
                        >
                            {category.label}
                            <span className="tabular-nums opacity-60">{category.count}</span>
                        </button>
                    ))}
                <button
                    type="button"
                    onClick={() => go({ draft_ready: draftReady ? undefined : '1', page: undefined })}
                    aria-pressed={draftReady}
                    title="Only conversations where the AI agent has a reply ready for you to check and send"
                    className={cn(chip, 'ml-auto', draftReady ? 'border-info/30 bg-info/10 text-info' : chipOff)}
                >
                    <SparklesIcon className="h-2.5 w-2.5" />
                    Draft ready
                </button>
            </div>

            {/* Date range filter */}
            {showDate && (
                <div className="px-3 py-2 border-b border-border shrink-0 flex items-center gap-1.5">
                    <span className="text-[11px] text-muted-foreground shrink-0">From</span>
                    <input
                        type="date"
                        value={filters.date_from ?? ''}
                        onChange={(e) => go({ date_from: e.target.value || undefined, page: undefined })}
                        className="flex-1 min-w-0 text-[11px] border border-input rounded-md px-2 py-1 bg-background focus:outline-none focus:ring-1 focus:ring-ring"
                    />
                    <span className="text-[11px] text-muted-foreground shrink-0">To</span>
                    <input
                        type="date"
                        value={filters.date_to ?? ''}
                        min={filters.date_from}
                        onChange={(e) => go({ date_to: e.target.value || undefined, page: undefined })}
                        className="flex-1 min-w-0 text-[11px] border border-input rounded-md px-2 py-1 bg-background focus:outline-none focus:ring-1 focus:ring-ring"
                    />
                    {(filters.date_from || filters.date_to) && (
                        <button
                            type="button"
                            onClick={() => {
                                setDateOpen(false);
                                go({ date_from: undefined, date_to: undefined, page: undefined });
                            }}
                            aria-label="Clear date filter"
                            className="text-muted-foreground hover:text-foreground shrink-0"
                        >
                            <XIcon className="h-3 w-3" />
                        </button>
                    )}
                </div>
            )}

            {/* Active tag indicator (compact) */}
            {activeTag && (
                <div className="flex items-center gap-2 px-3 py-1.5 border-b border-border shrink-0">
                    <span className="text-xs text-muted-foreground">Tag filter:</span>
                    <span
                        className="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                        style={{ backgroundColor: activeTag.color + '30', color: activeTag.color }}
                    >
                        {activeTag.name}
                    </span>
                    <button
                        type="button"
                        onClick={() => go({ tag: undefined, page: undefined })}
                        className="text-xs text-muted-foreground hover:text-foreground"
                    >
                        Clear
                    </button>
                </div>
            )}
        </>
    );
}
