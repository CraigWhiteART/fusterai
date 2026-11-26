import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { CheckSquareIcon, ExternalLinkIcon, InboxIcon, RotateCcwIcon, SquareIcon } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import {
    ConflictList,
    DiffEntries,
    Disclosure,
    ErrorLine,
    GateSummary,
    ProposalStatusBadge,
    Spinner,
    SuggestionNotes,
} from '@/Components/agent/LoopParts';
import { Decision } from '@/Components/conversation/DraftFeedback';
import {
    AGENT_SCREEN_PATHS,
    ORIGIN_LABELS,
    PROPOSAL_STATUS_LABELS,
    TARGET_TYPE_LABELS,
    asArray,
    asRecord,
    conversationUrl,
    isOpenProposal,
    isSimulationActive,
    loopApi,
    plural,
    queryParam,
    refreshOpenProposalCount,
    setQueryParam,
    shortDate,
    toApiError,
    usePolling,
    useWorkspaceEvents,
    type ApiError,
} from '@/lib/agentLoop';
import { cn } from '@/lib/utils';
import type { DiffEntry, Proposal, ProposalChange, ProposalEvidence } from '@/types/agentLoop';
import { AgentPage, EmptyState, Notice, Section } from '../AgentParts';

const STATUS_FILTERS: { value: string; label: string }[] = [
    { value: 'open', label: 'Waiting for review' },
    { value: 'applied', label: 'Live (applied)' },
    { value: 'rejected', label: 'Rejected' },
    { value: 'reverted', label: 'Undone' },
    { value: 'all', label: 'Everything' },
    ...['draft', 'testing', 'ready', 'stale'].map((s) => ({ value: s, label: `Only: ${PROPOSAL_STATUS_LABELS[s]}` })),
];

const ORIGIN_FILTERS = ['', 'feedback', 'manager', 'qa', 'mining'];

const SELECT = 'h-8 rounded-md border border-input bg-background px-2 text-xs';

function evidenceOf(p: Proposal): ProposalEvidence {
    return p.evidence && !Array.isArray(p.evidence) && typeof p.evidence === 'object' ? (p.evidence as ProposalEvidence) : {};
}

function ids(value: unknown): number[] {
    return asArray<unknown>(value).filter((v): v is number => typeof v === 'number' && Number.isFinite(v));
}

function ConversationLinks({ list, label = 'Based on' }: { list: number[]; label?: string }) {
    if (list.length === 0) return null;
    const shown = list.slice(0, 12);
    return (
        <p className="flex flex-wrap items-center gap-1 text-[11px] text-muted-foreground">
            <span>
                {label} {plural(list.length, 'conversation')}:
            </span>
            {shown.map((id) => (
                <a
                    key={id}
                    href={conversationUrl(id)}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-0.5 rounded border border-border px-1 hover:border-primary/40 hover:text-primary"
                >
                    #{id} <ExternalLinkIcon className="h-2.5 w-2.5" />
                </a>
            ))}
            {list.length > shown.length && <span>+{list.length - shown.length} more</span>}
        </p>
    );
}

/** Mining evidence per item: which conversations support this rule / knowledge item. */
function itemEvidence(entry: DiffEntry, evidence: ProposalEvidence): number[] {
    for (const rule of asArray<NonNullable<ProposalEvidence['rules']>[number]>(evidence.rules)) {
        if ((rule.rule_id && rule.rule_id === entry.item_id) || (rule.then && entry.after && entry.after.includes(rule.then))) {
            return ids(rule.conversation_ids);
        }
    }
    for (const k of asArray<NonNullable<ProposalEvidence['knowledge']>[number]>(evidence.knowledge)) {
        if (k.title && entry.after && entry.after.includes(k.title)) return ids(k.conversation_ids);
    }
    return [];
}

function testCaseOps(change: ProposalChange): { name: string; frozen: number | null; expectation: Record<string, unknown> }[] {
    return asArray<Record<string, unknown>>(change.ops)
        .filter((op) => op.op === 'create_test_case')
        .map((op) => ({
            name: typeof op.name === 'string' ? op.name : 'Test case',
            frozen: typeof op.frozen_case_id === 'number' ? op.frozen_case_id : null,
            expectation: (op.expectation && typeof op.expectation === 'object' ? op.expectation : {}) as Record<string, unknown>,
        }));
}

function expectationText(e: Record<string, unknown>): string {
    const parts: string[] = [];
    const inc = asArray<string>(e.must_include);
    const not = asArray<string>(e.must_not_include);
    const actions = asArray<string>(e.expected_actions);
    if (inc.length) parts.push(`must mention ${inc.map((s) => `“${s}”`).join(', ')}`);
    if (not.length) parts.push(`must never say ${not.map((s) => `“${s}”`).join(', ')}`);
    if (e.expected_outcome === 'handover') parts.push('should hand over to a person');
    if (e.expected_outcome === 'draft') parts.push('should write a reply');
    if (actions.length) parts.push(`should propose ${actions.join(', ')}`);
    return parts.join('; ') || 'judged against what the person actually sent';
}

// Per-browser "reviewed" ticks so a long mining proposal can be gone through item by item.
function useReviewedItems(proposalId: number | null) {
    const key = proposalId ? `agent.proposal.reviewed.${proposalId}` : null;
    const [done, setDone] = useState<Set<string>>(new Set());
    useEffect(() => {
        if (!key) return;
        try {
            setDone(new Set(JSON.parse(window.localStorage.getItem(key) ?? '[]') as string[]));
        } catch {
            setDone(new Set());
        }
    }, [key]);
    const toggle = useCallback(
        (item: string) => {
            setDone((prev) => {
                const next = new Set(prev);
                if (next.has(item)) next.delete(item);
                else next.add(item);
                try {
                    if (key) window.localStorage.setItem(key, JSON.stringify([...next]));
                } catch {
                    // storage unavailable: ticks last for this view only
                }
                return next;
            });
        },
        [key],
    );
    return { done, toggle };
}

function ChangeCard({
    change,
    evidence,
    reviewed,
    onToggle,
}: {
    change: ProposalChange;
    evidence: ProposalEvidence;
    reviewed: boolean;
    onToggle: () => void;
}) {
    const entries = asArray<DiffEntry>(change.diff);
    const tests = change.target_type === 'test_case' ? testCaseOps(change) : [];
    const label = change.target_label || TARGET_TYPE_LABELS[change.target_type] || change.target_type;
    const href =
        change.target_type === 'playbook' && change.target_id
            ? `/agent/playbooks/${change.target_id}`
            : change.target_type === 'knowledge' && change.target_id
              ? `/agent/knowledge/${change.target_id}/edit`
              : change.target_type === 'voice'
                ? '/agent/voice'
                : null;

    return (
        <li className={cn('rounded-lg border p-3', reviewed ? 'border-success/30 bg-success/5' : 'border-border bg-background')}>
            <div className="mb-2 flex items-start gap-2">
                <button
                    type="button"
                    onClick={onToggle}
                    className="mt-0.5 text-muted-foreground hover:text-foreground"
                    aria-pressed={reviewed}
                    aria-label={reviewed ? 'Mark as not reviewed' : 'Mark as reviewed'}
                    title="Tick items as you check them (only saved in this browser)"
                >
                    {reviewed ? <CheckSquareIcon className="h-4 w-4 text-success" /> : <SquareIcon className="h-4 w-4" />}
                </button>
                <div className="min-w-0 flex-1">
                    <p className="text-sm font-medium">{label}</p>
                    <p className="text-[11px] text-muted-foreground">{TARGET_TYPE_LABELS[change.target_type] ?? change.target_type}</p>
                </div>
                {href && (
                    <a
                        href={href}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-0.5 text-[11px] text-muted-foreground hover:text-primary"
                    >
                        Current version <ExternalLinkIcon className="h-2.5 w-2.5" />
                    </a>
                )}
            </div>
            {tests.length > 0 ? (
                <ul className="space-y-1.5">
                    {tests.map((t, i) => (
                        <li key={i} className="text-xs">
                            <span className="font-medium">{t.name}</span>
                            <span className="block text-muted-foreground">The reply {expectationText(t.expectation)}.</span>
                            {t.frozen && <span className="text-[11px] text-muted-foreground">Saved conversation snapshot #{t.frozen}</span>}
                        </li>
                    ))}
                </ul>
            ) : (
                <ul className="space-y-2.5">
                    {entries.length === 0 && <li className="text-xs italic text-muted-foreground">No visible text change.</li>}
                    {entries.map((entry, i) => {
                        const support = itemEvidence(entry, evidence);
                        return (
                            <li key={`${entry.item_id ?? ''}-${i}`} className="space-y-1">
                                <DiffEntries entries={[{ ...entry, target_label: undefined }]} />
                                <ConversationLinks list={support} />
                            </li>
                        );
                    })}
                </ul>
            )}
        </li>
    );
}

function EvidenceBlock({ proposal }: { proposal: Proposal }) {
    const ev = evidenceOf(proposal);
    const quotes = asArray<string | { text?: string; staff?: string }>(ev.quotes);
    const conversations = ids(ev.conversation_ids);
    const staff = asArray<{ label?: string; replies?: number }>(ev.staff);
    const diffs = asArray<{ topic?: string; note?: string }>(ev.staff_differences);
    const inconsistencies = asArray<NonNullable<ProposalEvidence['inconsistencies']>[number]>(ev.inconsistencies);
    const hasAnything =
        !!proposal.conversation_id ||
        conversations.length > 0 ||
        quotes.length > 0 ||
        !!ev.comment ||
        !!ev.selected_text ||
        staff.length > 0;
    if (!hasAnything && inconsistencies.length === 0) return null;

    return (
        <Section title="Where this came from">
            <div className="space-y-2 text-xs">
                {proposal.conversation_id && (
                    <a
                        href={conversationUrl(proposal.conversation_id)}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-1 text-primary hover:underline"
                    >
                        Open the conversation this feedback was given on <ExternalLinkIcon className="h-3 w-3" />
                    </a>
                )}
                {ev.comment && (
                    <p>
                        <span className="text-muted-foreground">Feedback: </span>“{ev.comment}”
                    </p>
                )}
                {ev.selected_text && (
                    <p>
                        <span className="text-muted-foreground">About: </span>“{ev.selected_text}”
                    </p>
                )}
                <ConversationLinks list={conversations} label="Seen in" />
                {quotes.length > 0 && (
                    <Disclosure title={`Example phrases from your replies (${quotes.length})`}>
                        <ul className="space-y-0.5">
                            {quotes.map((q, i) => (
                                <li key={i} className="text-muted-foreground">
                                    “{typeof q === 'string' ? q : q.text}”{typeof q !== 'string' && q.staff ? ` · ${q.staff}` : ''}
                                </li>
                            ))}
                        </ul>
                    </Disclosure>
                )}
                {staff.length > 0 && (
                    <p className="text-muted-foreground">
                        Learned from {staff.map((s) => `${s.label ?? 'someone'} (${s.replies ?? 0} replies)`).join(', ')}.
                        {typeof ev.sample_size === 'number' ? ` Sample of ${ev.sample_size} replies.` : ''}
                    </p>
                )}
                {diffs.length > 0 && (
                    <ul className="space-y-0.5">
                        {diffs.map((d, i) => (
                            <li key={i}>
                                <span className="font-medium">{d.topic}</span>: <span className="text-muted-foreground">{d.note}</span>
                            </li>
                        ))}
                    </ul>
                )}
                {inconsistencies.length > 0 && (
                    <Disclosure title={`Places your past replies disagreed (${inconsistencies.length})`} defaultOpen>
                        <ul className="space-y-2">
                            {inconsistencies.map((inc, i) => (
                                <li key={i} className="space-y-1 rounded-md border border-border p-2">
                                    <p className="font-medium">
                                        {inc.topic?.replace(/_/g, ' ')}
                                        {inc.situation_name ? <span className="text-muted-foreground"> · {inc.situation_name}</span> : null}
                                    </p>
                                    {inc.summary && <p className="text-muted-foreground">{inc.summary}</p>}
                                    {asArray<{ statement?: string; evidence?: number[]; last_seen?: string | null }>(inc.versions).map(
                                        (v, j) => (
                                            <div key={j} className="border-l-2 border-border pl-2">
                                                <p>
                                                    “{v.statement}”
                                                    {v.last_seen ? (
                                                        <span className="text-muted-foreground"> · last seen {v.last_seen}</span>
                                                    ) : null}
                                                </p>
                                                <ConversationLinks list={ids(v.evidence)} label="In" />
                                            </div>
                                        ),
                                    )}
                                    {inc.recommendation && <p className="text-primary">Suggested: {inc.recommendation}</p>}
                                </li>
                            ))}
                        </ul>
                    </Disclosure>
                )}
            </div>
        </Section>
    );
}

function PreviewBlock({ proposal, onChange }: { proposal: Proposal; onChange: () => void }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const p = proposal.preview;
    if (!proposal.run_id) return null;
    const pending = p?.status === 'pending' || p?.status === 'running';

    return (
        <Section
            title="How the draft changes"
            description="The conversation this came from, re-drafted with the change (nothing is sent)."
            actions={
                isOpenProposal(proposal.status) ? (
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={busy || pending}
                        onClick={async () => {
                            setBusy(true);
                            setError(null);
                            try {
                                await loopApi.preview(proposal.id);
                                onChange();
                            } catch (e) {
                                setError(toApiError(e));
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        {busy ? <Spinner /> : <RotateCcwIcon className="h-3.5 w-3.5" />} Re-draft
                    </Button>
                ) : undefined
            }
        >
            {!p ? (
                <p className="text-xs text-muted-foreground">No re-draft yet.</p>
            ) : pending ? (
                <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <Spinner /> Re-drafting…
                </p>
            ) : p.status !== 'ready' ? (
                <p className="text-xs text-muted-foreground">
                    {p.error ? `Could not re-draft: ${p.error}` : 'No re-draft for this change.'}
                </p>
            ) : (
                <div className="grid gap-2 md:grid-cols-2">
                    <div className="min-w-0 rounded-md border border-border bg-muted/20 p-2.5">
                        <p className="mb-1 text-[10px] font-semibold uppercase text-muted-foreground">Before</p>
                        <p className="max-h-72 overflow-y-auto whitespace-pre-wrap text-xs">{p.old_draft ?? '—'}</p>
                    </div>
                    <div className="min-w-0 rounded-md border border-primary/20 bg-primary/5 p-2.5">
                        <p className="mb-1 text-[10px] font-semibold uppercase text-primary">After</p>
                        <p className="max-h-72 overflow-y-auto whitespace-pre-wrap text-xs">
                            {p.new_draft ?? `Hands the conversation to a person${p.new_handover_label ? `: ${p.new_handover_label}` : ''}.`}
                        </p>
                    </div>
                </div>
            )}
            <ErrorLine error={error} />
        </Section>
    );
}

function TestsBlock({ proposal, onChange }: { proposal: Proposal; onChange: (p: Proposal) => void }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const open = isOpenProposal(proposal.status);
    const running = !!proposal.gate && isSimulationActive(proposal.gate.status);

    return (
        <Section
            title="Saved tests"
            description="Past conversations saved as tests are replayed with and without this change, and judged against what the person actually sent."
            actions={
                open && proposal.status !== 'stale' ? (
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={busy || running}
                        onClick={async () => {
                            setBusy(true);
                            setError(null);
                            try {
                                onChange((await loopApi.gate(proposal.id)).proposal);
                            } catch (e) {
                                setError(toApiError(e));
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        {busy ? <Spinner /> : null} {proposal.gate ? 'Run again' : 'Run tests'}
                    </Button>
                ) : undefined
            }
        >
            {proposal.gate ? (
                <GateSummary simulation={proposal.gate} canManage title="Latest run" />
            ) : (
                <p className="text-xs text-muted-foreground">
                    Not tested yet. Tests are optional, but they catch changes that break replies that used to be fine.
                </p>
            )}
            {proposal.gate && (
                <a href={`${AGENT_SCREEN_PATHS.simulations}&id=${proposal.gate.id}`} className="text-xs text-primary hover:underline">
                    Open the full results
                </a>
            )}
            <ErrorLine error={error} />
        </Section>
    );
}

function AppliedBlock({ proposal, onChange }: { proposal: Proposal; onChange: (p: Proposal) => void }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const a = proposal.applied ?? {};
    const lines = [
        a.voice ? 'Brand voice: new version' : null,
        asArray(a.playbooks).length ? `${plural(asArray(a.playbooks).length, 'playbook')} updated` : null,
        asArray(a.playbooks_created).length ? `${plural(asArray(a.playbooks_created).length, 'playbook')} created (in draft mode)` : null,
        asArray(a.knowledge_created).length ? `${plural(asArray(a.knowledge_created).length, 'knowledge item')} added` : null,
        asArray(a.knowledge_superseded).length ? `${plural(asArray(a.knowledge_superseded).length, 'knowledge item')} updated` : null,
        asArray(a.knowledge_retired).length ? `${plural(asArray(a.knowledge_retired).length, 'knowledge item')} retired` : null,
        asArray(a.examples_retired).length
            ? `${plural(asArray(a.examples_retired).length, 'example reply', 'example replies')} retired`
            : null,
        asArray(a.test_cases_created).length ? `${plural(asArray(a.test_cases_created).length, 'test case')} saved` : null,
    ].filter(Boolean);

    return (
        <Section title={proposal.status === 'reverted' ? 'Undone' : 'Live'}>
            <p className="text-xs text-muted-foreground">
                {proposal.status === 'reverted'
                    ? `Applied ${shortDate(proposal.applied_at)}, undone ${shortDate(proposal.reverted_at)}.`
                    : `Applied ${shortDate(proposal.applied_at)}.`}
            </p>
            {lines.length > 0 && (
                <ul className="list-disc space-y-0.5 pl-5 text-xs">
                    {lines.map((l) => (
                        <li key={l}>{l}</li>
                    ))}
                </ul>
            )}
            {proposal.review_note && <p className="text-xs">Note: “{proposal.review_note}”</p>}
            {proposal.status === 'applied' && (
                <div>
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={busy}
                        onClick={async () => {
                            if (
                                !window.confirm(
                                    'Undo this change? The previous voice / playbook versions are restored and anything it added is retired.',
                                )
                            )
                                return;
                            setBusy(true);
                            setError(null);
                            try {
                                onChange(await loopApi.revert(proposal.id));
                            } catch (e) {
                                setError(toApiError(e));
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        {busy ? <Spinner /> : <RotateCcwIcon className="h-3.5 w-3.5" />} Undo this change
                    </Button>
                </div>
            )}
            <ErrorLine error={error} />
        </Section>
    );
}

function ProposalDetail({ id, onUpdated }: { id: number; onUpdated: (p: Proposal) => void }) {
    const [proposal, setProposal] = useState<Proposal | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const { done, toggle } = useReviewedItems(id);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const load = useCallback(async () => {
        try {
            const p = await loopApi.proposal(id);
            setProposal(p);
            setError(null);
            onUpdated(p);
        } catch (e) {
            setError(toApiError(e));
        }
    }, [id, onUpdated]);

    useEffect(() => {
        setProposal(null);
        void load();
    }, [load]);
    useEffect(
        () => () => {
            if (timer.current) clearTimeout(timer.current);
        },
        [],
    );

    const soon = useCallback(() => {
        if (timer.current) clearTimeout(timer.current);
        timer.current = setTimeout(() => void load(), 500);
    }, [load]);

    useWorkspaceEvents({
        proposalUpdated: (e) => e.proposal_id === id && soon(),
        previewReady: (e) => e.proposal_id === id && soon(),
        simulationProgress: (e) => e.proposal_id === id && soon(),
        simulationFinished: (e) => e.proposal_id === id && soon(),
    });
    const moving =
        !!proposal &&
        ((!!proposal.gate && isSimulationActive(proposal.gate.status)) ||
            proposal.preview?.status === 'pending' ||
            proposal.preview?.status === 'running');
    usePolling(() => void load(), 6000, moving);

    // Actions return the list shape (short preview): keep the full one from show.
    const change = useCallback(
        (p: Proposal) => {
            setProposal((prev) => ({ ...p, preview: p.preview && 'old_draft' in p.preview ? p.preview : (prev?.preview ?? p.preview) }));
            onUpdated(p);
            void refreshOpenProposalCount(true);
            soon();
        },
        [onUpdated, soon],
    );

    if (error && !proposal) return <ErrorLine error={error} />;
    if (!proposal)
        return (
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                <Spinner /> Loading…
            </p>
        );

    const evidence = evidenceOf(proposal);
    const changes = asArray<ProposalChange>(proposal.changes);
    const reviewedCount = changes.filter((c) => done.has(String(c.id))).length;
    const open = isOpenProposal(proposal.status);

    return (
        <div className="space-y-4">
            <Section>
                <div className="flex flex-wrap items-center gap-2">
                    <ProposalStatusBadge status={proposal.status} />
                    <Badge variant="outline">{ORIGIN_LABELS[proposal.origin] ?? proposal.origin}</Badge>
                    <span className="text-xs text-muted-foreground">
                        #{proposal.id} · {shortDate(proposal.created_at)}
                    </span>
                </div>
                <h2 className="text-lg font-semibold">{proposal.title || 'Untitled proposal'}</h2>
                {proposal.rationale && <p className="whitespace-pre-wrap text-sm text-muted-foreground">{proposal.rationale}</p>}
                {proposal.status === 'stale' && (
                    <Notice tone="warning">
                        The live instructions changed after this was written. Rebase it to re-apply the same changes to the current version.
                    </Notice>
                )}
            </Section>

            <EvidenceBlock proposal={proposal} />

            <Section
                title={`Changes (${changes.length})`}
                description={
                    changes.length > 1
                        ? `Go through them one by one: ${reviewedCount} of ${changes.length} ticked. Approving applies all of them; to drop one, reject the whole proposal with a note.`
                        : undefined
                }
            >
                <ul className="space-y-2">
                    {changes.map((c) => (
                        <ChangeCard
                            key={c.id}
                            change={c}
                            evidence={evidence}
                            reviewed={done.has(String(c.id))}
                            onToggle={() => toggle(String(c.id))}
                        />
                    ))}
                </ul>
                <SuggestionNotes
                    suggestions={proposal.suggestions}
                    intro="Suggestions only: the AI never changes permissions, limits or modes. A person can, in settings:"
                />
            </Section>

            <Section title="Conflicts with existing instructions">
                <ConflictList proposal={proposal} canManage onChange={change} />
            </Section>

            <PreviewBlock proposal={proposal} onChange={soon} />
            {(open || proposal.gate) && <TestsBlock proposal={proposal} onChange={change} />}

            {open ? (
                <Section title="Decision">
                    <Decision proposal={proposal} isAdmin onChange={change} compact={false} />
                </Section>
            ) : proposal.status === 'applied' || proposal.status === 'reverted' ? (
                <AppliedBlock proposal={proposal} onChange={change} />
            ) : (
                <Section title="Decision">
                    <p className="text-xs text-muted-foreground">
                        Rejected {shortDate(proposal.reviewed_at)}. Nothing was changed; the proposal is kept for the record.
                        {proposal.review_note ? ` Note: “${proposal.review_note}”` : ''}
                    </p>
                </Section>
            )}
            <ErrorLine error={error} />
        </div>
    );
}

function QueueItem({ proposal, active, onClick }: { proposal: Proposal; active: boolean; onClick: () => void }) {
    const changes = asArray<ProposalChange>(proposal.changes);
    return (
        <li>
            <button
                type="button"
                onClick={onClick}
                className={cn(
                    'w-full rounded-lg border px-3 py-2 text-left transition-colors',
                    active ? 'border-primary/40 bg-primary/5' : 'border-border bg-card hover:bg-muted/40',
                )}
            >
                <div className="flex items-center gap-1.5">
                    <ProposalStatusBadge status={proposal.status} />
                    <span className="text-[11px] text-muted-foreground">{ORIGIN_LABELS[proposal.origin] ?? proposal.origin}</span>
                    {proposal.blocking_conflicts > 0 && (
                        <span className="ml-auto text-[10px] font-semibold text-destructive">
                            {plural(proposal.blocking_conflicts, 'conflict')}
                        </span>
                    )}
                </div>
                <p className="mt-1 line-clamp-2 text-sm font-medium">{proposal.title || 'Untitled proposal'}</p>
                <p className="text-[11px] text-muted-foreground">
                    {plural(changes.length, 'change')} · {shortDate(proposal.created_at)}
                </p>
            </button>
        </li>
    );
}

export default function ProposalsIndex() {
    const [status, setStatus] = useState(() => queryParam('status') ?? 'open');
    const [origin, setOrigin] = useState(() => queryParam('origin') ?? '');
    const [items, setItems] = useState<Proposal[]>([]);
    const [page, setPage] = useState(1);
    const [lastPage, setLastPage] = useState(1);
    const [counts, setCounts] = useState<Record<string, number>>({});
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<ApiError | null>(null);
    const [selected, setSelected] = useState<number | null>(() => Number(queryParam('id')) || null);
    const listTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const load = useCallback(
        async (nextPage = 1) => {
            setLoading(true);
            try {
                const r = await loopApi.proposals({
                    open: status === 'open',
                    status: status !== 'open' && status !== 'all' ? status : undefined,
                    origin: origin || undefined,
                    per_page: 25,
                    page: nextPage,
                });
                const data = asArray<Proposal>(r.data);
                setItems((prev) => (nextPage === 1 ? data : [...prev, ...data.filter((p) => !prev.some((x) => x.id === p.id))]));
                setPage(r.meta?.current_page ?? nextPage);
                setLastPage(r.meta?.last_page ?? nextPage);
                setCounts(asRecord<number>(r.counts));
                setError(null);
            } catch (e) {
                setError(toApiError(e));
            } finally {
                setLoading(false);
            }
        },
        [status, origin],
    );

    useEffect(() => {
        void load(1);
        setQueryParam('status', status === 'open' ? null : status);
        setQueryParam('origin', origin || null);
    }, [load, status, origin]);

    useEffect(
        () => () => {
            if (listTimer.current) clearTimeout(listTimer.current);
        },
        [],
    );
    useWorkspaceEvents({
        proposalUpdated: () => {
            if (listTimer.current) clearTimeout(listTimer.current);
            listTimer.current = setTimeout(() => void load(1), 1000);
        },
    });

    // Auto-select the first item on wide screens when nothing is selected.
    useEffect(() => {
        if (selected === null && items.length > 0 && window.matchMedia?.('(min-width: 1024px)').matches) setSelected(items[0].id);
    }, [items, selected]);

    useEffect(() => setQueryParam('id', selected), [selected]);

    const onUpdated = useCallback(
        (p: Proposal) => setItems((prev) => prev.map((x) => (x.id === p.id ? { ...x, ...p, preview: x.preview } : x))),
        [],
    );
    const openCount = useMemo(() => ['draft', 'testing', 'ready', 'stale'].reduce((sum, s) => sum + (counts[s] ?? 0), 0), [counts]);

    return (
        <AgentPage
            title="Proposals"
            current={AGENT_SCREEN_PATHS.proposals}
            width="max-w-7xl"
            description="Changes to the agent's instructions, suggested from your feedback, the manager chat, quality checks and your email history. Nothing goes live until you approve it."
        >
            <div className="grid gap-6 lg:grid-cols-[22rem_minmax(0,1fr)]">
                <div className={cn('space-y-3', selected !== null && 'hidden lg:block')}>
                    <div className="flex flex-wrap gap-2">
                        <select value={status} onChange={(e) => setStatus(e.target.value)} className={SELECT} aria-label="Status">
                            {STATUS_FILTERS.map((f) => (
                                <option key={f.value} value={f.value}>
                                    {f.label}
                                    {f.value === 'open' ? ` (${openCount})` : counts[f.value] !== undefined ? ` (${counts[f.value]})` : ''}
                                </option>
                            ))}
                        </select>
                        <select value={origin} onChange={(e) => setOrigin(e.target.value)} className={SELECT} aria-label="Source">
                            {ORIGIN_FILTERS.map((o) => (
                                <option key={o} value={o}>
                                    {o ? ORIGIN_LABELS[o] : 'From anywhere'}
                                </option>
                            ))}
                        </select>
                    </div>
                    <ErrorLine error={error} />
                    {items.length === 0 && !loading ? (
                        <EmptyState icon={<InboxIcon className="h-5 w-5 text-muted-foreground" />} title="Nothing here">
                            {status === 'open'
                                ? 'No changes are waiting. Give feedback under an AI draft in the inbox, or learn from your history on the Mining page.'
                                : 'No proposals match these filters.'}
                        </EmptyState>
                    ) : (
                        <ul className="space-y-1.5">
                            {items.map((p) => (
                                <QueueItem key={p.id} proposal={p} active={p.id === selected} onClick={() => setSelected(p.id)} />
                            ))}
                        </ul>
                    )}
                    {loading && (
                        <p className="flex items-center gap-2 text-xs text-muted-foreground">
                            <Spinner /> Loading…
                        </p>
                    )}
                    {!loading && page < lastPage && (
                        <Button size="sm" variant="outline" className="w-full" onClick={() => void load(page + 1)}>
                            Load more
                        </Button>
                    )}
                </div>
                <div className={cn('min-w-0', selected === null && 'hidden lg:block')}>
                    {selected !== null ? (
                        <div className="space-y-3">
                            <button
                                type="button"
                                className="text-xs text-muted-foreground hover:text-foreground lg:hidden"
                                onClick={() => setSelected(null)}
                            >
                                ← Back to the list
                            </button>
                            <ProposalDetail key={selected} id={selected} onUpdated={onUpdated} />
                        </div>
                    ) : (
                        <EmptyState title="Pick a proposal">Choose one on the left to see what it changes and why.</EmptyState>
                    )}
                </div>
            </div>
        </AgentPage>
    );
}
