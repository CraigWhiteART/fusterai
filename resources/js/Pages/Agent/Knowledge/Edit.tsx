import React from 'react';
import { useForm } from '@inertiajs/react';
import { GlobeIcon } from 'lucide-react';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import { Textarea } from '@/Components/ui/textarea';
import { cn } from '@/lib/utils';
import { AgentPage, FieldError, Notice, SaveButton, Section, errorFor, formatDate } from '../AgentParts';
import type { KnowledgeItem, KnowledgeKind, KnowledgeScope, KnowledgeStatus } from '@/types/agent';

interface Props {
    item: KnowledgeItem | null;
    kinds: KnowledgeKind[];
    statuses: KnowledgeStatus[];
    playbooks: { slug: string; name: string }[];
}

const KIND_HELP: Record<string, string> = {
    fact: 'Fact: something true right now (e.g. “The warehouse is closed until 2 January”).',
    policy: 'Policy: a rule you stand by (e.g. “Returns within 30 days of delivery”).',
    kb_chunk: 'Knowledge base: part of a knowledge-base document.',
    snippet: 'Snippet: wording the agent may reuse as-is.',
};

const STATUS_TEXT: Record<string, string> = {
    active: 'Active: the agent may use it',
    needs_scope: 'Needs scope: ignored until scoped',
    draft: 'Draft: saved, not used yet',
    retired: 'Retired: no longer used',
};

const splitList = (value: string) =>
    value
        .split(',')
        .map((v) => v.trim())
        .filter(Boolean);

const dateInput = (value: string | null | undefined) => (value ? value.slice(0, 10) : '');

export default function KnowledgeEdit({ item, kinds, statuses, playbooks }: Props) {
    const initialScope: KnowledgeScope = item?.scope ?? { playbooks: [], products: [], countries: [], global: false };
    const { data, setData, post, patch, processing, errors, isDirty } = useForm({
        kind: (item?.kind ?? 'fact') as KnowledgeKind,
        title: item?.title ?? '',
        body: item?.body ?? '',
        scope: initialScope,
        valid_from: dateInput(item?.valid_from),
        valid_until: dateInput(item?.valid_until),
        status: (item && item.status !== 'needs_scope' ? item.status : undefined) as KnowledgeStatus | undefined,
    });
    const [products, setProducts] = React.useState(initialScope.products.join(', '));
    const [countries, setCountries] = React.useState(initialScope.countries.join(', '));
    const e = errors as Record<string, string | undefined>;
    const hasScope = data.scope.global || data.scope.playbooks.length + data.scope.products.length + data.scope.countries.length > 0;

    function submit(ev: React.FormEvent) {
        ev.preventDefault();
        if (data.status === 'retired' && item?.status !== 'retired') {
            if (!window.confirm('Retire this item? The agent stops using it straight away. You can make it active again later.')) return;
        }
        if (item) patch(`/agent/knowledge/${item.id}`, { preserveScroll: true });
        else post('/agent/knowledge');
    }

    const setScope = (patchScope: Partial<KnowledgeScope>) => setData('scope', { ...data.scope, ...patchScope });
    const togglePlaybook = (slug: string) =>
        setScope({
            playbooks: data.scope.playbooks.includes(slug)
                ? data.scope.playbooks.filter((s) => s !== slug)
                : [...data.scope.playbooks, slug],
        });

    const title = item ? item.title || 'Edit knowledge' : 'New knowledge';

    return (
        <AgentPage
            title={title}
            headTitle={item ? 'Edit knowledge' : 'New knowledge'}
            current="/agent/knowledge"
            back={{ href: '/agent/knowledge', label: 'All knowledge' }}
            width="max-w-3xl"
            description={item ? `Last changed ${formatDate(item.updated_at)}.` : 'Something the agent may quote in replies.'}
        >
            {item?.status === 'needs_scope' && (
                <Notice tone="warning">
                    This item came in without a scope, so the agent ignores it. Choose where it applies below (or switch on “Everywhere”);
                    saving makes it active.
                </Notice>
            )}
            {item?.source_document_id && (
                <Notice>
                    Part {(item.chunk_index ?? 0) + 1} of knowledge-base document #{item.source_document_id}. Editing that document replaces
                    this text; the scope and status you set here are kept.
                </Notice>
            )}

            <form onSubmit={submit} className="space-y-6">
                <Section title="Content">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="kind">Kind</Label>
                            <select
                                id="kind"
                                className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                                value={data.kind}
                                onChange={(ev) => setData('kind', ev.target.value as KnowledgeKind)}
                            >
                                {kinds.map((k) => (
                                    <option key={k} value={k}>
                                        {(KIND_HELP[k] ?? k).split(':')[0]}
                                    </option>
                                ))}
                            </select>
                            <p className="text-xs text-muted-foreground">{KIND_HELP[data.kind]}</p>
                            <FieldError message={e.kind} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="status">Status</Label>
                            <select
                                id="status"
                                className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                                value={data.status ?? ''}
                                onChange={(ev) => setData('status', (ev.target.value || undefined) as KnowledgeStatus | undefined)}
                            >
                                <option value="">
                                    {item?.status === 'needs_scope'
                                        ? 'Active once scoped (default)'
                                        : item
                                          ? 'Keep current'
                                          : 'Active (default)'}
                                </option>
                                {statuses
                                    .filter((s) => s !== 'needs_scope')
                                    .map((s) => (
                                        <option key={s} value={s}>
                                            {STATUS_TEXT[s] ?? s}
                                        </option>
                                    ))}
                            </select>
                            <FieldError message={e.status} />
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="title">Title</Label>
                        <Input
                            id="title"
                            value={data.title}
                            placeholder="Dispatch times"
                            onChange={(ev) => setData('title', ev.target.value)}
                        />
                        <FieldError message={e.title} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="body">Text</Label>
                        <Textarea id="body" rows={8} value={data.body} onChange={(ev) => setData('body', ev.target.value)} />
                        <p className="text-xs text-muted-foreground">
                            Write it the way you would tell a new colleague. The agent may quote it.
                        </p>
                        <FieldError message={e.body} />
                    </div>
                </Section>

                <Section
                    title="Where it applies"
                    description="The agent only sees this item on emails that match its scope. Pick at least one, or choose Everywhere."
                    className={cn(!hasScope && 'border-warning/40')}
                >
                    <div className="flex items-start gap-3 rounded-lg border border-border p-3">
                        <Switch id="global" checked={data.scope.global} onCheckedChange={(v) => setScope({ global: v })} />
                        <div>
                            <Label htmlFor="global" className="flex items-center gap-1.5">
                                <GlobeIcon className="h-3.5 w-3.5" /> Everywhere
                            </Label>
                            <p className="text-xs text-muted-foreground">Applies to every conversation, whatever the playbook.</p>
                        </div>
                    </div>

                    <div
                        className={cn('space-y-4', data.scope.global && 'pointer-events-none opacity-50')}
                        aria-disabled={data.scope.global}
                    >
                        <div className="space-y-1.5">
                            <p className="text-sm font-medium">Playbooks</p>
                            {playbooks.length === 0 ? (
                                <p className="text-xs text-muted-foreground">No playbooks yet.</p>
                            ) : (
                                <div className="flex flex-wrap gap-1.5">
                                    {playbooks.map((p) => {
                                        const on = data.scope.playbooks.includes(p.slug);
                                        return (
                                            <button
                                                key={p.slug}
                                                type="button"
                                                aria-pressed={on}
                                                onClick={() => togglePlaybook(p.slug)}
                                                className={cn(
                                                    'rounded-full border px-2.5 py-1 text-xs transition-colors',
                                                    on ? 'border-primary bg-primary/10 text-primary' : 'border-border hover:bg-muted',
                                                )}
                                            >
                                                {p.name}
                                            </button>
                                        );
                                    })}
                                </div>
                            )}
                            <FieldError message={errorFor(e, 'scope.playbooks')} />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label htmlFor="products">Product keywords</Label>
                                <Input
                                    id="products"
                                    value={products}
                                    placeholder="dial, strap"
                                    onChange={(ev) => {
                                        setProducts(ev.target.value);
                                        setScope({ products: splitList(ev.target.value) });
                                    }}
                                />
                                <p className="text-xs text-muted-foreground">Comma separated; matched against the order's products.</p>
                                <FieldError message={errorFor(e, 'scope.products')} />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="countries">Countries</Label>
                                <Input
                                    id="countries"
                                    value={countries}
                                    placeholder="AU, NZ"
                                    onChange={(ev) => {
                                        setCountries(ev.target.value);
                                        setScope({ countries: splitList(ev.target.value).map((c) => c.toUpperCase()) });
                                    }}
                                />
                                <p className="text-xs text-muted-foreground">
                                    Two-letter codes, comma separated; matched against the shipping country.
                                </p>
                                <FieldError message={errorFor(e, 'scope.countries')} />
                            </div>
                        </div>
                    </div>
                    <FieldError message={e.scope ?? e['scope.global']} />
                </Section>

                <Section title="Dates (optional)" description="Outside these dates the agent ignores the item, e.g. for a holiday closure.">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="valid_from">Valid from</Label>
                            <Input
                                id="valid_from"
                                type="date"
                                value={data.valid_from}
                                onChange={(ev) => setData('valid_from', ev.target.value)}
                            />
                            <FieldError message={e.valid_from} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="valid_until">Valid until</Label>
                            <Input
                                id="valid_until"
                                type="date"
                                value={data.valid_until}
                                onChange={(ev) => setData('valid_until', ev.target.value)}
                            />
                            <FieldError message={e.valid_until} />
                        </div>
                    </div>
                </Section>

                <div className="flex items-center gap-3">
                    <SaveButton processing={processing} disabled={!!item && !isDirty && item.status !== 'needs_scope'}>
                        {item?.status === 'needs_scope' ? 'Save and activate' : item ? 'Save changes' : 'Create item'}
                    </SaveButton>
                    {!hasScope && <span className="text-xs text-warning">Choose a scope before saving.</span>}
                </div>
            </form>
        </AgentPage>
    );
}
