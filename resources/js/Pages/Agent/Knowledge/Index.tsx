import React from 'react';
import { Link, router } from '@inertiajs/react';
import { AlertTriangleIcon, BookOpenIcon, GlobeIcon, PlusIcon, SearchIcon } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { cn } from '@/lib/utils';
import { AgentPage, EmptyState } from '../AgentParts';
import type { KnowledgeItem, KnowledgeKind, KnowledgeStatus } from '@/types/agent';

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
}

interface Filters {
    status?: KnowledgeStatus;
    kind?: KnowledgeKind;
    playbook?: string;
    scope?: 'global' | 'scoped';
    q?: string;
}

interface Props {
    items: Paginated<KnowledgeItem>;
    filters: Filters;
    counts: Partial<Record<KnowledgeStatus, number>>;
    kinds: KnowledgeKind[];
    statuses: KnowledgeStatus[];
    playbooks: { slug: string; name: string }[];
}

const KIND_LABEL: Record<string, string> = {
    fact: 'Fact',
    policy: 'Policy',
    kb_chunk: 'Knowledge base',
    snippet: 'Snippet',
};

const STATUS_LABEL: Record<string, string> = {
    active: 'Active',
    needs_scope: 'Needs scope',
    draft: 'Draft',
    retired: 'Retired',
};

function ScopeChips({ item, playbookNames }: { item: KnowledgeItem; playbookNames: Map<string, string> }) {
    if (item.scope.global) {
        return (
            <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-[11px]">
                <GlobeIcon className="h-3 w-3" /> Everywhere
            </span>
        );
    }
    const chips = [
        ...item.scope.playbooks.map((s) => playbookNames.get(s) ?? s),
        ...item.scope.products.map((p) => `Product: ${p}`),
        ...item.scope.countries.map((c) => `Country: ${c}`),
    ];
    if (chips.length === 0) return <span className="text-[11px] text-warning">No scope yet</span>;
    return (
        <span className="flex flex-wrap gap-1">
            {chips.map((chip) => (
                <span key={chip} className="rounded-full bg-muted px-2 py-0.5 text-[11px]">
                    {chip}
                </span>
            ))}
        </span>
    );
}

export default function KnowledgeIndex({ items, filters, counts, kinds, statuses, playbooks }: Props) {
    const [query, setQuery] = React.useState(filters.q ?? '');
    const playbookNames = React.useMemo(() => new Map(playbooks.map((p) => [p.slug, p.name])), [playbooks]);
    const needsScope = counts.needs_scope ?? 0;
    const total = Object.values(counts).reduce((sum, n) => sum + (n ?? 0), 0);
    const filtered = Object.entries(filters).some(([, v]) => v !== undefined && v !== '' && v !== null);

    const apply = (patch: Partial<Filters>) => {
        const next = { ...filters, ...patch };
        const params = Object.fromEntries(Object.entries(next).filter(([, v]) => v !== undefined && v !== ''));
        router.get('/agent/knowledge', params, { preserveState: true, preserveScroll: true, replace: true });
    };

    const newButton = (
        <Link href="/agent/knowledge/create">
            <Button>
                <PlusIcon className="mr-1 h-4 w-4" /> New item
            </Button>
        </Link>
    );

    const select = 'h-9 rounded-md border border-input bg-background px-2 text-sm';

    return (
        <AgentPage
            title="Knowledge"
            current="/agent/knowledge"
            width="max-w-5xl"
            description="Facts and policies the agent may quote. Every item needs a scope (which playbooks, products or countries it is about, or everywhere) before the agent uses it."
            actions={newButton}
        >
            {needsScope > 0 && filters.status !== 'needs_scope' && (
                <button
                    type="button"
                    onClick={() => apply({ status: 'needs_scope' })}
                    className="flex w-full items-center gap-3 rounded-xl border border-warning/40 bg-warning/10 px-4 py-3 text-left text-sm hover:bg-warning/15"
                >
                    <AlertTriangleIcon className="h-4 w-4 shrink-0 text-warning" />
                    <span className="flex-1">
                        <span className="font-semibold">
                            {needsScope} item{needsScope === 1 ? '' : 's'} waiting for a scope.
                        </span>{' '}
                        The agent ignores them until you say where they apply.
                    </span>
                    <span className="font-medium text-primary">Review the queue →</span>
                </button>
            )}

            <div className="flex flex-wrap gap-1 border-b border-border" role="tablist" aria-label="Status">
                {[undefined, ...statuses].map((status) => {
                    const active = (filters.status ?? undefined) === status;
                    const count = status ? (counts[status] ?? 0) : total;
                    return (
                        <button
                            key={status ?? 'all'}
                            type="button"
                            role="tab"
                            aria-selected={active}
                            onClick={() => apply({ status })}
                            className={cn(
                                '-mb-px border-b-2 px-3 py-2 text-sm transition-colors',
                                active
                                    ? 'border-primary font-medium text-foreground'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                                status === 'needs_scope' && count > 0 && !active && 'text-warning',
                            )}
                        >
                            {status ? (STATUS_LABEL[status] ?? status) : 'All'}
                            <span className="ml-1.5 rounded-full bg-muted px-1.5 text-[11px] tabular-nums">{count}</span>
                        </button>
                    );
                })}
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <form
                    className="relative"
                    onSubmit={(e) => {
                        e.preventDefault();
                        apply({ q: query });
                    }}
                >
                    <SearchIcon className="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                    <Input className="w-56 pl-8" placeholder="Search text…" value={query} onChange={(e) => setQuery(e.target.value)} />
                </form>
                <select
                    className={select}
                    aria-label="Kind"
                    value={filters.kind ?? ''}
                    onChange={(e) => apply({ kind: (e.target.value || undefined) as KnowledgeKind | undefined })}
                >
                    <option value="">All kinds</option>
                    {kinds.map((k) => (
                        <option key={k} value={k}>
                            {KIND_LABEL[k] ?? k}
                        </option>
                    ))}
                </select>
                <select
                    className={select}
                    aria-label="Scope"
                    value={filters.scope ?? ''}
                    onChange={(e) => apply({ scope: (e.target.value || undefined) as Filters['scope'] })}
                >
                    <option value="">Any scope</option>
                    <option value="global">Everywhere</option>
                    <option value="scoped">Scoped</option>
                </select>
                <select
                    className={select}
                    aria-label="Playbook"
                    value={filters.playbook ?? ''}
                    onChange={(e) => apply({ playbook: e.target.value || undefined })}
                >
                    <option value="">Any playbook</option>
                    {playbooks.map((p) => (
                        <option key={p.slug} value={p.slug}>
                            {p.name}
                        </option>
                    ))}
                </select>
                {filtered && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            setQuery('');
                            router.get('/agent/knowledge', {}, { preserveScroll: true, replace: true });
                        }}
                    >
                        Clear filters
                    </Button>
                )}
            </div>

            {items.data.length === 0 ? (
                total === 0 ? (
                    <EmptyState
                        icon={<BookOpenIcon className="h-5 w-5 text-muted-foreground" />}
                        title="No knowledge yet"
                        action={newButton}
                    >
                        Add your policies and current facts (dispatch times, returns window, stock news), or import sent mail from{' '}
                        <Link href="/agent/import" className="text-primary hover:underline">
                            Import history
                        </Link>{' '}
                        to learn them.
                    </EmptyState>
                ) : filters.status === 'needs_scope' ? (
                    <EmptyState title="The queue is empty">Every item has a scope.</EmptyState>
                ) : (
                    <EmptyState title="Nothing matches these filters" />
                )
            ) : (
                <ul className="space-y-2">
                    {items.data.map((item) => (
                        <li key={item.id}>
                            <Link
                                href={`/agent/knowledge/${item.id}/edit`}
                                className={cn(
                                    'block rounded-xl border bg-card p-4 text-sm transition-colors hover:bg-muted/40',
                                    item.status === 'needs_scope' ? 'border-warning/40' : 'border-border',
                                )}
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <p className="font-medium">{item.title || item.body.slice(0, 80)}</p>
                                    <span className="rounded-full bg-muted px-2 py-0.5 text-[11px]">
                                        {KIND_LABEL[item.kind] ?? item.kind}
                                    </span>
                                    {item.status !== 'active' && (
                                        <span
                                            className={cn(
                                                'rounded-full px-2 py-0.5 text-[11px] font-semibold',
                                                item.status === 'needs_scope'
                                                    ? 'bg-warning/15 text-warning'
                                                    : 'bg-muted text-muted-foreground',
                                            )}
                                        >
                                            {STATUS_LABEL[item.status] ?? item.status}
                                        </span>
                                    )}
                                    {item.valid_until && (
                                        <span className="text-[11px] text-muted-foreground">
                                            until {new Date(item.valid_until).toLocaleDateString()}
                                        </span>
                                    )}
                                </div>
                                <p className="mt-1 line-clamp-2 text-muted-foreground">{item.body}</p>
                                <div className="mt-2">
                                    <ScopeChips item={item} playbookNames={playbookNames} />
                                </div>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}

            {items.links.length > 3 && (
                <div className="flex flex-wrap gap-1">
                    {items.links.map((link, i) =>
                        link.url ? (
                            <Link
                                key={i}
                                href={link.url}
                                preserveScroll
                                className={cn(
                                    'rounded border px-2 py-1 text-xs',
                                    link.active ? 'border-primary bg-primary text-primary-foreground' : 'border-border hover:bg-muted',
                                )}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ) : null,
                    )}
                </div>
            )}
        </AgentPage>
    );
}
