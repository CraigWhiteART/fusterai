import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import { CheckIcon, ExternalLinkIcon, LightbulbIcon, PauseIcon, PickaxeIcon, PlayIcon, SquareIcon } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Disclosure, ErrorLine, ProgressBar, ProposalStatusBadge, Spinner } from '@/Components/agent/LoopParts';
import {
    AGENT_SCREEN_PATHS,
    asArray,
    asRecord,
    conversationUrl,
    loopApi,
    pct,
    plural,
    proposalUrl,
    queryParam,
    setQueryParam,
    shortDate,
    toApiError,
    usd,
    useAction,
    usePolling,
    type ApiError,
} from '@/lib/agentLoop';
import { cn } from '@/lib/utils';
import type {
    MiningCoverage,
    MiningEstimate,
    MiningInconsistency,
    MiningIndex as MiningIndexPayload,
    MiningOptions,
    MiningProposalRef,
    MiningRunDetail,
    MiningRunSummary,
    MiningStageUsage,
} from '@/types/agentLoop';
import { AgentPage, EmptyState, Notice, Section } from '../AgentParts';

// ── Labels ───────────────────────────────────────────────────────────────────

const STAGE_LABELS: Record<string, string> = {
    select: 'Pick conversations',
    extract: 'Read each conversation',
    cluster: 'Group by situation',
    synthesise: 'Write playbooks',
    voice: 'Learn the brand voice',
    emit: 'Create proposals',
    done: 'Done',
};
const DEFAULT_STAGES = ['select', 'extract', 'cluster', 'synthesise', 'voice', 'emit', 'done'];
const USAGE_STAGES = ['extract', 'cluster', 'synthesise', 'voice'];

const STATUS_LABELS: Record<string, string> = {
    queued: 'Queued',
    running: 'Running',
    paused: 'Paused',
    completed: 'Finished',
    failed: 'Failed',
    cancelled: 'Cancelled',
};

const SITUATION_STATUS: Record<string, { label: string; help: string }> = {
    pending: { label: 'Waiting', help: 'Not written up yet.' },
    synthesised: { label: 'Written up', help: 'Rules, knowledge and test cases were drafted for this situation.' },
    failed: { label: 'Failed', help: 'Writing this situation up failed; resuming the run tries again.' },
    too_small: { label: 'Too few conversations', help: 'Too few conversations to learn a reliable pattern from.' },
    long_tail: { label: 'Rare / other', help: 'Rare or miscellaneous situations are counted but not written up.' },
};

const PROPOSAL_ACTION_LABELS: Record<string, string> = {
    created: 'New proposal',
    updated: 'Proposal updated',
    unchanged: 'No change since last run',
    unchanged_reviewed: 'Already reviewed, unchanged',
    skipped_in_review: 'Skipped: tests are running on it',
    skipped: 'Skipped',
    failed: 'Could not create',
};

const PII_KINDS: Record<string, string> = {
    customer_name: 'name',
    email: 'email',
    phone: 'phone number',
    address: 'address',
    order_number: 'order number',
    tracking_number: 'tracking number',
};

const DROPPED_LABELS: Record<string, string> = {
    not_support_spam: 'Spam',
    not_support_vendor: 'Suppliers / vendors',
    not_support_marketing: 'Marketing email',
    not_support_personal: 'Personal email',
    not_support_other: 'Not a support conversation',
    no_human_reply: 'No reply written by a person',
    rule_no_evidence: 'Rules without supporting conversations',
    knowledge_unscoped: 'Facts with no clear place to apply',
    inconsistency_unsupported: 'Disagreements without enough evidence',
    test_case_unfreezable: 'Test cases that could not be saved',
    invalid_op: 'Changes that did not fit the current instructions',
};

function droppedLabel(key: string): string {
    if (DROPPED_LABELS[key]) return DROPPED_LABELS[key];
    if (key.startsWith('pii_')) {
        const kind = key.slice(4);
        return `Dropped to protect customer details (${PII_KINDS[kind] ?? kind.replace(/_/g, ' ')})`;
    }
    if (key.startsWith('not_support_')) return `Not support: ${key.slice(12).replace(/_/g, ' ')}`;
    return humanize(key);
}

function humanize(key: string): string {
    const s = key.replace(/[_.]/g, ' ').trim();
    return s ? s[0].toUpperCase() + s.slice(1) : key;
}

const ACTIVE = ['queued', 'running'];

function statusVariant(status: string): 'default' | 'secondary' | 'success' | 'destructive' | 'outline' {
    if (status === 'completed') return 'success';
    if (status === 'failed') return 'destructive';
    if (ACTIVE.includes(status)) return 'default';
    if (status === 'paused') return 'secondary';
    return 'outline';
}

function StatusBadge({ status }: { status: string | null | undefined }) {
    const s = status ?? 'unknown';
    return <Badge variant={statusVariant(s)}>{STATUS_LABELS[s] ?? humanize(s)}</Badge>;
}

function num(value: unknown): number {
    return typeof value === 'number' && Number.isFinite(value) ? value : 0;
}

function fmt(value: unknown): string {
    return num(value).toLocaleString();
}

function dateOnly(value: string | null | undefined): string {
    if (!value) return '';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString(undefined, { dateStyle: 'medium' });
}

const selectClass =
    'h-10 w-full rounded-lg border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30';

// ── Page ─────────────────────────────────────────────────────────────────────

export default function MiningIndex() {
    const [index, setIndex] = useState<MiningIndexPayload | null>(null);
    const [loadError, setLoadError] = useState<ApiError | null>(null);
    const [selectedId, setSelectedId] = useState<number | null>(() => {
        const q = Number(queryParam('run'));
        return Number.isFinite(q) && q > 0 ? q : null;
    });

    const loadIndex = useCallback(async () => {
        try {
            const data = await loopApi.miningIndex();
            setIndex(data);
            setLoadError(null);
            setSelectedId((current) => current ?? data?.active_run_id ?? asArray<MiningRunSummary>(data?.runs)[0]?.id ?? null);
        } catch (e) {
            setLoadError(toApiError(e));
        }
    }, []);

    useEffect(() => {
        void loadIndex();
    }, [loadIndex]);

    const select = useCallback((id: number) => {
        setSelectedId(id);
        setQueryParam('run', id);
    }, []);

    const runs = asArray<MiningRunSummary>(index?.runs);
    const hasActive = !!index?.active_run_id;

    return (
        <AgentPage
            title="Learn from history"
            headTitle="History mining"
            current={AGENT_SCREEN_PATHS.mining}
            width="max-w-6xl"
            description="Reads your past support conversations and drafts a brand voice, playbooks, facts and test cases from how your team actually answered. Nothing goes live: every result is a proposal you review first."
        >
            {loadError && <Notice tone="danger">{loadError.message}</Notice>}
            {!index && !loadError && (
                <p className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Spinner /> Loading…
                </p>
            )}

            {index && (
                <>
                    {!hasActive && (
                        <StartForm
                            index={index}
                            onStarted={(run) => {
                                select(run.id);
                                void loadIndex();
                            }}
                            onShowActive={async () => {
                                const data = await loopApi.miningIndex().catch(() => null);
                                if (data) setIndex(data);
                                if (data?.active_run_id) select(data.active_run_id);
                            }}
                        />
                    )}

                    {selectedId !== null && <RunDetail key={selectedId} runId={selectedId} onChanged={() => void loadIndex()} />}

                    <Section
                        title="Runs"
                        description="The newest 20 runs. Re-running on unchanged history costs nothing and creates no duplicate proposals."
                    >
                        {runs.length === 0 ? (
                            <EmptyState icon={<PickaxeIcon className="h-5 w-5 text-muted-foreground" />} title="No runs yet">
                                Estimate the cost above, then start a run. Import your Gmail history first so there is something to learn
                                from.
                            </EmptyState>
                        ) : (
                            <RunsTable runs={runs} selectedId={selectedId} onSelect={select} />
                        )}
                    </Section>
                </>
            )}
        </AgentPage>
    );
}

// ── Start form ───────────────────────────────────────────────────────────────

function StartForm({
    index,
    onStarted,
    onShowActive,
}: {
    index: MiningIndexPayload;
    onStarted: (run: MiningRunDetail) => void;
    onShowActive: () => void;
}) {
    const defaults = index.defaults ?? { months: 18, max_conversations: 3000, budget_usd: 25, sample_strategy: 'stratified' };
    const limits = index.limits ?? { months: 120, max_conversations: 20000, budget_usd: 500 };
    const strategies = asArray<string>(index.strategies).length ? asArray<string>(index.strategies) : ['stratified', 'newest'];

    const [windowMode, setWindowMode] = useState<'months' | 'dates'>('months');
    const [months, setMonths] = useState(String(defaults.months ?? 18));
    const [since, setSince] = useState('');
    const [until, setUntil] = useState('');
    const [max, setMax] = useState(String(defaults.max_conversations ?? 3000));
    const [budget, setBudget] = useState(String(defaults.budget_usd ?? 25));
    const [strategy, setStrategy] = useState(defaults.sample_strategy || strategies[0]);
    const [estimate, setEstimate] = useState<{ key: string; value: MiningEstimate } | null>(null);
    const [conflict, setConflict] = useState<string | null>(null);
    const { busy, error, run } = useAction();

    const options: MiningOptions = useMemo(() => {
        const o: MiningOptions = {
            max_conversations: Number(max) || undefined,
            budget_usd: Number(budget) || undefined,
            sample_strategy: strategy,
        };
        if (windowMode === 'months') o.months = Number(months) || undefined;
        else {
            o.since = since || undefined;
            o.until = until || undefined;
        }
        return o;
    }, [windowMode, months, since, until, max, budget, strategy]);
    const key = JSON.stringify(options);
    const current = estimate && estimate.key === key ? estimate.value : null;

    async function doEstimate() {
        setConflict(null);
        const value = await run('estimate', () => loopApi.miningEstimate(options));
        if (value) setEstimate({ key, value });
    }

    async function doStart() {
        setConflict(null);
        const started = await run('start', async () => {
            try {
                return await loopApi.startMining(options);
            } catch (e) {
                const err = toApiError(e);
                if (err.status !== 409) throw e;
                setConflict(err.message || 'A run is already in progress.');
                return null;
            }
        });
        if (started) onStarted(started);
    }

    return (
        <Section
            title="Start a new run"
            description="Get a cost estimate first. The AI reads each conversation once; later runs reuse that work for free."
        >
            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                <div className="space-y-1.5">
                    <Label>Which conversations</Label>
                    <div className="flex gap-1 text-xs">
                        {(['months', 'dates'] as const).map((m) => (
                            <button
                                key={m}
                                type="button"
                                onClick={() => setWindowMode(m)}
                                className={cn(
                                    'rounded-md border px-2 py-1',
                                    windowMode === m ? 'border-primary bg-primary/10 text-primary' : 'border-border text-muted-foreground',
                                )}
                            >
                                {m === 'months' ? 'Last N months' : 'Date range'}
                            </button>
                        ))}
                    </div>
                    {windowMode === 'months' ? (
                        <div className="flex items-center gap-2">
                            <Input
                                type="number"
                                min={1}
                                max={limits.months}
                                value={months}
                                onChange={(e) => setMonths(e.target.value)}
                                aria-label="Months"
                            />
                            <span className="text-xs text-muted-foreground">months</span>
                        </div>
                    ) : (
                        <div className="grid grid-cols-2 gap-2">
                            <Input type="date" value={since} onChange={(e) => setSince(e.target.value)} aria-label="From" />
                            <Input type="date" value={until} onChange={(e) => setUntil(e.target.value)} aria-label="Until" />
                        </div>
                    )}
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="mining-max">At most this many conversations</Label>
                    <Input
                        id="mining-max"
                        type="number"
                        min={1}
                        max={limits.max_conversations}
                        value={max}
                        onChange={(e) => setMax(e.target.value)}
                    />
                    <p className="text-[11px] text-muted-foreground">Up to {fmt(limits.max_conversations)}.</p>
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="mining-budget">Spending cap (USD)</Label>
                    <Input
                        id="mining-budget"
                        type="number"
                        min={0.01}
                        step="0.01"
                        max={limits.budget_usd}
                        value={budget}
                        onChange={(e) => setBudget(e.target.value)}
                    />
                    <p className="text-[11px] text-muted-foreground">
                        The run pauses before it would go over this. Up to {usd(limits.budget_usd)}.
                    </p>
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="mining-strategy">If there are more than that</Label>
                    <select id="mining-strategy" className={selectClass} value={strategy} onChange={(e) => setStrategy(e.target.value)}>
                        {strategies.map((s) => (
                            <option key={s} value={s}>
                                {s === 'stratified' ? 'Spread over the months' : s === 'newest' ? 'Newest first' : humanize(s)}
                            </option>
                        ))}
                    </select>
                    <p className="text-[11px] text-muted-foreground">
                        {strategy === 'newest'
                            ? 'Takes only the most recent conversations.'
                            : 'Takes some from every month, with more from recent months, so old and new habits are both seen.'}
                    </p>
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <Button type="button" variant="outline" onClick={() => void doEstimate()} disabled={busy !== null}>
                    {busy === 'estimate' ? <Spinner /> : null} Estimate cost
                </Button>
                <Button type="button" onClick={() => void doStart()} disabled={busy !== null || !current}>
                    {busy === 'start' ? <Spinner /> : <PlayIcon className="h-3.5 w-3.5" />} Start run
                </Button>
                {!current && <span className="text-xs text-muted-foreground">Estimate first: Start uses the numbers you estimated.</span>}
            </div>
            <ErrorLine error={error} />
            {conflict && (
                <Notice tone="warning">
                    {conflict}{' '}
                    <button type="button" className="font-medium text-primary underline" onClick={onShowActive}>
                        Show the run in progress
                    </button>
                </Notice>
            )}
            {current && <EstimateView estimate={current} />}
        </Section>
    );
}

function EstimateView({ estimate }: { estimate: MiningEstimate }) {
    const stages = asRecord<MiningStageUsage>(estimate.stages);
    const byMonth = Object.entries(asRecord<number>(estimate.selected_by_month)).sort(([a], [b]) => a.localeCompare(b));
    const maxMonth = Math.max(1, ...byMonth.map(([, v]) => num(v)));
    const total = estimate.total;

    return (
        <div className="space-y-4 rounded-lg border border-border bg-muted/20 p-4">
            {estimate.within_budget === false && (
                <Notice tone="warning">
                    The estimate ({usd(total?.cost_usd)}) is over your cap of {usd(estimate.budget_usd)}. The run will pause when it reaches
                    the cap; raise the cap or pick fewer conversations.
                </Notice>
            )}
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <Mini label="Conversations that qualify" value={fmt(estimate.eligible)} />
                <Mini label="Will be read" value={fmt(estimate.selected)} />
                <Mini label="Already read before (free)" value={fmt(estimate.already_extracted)} />
                <Mini label="New to read" value={fmt(estimate.to_extract)} />
                <Mini
                    label="Estimated cost"
                    value={usd(total?.cost_usd)}
                    hint={`about ${estimate.situations_estimated ?? '?'} situations`}
                />
            </div>
            <UsageTable usage={stages} total={total} estimated />
            {byMonth.length > 0 && (
                <Disclosure title={`Conversations per month (${byMonth.length} months)`}>
                    <ul className="space-y-0.5">
                        {byMonth.map(([month, count]) => (
                            <li key={month} className="flex items-center gap-2 text-xs">
                                <span className="w-16 shrink-0 tabular-nums text-muted-foreground">{month}</span>
                                <span className="h-2 rounded-sm bg-primary/60" style={{ width: `${(num(count) / maxMonth) * 60}%` }} />
                                <span className="tabular-nums">{fmt(count)}</span>
                            </li>
                        ))}
                    </ul>
                </Disclosure>
            )}
            {estimate.embeddings === false && (
                <p className="text-[11px] text-muted-foreground">
                    Similar situations are grouped with one small AI call (embeddings are off).
                </p>
            )}
        </div>
    );
}

function Mini({ label, value, hint }: { label: string; value: React.ReactNode; hint?: React.ReactNode }) {
    return (
        <div className="rounded-lg border border-border bg-card p-3">
            <p className="text-[11px] text-muted-foreground">{label}</p>
            <p className="mt-0.5 text-lg font-semibold tabular-nums">{value}</p>
            {hint && <p className="text-[11px] text-muted-foreground">{hint}</p>}
        </div>
    );
}

function UsageTable({
    usage,
    total,
    estimated = false,
}: {
    usage: Record<string, MiningStageUsage>;
    total?: MiningStageUsage | null;
    estimated?: boolean;
}) {
    const keys = [...USAGE_STAGES.filter((k) => usage[k]), ...Object.keys(usage).filter((k) => !USAGE_STAGES.includes(k))];
    if (keys.length === 0) return null;
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-xs">
                <thead>
                    <tr className="border-b border-border text-left text-muted-foreground">
                        <th className="py-1.5 pr-3 font-medium">Step</th>
                        <th className="py-1.5 pr-3 text-right font-medium">AI calls</th>
                        {!estimated && <th className="py-1.5 pr-3 text-right font-medium">Reused</th>}
                        <th className="py-1.5 pr-3 text-right font-medium">Tokens in</th>
                        <th className="py-1.5 pr-3 text-right font-medium">Tokens out</th>
                        {estimated && <th className="py-1.5 pr-3 font-medium">Model</th>}
                        <th className="py-1.5 text-right font-medium">{estimated ? 'Estimated cost' : 'Cost'}</th>
                    </tr>
                </thead>
                <tbody>
                    {keys.map((k) => {
                        const u = usage[k] ?? ({} as MiningStageUsage);
                        return (
                            <tr key={k} className="border-b border-border/50">
                                <td className="py-1.5 pr-3">{STAGE_LABELS[k] ?? humanize(k)}</td>
                                <td className="py-1.5 pr-3 text-right tabular-nums">{fmt(u.calls)}</td>
                                {!estimated && <td className="py-1.5 pr-3 text-right tabular-nums">{fmt(u.cache_hits)}</td>}
                                <td className="py-1.5 pr-3 text-right tabular-nums">{fmt(u.tokens_in)}</td>
                                <td className="py-1.5 pr-3 text-right tabular-nums">{fmt(u.tokens_out)}</td>
                                {estimated && <td className="py-1.5 pr-3 text-muted-foreground">{u.model ?? ''}</td>}
                                <td className="py-1.5 text-right tabular-nums">{usd(u.cost_usd)}</td>
                            </tr>
                        );
                    })}
                    {total && (
                        <tr className="font-medium">
                            <td className="py-1.5 pr-3">Total</td>
                            <td className="py-1.5 pr-3 text-right tabular-nums">{fmt(total.calls)}</td>
                            {!estimated && <td className="py-1.5 pr-3" />}
                            <td className="py-1.5 pr-3 text-right tabular-nums">{fmt(total.tokens_in)}</td>
                            <td className="py-1.5 pr-3 text-right tabular-nums">{fmt(total.tokens_out)}</td>
                            {estimated && <td className="py-1.5 pr-3" />}
                            <td className="py-1.5 text-right tabular-nums">{usd(total.cost_usd)}</td>
                        </tr>
                    )}
                </tbody>
            </table>
        </div>
    );
}

// ── Runs list ────────────────────────────────────────────────────────────────

function RunsTable({
    runs,
    selectedId,
    onSelect,
}: {
    runs: MiningRunSummary[];
    selectedId: number | null;
    onSelect: (id: number) => void;
}) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b border-border text-left text-xs text-muted-foreground">
                        <th className="py-2 pr-3 font-medium">Run</th>
                        <th className="py-2 pr-3 font-medium">Status</th>
                        <th className="py-2 pr-3 font-medium">Step</th>
                        <th className="py-2 pr-3 text-right font-medium">Conversations</th>
                        <th className="py-2 pr-3 text-right font-medium">Cost / cap</th>
                        <th className="py-2 font-medium">Started by</th>
                    </tr>
                </thead>
                <tbody>
                    {runs.map((r) => (
                        <tr
                            key={r.id}
                            className={cn(
                                'cursor-pointer border-b border-border/50 hover:bg-muted/40',
                                r.id === selectedId && 'bg-primary/5',
                            )}
                            onClick={() => onSelect(r.id)}
                        >
                            <td className="py-2 pr-3">
                                <button type="button" className="font-medium text-primary hover:underline" onClick={() => onSelect(r.id)}>
                                    #{r.id}
                                </button>
                            </td>
                            <td className="py-2 pr-3">
                                <StatusBadge status={r.status} />
                                {r.pause_reason === 'budget' && <span className="ml-1 text-[11px] text-warning">budget</span>}
                            </td>
                            <td className="py-2 pr-3 text-xs">{STAGE_LABELS[r.stage] ?? humanize(String(r.stage ?? ''))}</td>
                            <td className="py-2 pr-3 text-right tabular-nums">{fmt(r.selected)}</td>
                            <td className="py-2 pr-3 text-right text-xs tabular-nums">
                                {usd(r.cost_usd)} / {usd(r.budget_usd)}
                            </td>
                            <td className="py-2 text-xs text-muted-foreground">
                                {r.created_by ?? 'system'} · {shortDate(r.created_at)}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

// ── Run detail ───────────────────────────────────────────────────────────────

function RunDetail({ runId, onChanged }: { runId: number; onChanged: () => void }) {
    const [runState, setRun] = useState<MiningRunDetail | null>(null);
    const [loadError, setLoadError] = useState<ApiError | null>(null);
    const [newCap, setNewCap] = useState('');
    const { busy, error, run: act } = useAction();

    const load = useCallback(async () => {
        try {
            setRun(await loopApi.miningRun(runId));
            setLoadError(null);
        } catch (e) {
            setLoadError(toApiError(e));
        }
    }, [runId]);

    useEffect(() => {
        void load();
    }, [load]);

    const status = runState?.status ?? '';
    const active = ACTIVE.includes(status);
    usePolling(() => void load(), active ? 3000 : 15000, active || status === 'paused');

    async function action(kind: 'pause' | 'resume' | 'cancel', budgetUsd?: number) {
        if (kind === 'cancel' && !window.confirm('Cancel this run? What it has learned so far stays; you can start a new run later.'))
            return;
        const updated = await act(kind, async () => {
            try {
                return await loopApi.miningAction(runId, kind, budgetUsd);
            } catch (e) {
                const body = toApiError(e).body as { run?: MiningRunDetail } | null;
                if (body?.run) setRun((prev) => (prev ? { ...prev, ...body.run } : prev));
                throw e;
            }
        });
        if (updated) {
            setRun(updated);
            setNewCap('');
            onChanged();
        }
    }

    if (loadError && !runState)
        return (
            <Notice tone="danger">
                Run #{runId}: {loadError.message}
            </Notice>
        );
    if (!runState)
        return (
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                <Spinner /> Loading run #{runId}…
            </p>
        );

    const r = runState;
    const stages = asArray<string>(r.stages).length ? asArray<string>(r.stages) : DEFAULT_STAGES;
    const stageIndex = typeof r.stage_index === 'number' ? r.stage_index : Math.max(0, stages.indexOf(String(r.stage)));
    const progress = r.progress ?? {};
    const selected = num(progress.selected ?? r.selected);
    const processed = num(progress.done) + num(progress.failed);
    const budgetPaused = status === 'paused' && r.pause_reason === 'budget';
    const canResume = status === 'paused' || status === 'failed';
    const canCancel = ['queued', 'running', 'paused', 'failed'].includes(status);
    const capValue = Number(newCap);
    const capOk = Number.isFinite(capValue) && capValue > num(r.cost_usd);
    const usage = asRecord<MiningStageUsage>(r.usage);
    const proposals = asRecord<MiningProposalRef>(r.proposals);
    const coverage = asArray<MiningCoverage>(r.coverage);
    const dropped = Object.entries(asRecord<number>(r.dropped)).filter(([, v]) => num(v) > 0);
    const inconsistencies = asArray<MiningInconsistency>(r.inconsistencies);
    const budget = r.budget_usd;
    const spentShare = budget ? num(r.cost_usd) / budget : 0;

    return (
        <>
            <Section
                title={
                    <span className="flex items-center gap-2">
                        Run #{r.id} <StatusBadge status={status} />
                        {active && <Spinner className="text-muted-foreground" />}
                    </span>
                }
                description={`Started ${shortDate(r.started_at ?? r.created_at)}${r.created_by ? ` by ${r.created_by}` : ''}${
                    r.finished_at ? ` · finished ${shortDate(r.finished_at)}` : ''
                }`}
                actions={
                    <div className="flex flex-wrap gap-2">
                        {active && (
                            <Button size="sm" variant="outline" onClick={() => void action('pause')} disabled={busy !== null}>
                                {busy === 'pause' ? <Spinner /> : <PauseIcon className="h-3.5 w-3.5" />} Pause
                            </Button>
                        )}
                        {canResume && !budgetPaused && (
                            <Button size="sm" onClick={() => void action('resume')} disabled={busy !== null}>
                                {busy === 'resume' ? <Spinner /> : <PlayIcon className="h-3.5 w-3.5" />} Resume
                            </Button>
                        )}
                        {canCancel && (
                            <Button size="sm" variant="ghost" onClick={() => void action('cancel')} disabled={busy !== null}>
                                {busy === 'cancel' ? <Spinner /> : <SquareIcon className="h-3.5 w-3.5" />} Cancel
                            </Button>
                        )}
                    </div>
                }
            >
                <ErrorLine error={error} />
                {loadError && <ErrorLine error={`Could not refresh: ${loadError.message}`} />}

                {budgetPaused && (
                    <Notice tone="warning">
                        <p className="font-medium">Stopped because it reached the {usd(budget)} budget.</p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {r.message ?? 'The next AI call would have gone over the cap.'} It has spent {usd(r.cost_usd)} so far. To carry
                            on, set a higher cap.
                        </p>
                        <form
                            className="mt-2 flex flex-wrap items-center gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                if (capOk) void action('resume', capValue);
                            }}
                        >
                            <Input
                                type="number"
                                min={0.01}
                                step="0.01"
                                value={newCap}
                                onChange={(e) => setNewCap(e.target.value)}
                                placeholder={`More than ${usd(r.cost_usd)}`}
                                className="h-9 w-40"
                                aria-label="New spending cap in USD"
                            />
                            <Button type="submit" size="sm" disabled={!capOk || busy !== null}>
                                {busy === 'resume' ? <Spinner /> : <PlayIcon className="h-3.5 w-3.5" />} Resume with new cap
                            </Button>
                            {newCap && !capOk && <span className="text-xs text-destructive">Must be more than {usd(r.cost_usd)}.</span>}
                        </form>
                    </Notice>
                )}
                {status === 'paused' && r.pause_reason !== 'budget' && <Notice>Paused. Resume carries on where it stopped.</Notice>}
                {status === 'failed' && (
                    <Notice tone="danger">It stopped after repeated errors. Resume carries on from the last checkpoint.</Notice>
                )}
                {r.message && !budgetPaused && <p className="text-xs text-muted-foreground">{r.message}</p>}

                {/* Stage stepper */}
                <ol className="flex flex-wrap gap-1.5">
                    {stages.map((s, i) => {
                        const finished = status === 'completed' || stages[stageIndex] === 'done';
                        const done = i < stageIndex || (finished && i <= stageIndex);
                        const now = i === stageIndex && !finished;
                        return (
                            <li
                                key={s}
                                className={cn(
                                    'flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs',
                                    done && 'border-success/30 bg-success/5 text-success',
                                    now && 'border-primary bg-primary/10 font-medium text-primary',
                                    !done && !now && 'border-border text-muted-foreground',
                                )}
                            >
                                {done ? <CheckIcon className="h-3 w-3" /> : now && active ? <Spinner className="h-3 w-3" /> : null}
                                {STAGE_LABELS[s] ?? humanize(s)}
                            </li>
                        );
                    })}
                </ol>

                <div className="space-y-1">
                    <div className="flex justify-between text-xs">
                        <span>
                            Conversations read: {fmt(processed)} of {fmt(selected)}
                        </span>
                        <span className="text-muted-foreground">{selected ? pct(processed / selected) : ''}</span>
                    </div>
                    <ProgressBar value={selected ? processed / selected : 0} />
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Mini
                        label="Read with AI / reused / copies"
                        value={`${fmt(progress.extracted_new)} / ${fmt(progress.reused)} / ${fmt(progress.macro_copies)}`}
                        hint={num(progress.failed) ? `${fmt(progress.failed)} could not be read` : 'Copies = the same canned reply'}
                    />
                    <Mini
                        label="Situations found"
                        value={fmt(progress.situations)}
                        hint={`${fmt(progress.situations_synthesised)} written up${
                            num(progress.situations_failed) ? `, ${fmt(progress.situations_failed)} failed` : ''
                        }`}
                    />
                    <Mini label="Proposals created" value={fmt(progress.proposals_emitted)} hint="Waiting for your review" />
                    <div className="rounded-lg border border-border bg-card p-3">
                        <p className="text-[11px] text-muted-foreground">Spent so far</p>
                        <p className="mt-0.5 text-lg font-semibold tabular-nums">
                            {usd(r.cost_usd)} <span className="text-xs font-normal text-muted-foreground">of {usd(budget)}</span>
                        </p>
                        {budget ? <ProgressBar value={spentShare} className="mt-1" /> : null}
                        {typeof r.remaining_budget_usd === 'number' && (
                            <p className="mt-0.5 text-[11px] text-muted-foreground">{usd(r.remaining_budget_usd)} left</p>
                        )}
                    </div>
                </div>

                {r.last_error && (
                    <div className="rounded-md border border-destructive/20 bg-destructive/5 px-3 py-2 text-xs">
                        <p className="font-medium text-destructive">
                            Last error{r.last_error_at ? ` (${shortDate(r.last_error_at)})` : ''}
                            {num(r.error_count) ? ` · ${plural(num(r.error_count), 'error')} in total` : ''}
                        </p>
                        <p className="mt-0.5 break-words font-mono text-[11px] text-muted-foreground">{r.last_error}</p>
                    </div>
                )}

                <Disclosure title="Cost per step">
                    <UsageTable usage={usage} />
                    {r.tokens && (
                        <p className="mt-1 text-[11px] text-muted-foreground">
                            {fmt(r.tokens.in)} tokens in · {fmt(r.tokens.out)} tokens out
                        </p>
                    )}
                </Disclosure>
            </Section>

            <ProposalsOverview proposals={proposals} voice={r.voice} />

            <Section
                title="Situations"
                description="What customers wrote about, how often, and what was drafted for each. Suggested actions are advice only: allowing an action is always your decision on the playbook page."
            >
                {coverage.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {stageIndex < stages.indexOf('cluster')
                            ? 'Situations appear once the conversations have been read.'
                            : 'No situations.'}
                    </p>
                ) : (
                    <CoverageTable coverage={coverage} />
                )}
            </Section>

            {inconsistencies.length > 0 && (
                <Section
                    title={`Where your answers disagreed (${inconsistencies.length})`}
                    description="Your team answered the same question in different ways over time. The proposals use the most recent answer; check it is the one you want."
                >
                    <InconsistencyList items={inconsistencies} />
                </Section>
            )}

            {dropped.length > 0 && (
                <Section title="Left out" description="Counted but not used. Customer details are never kept in what the agent learns.">
                    <ul className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                        {dropped
                            .sort(([, a], [, b]) => num(b) - num(a))
                            .map(([key, value]) => (
                                <li key={key} className="flex justify-between gap-3 border-b border-border/40 py-1">
                                    <span>{droppedLabel(key)}</span>
                                    <span className="tabular-nums text-muted-foreground">{fmt(value)}</span>
                                </li>
                            ))}
                    </ul>
                </Section>
            )}
        </>
    );
}

// ── Proposals, coverage, inconsistencies ─────────────────────────────────────

function ProposalRef({ refValue }: { refValue: MiningProposalRef | null | undefined }) {
    if (!refValue) return <span className="text-xs text-muted-foreground">—</span>;
    const action = refValue.action ? (PROPOSAL_ACTION_LABELS[refValue.action] ?? humanize(refValue.action)) : null;
    const errors = asArray<unknown>(refValue.errors).map(String);
    return (
        <div className="space-y-0.5 text-xs">
            {refValue.proposal_id ? (
                <Link
                    href={proposalUrl(refValue.proposal_id)}
                    className="inline-flex items-center gap-1.5 font-medium text-primary hover:underline"
                >
                    Proposal #{refValue.proposal_id}
                    {refValue.status && <ProposalStatusBadge status={refValue.status} />}
                </Link>
            ) : null}
            {action && <p className="text-muted-foreground">{action}</p>}
            {refValue.reason && <p className="text-muted-foreground">{refValue.reason}</p>}
            {errors.length > 0 && (
                <Disclosure title={<span className="text-destructive">{plural(errors.length, 'problem')}</span>}>
                    <ul className="space-y-0.5">
                        {errors.map((e, i) => (
                            <li key={i} className="text-[11px] text-destructive">
                                {e}
                            </li>
                        ))}
                    </ul>
                </Disclosure>
            )}
        </div>
    );
}

function ProposalsOverview({ proposals, voice }: { proposals: Record<string, MiningProposalRef>; voice: MiningRunDetail['voice'] }) {
    const summary = proposals['summary'];
    const staff = asArray<{ label: string; replies: number }>(voice?.staff);
    const differences = asArray<{ topic: string; note: string }>(voice?.staff_differences);
    const phrases = asArray<{ text: string; staff?: string | null }>(voice?.example_phrases);
    if (!voice && !summary) return null;

    return (
        <Section title="Brand voice and overall summary">
            <div className="grid gap-4 md:grid-cols-2">
                {voice && (
                    <div className="space-y-2">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <p className="text-sm font-medium">Brand voice</p>
                                <p className="text-xs text-muted-foreground">
                                    Learned from {plural(num(voice.sample_size), 'reply', 'replies')}
                                    {staff.length ? ` by ${staff.map((s) => `${s.label} (${fmt(s.replies)})`).join(', ')}` : ''}.
                                </p>
                            </div>
                            <ProposalRef refValue={voice.proposal ?? proposals['voice']} />
                        </div>
                        {phrases.length > 0 && (
                            <div className="flex flex-wrap gap-1">
                                {phrases.map((p, i) => (
                                    <span
                                        key={i}
                                        className="rounded border border-border bg-muted/40 px-1.5 py-0.5 text-xs"
                                        title={p.staff ?? undefined}
                                    >
                                        “{p.text}”
                                    </span>
                                ))}
                            </div>
                        )}
                        {differences.length > 0 && (
                            <ul className="space-y-0.5 text-xs">
                                {differences.map((d, i) => (
                                    <li key={i}>
                                        <span className="font-medium">{humanize(d.topic ?? '')}:</span>{' '}
                                        <span className="text-muted-foreground">{d.note}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}
                {summary && (
                    <div className="space-y-1">
                        <p className="text-sm font-medium">Disagreements summary</p>
                        <p className="text-xs text-muted-foreground">
                            One proposal stating the most recent answer for each topic your team disagreed on.
                        </p>
                        <ProposalRef refValue={summary} />
                    </div>
                )}
            </div>
        </Section>
    );
}

function CoverageTable({ coverage }: { coverage: MiningCoverage[] }) {
    const maxShare = Math.max(0.0001, ...coverage.map((c) => num(c.share)));
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b border-border text-left text-xs text-muted-foreground">
                        <th className="py-2 pr-3 font-medium">Situation</th>
                        <th className="py-2 pr-3 font-medium">Conversations</th>
                        <th className="py-2 pr-3 font-medium">Status</th>
                        <th className="py-2 pr-3 font-medium">Drafted</th>
                        <th className="py-2 font-medium">Proposal</th>
                    </tr>
                </thead>
                <tbody>
                    {coverage.map((c) => {
                        const st = SITUATION_STATUS[c.status] ?? { label: humanize(String(c.status ?? '')), help: '' };
                        const syn = c.synthesis;
                        const actions = asArray<string>(syn?.suggested_actions);
                        return (
                            <tr key={c.key} className="border-b border-border/50 align-top">
                                <td className="max-w-xs py-2 pr-3">
                                    <p className="font-medium">
                                        {c.name || humanize(c.key)}{' '}
                                        {c.is_new ? (
                                            <Badge variant="default">New</Badge>
                                        ) : c.playbook_id ? (
                                            <a
                                                href={`/agent/playbooks/${c.playbook_id}`}
                                                className="text-xs font-normal text-primary hover:underline"
                                            >
                                                {c.playbook_slug ?? 'playbook'}
                                            </a>
                                        ) : null}
                                    </p>
                                    {c.description && <p className="text-xs text-muted-foreground">{c.description}</p>}
                                    {actions.length > 0 && (
                                        <div className="mt-1 flex flex-wrap items-center gap-1">
                                            <LightbulbIcon className="h-3 w-3 text-warning" />
                                            {actions.map((a) => (
                                                <span
                                                    key={a}
                                                    className="rounded-full border border-warning/30 bg-warning/5 px-1.5 py-0.5 text-[10px]"
                                                    title="Suggested only. Allow it yourself on the playbook page if you want it."
                                                >
                                                    could allow: {humanize(a)}
                                                </span>
                                            ))}
                                        </div>
                                    )}
                                </td>
                                <td className="py-2 pr-3">
                                    <p className="tabular-nums">
                                        {fmt(c.conversation_count)} <span className="text-xs text-muted-foreground">({pct(c.share)})</span>
                                    </p>
                                    <div className="mt-1 h-1.5 w-24 rounded-full bg-muted">
                                        <div
                                            className="h-full rounded-full bg-primary/60"
                                            style={{ width: `${(num(c.share) / maxShare) * 100}%` }}
                                        />
                                    </div>
                                </td>
                                <td className="py-2 pr-3 text-xs">
                                    <span title={st.help}>{st.label}</span>
                                    {c.error && <p className="text-destructive">{c.error}</p>}
                                </td>
                                <td className="py-2 pr-3 text-xs text-muted-foreground">
                                    {syn
                                        ? [
                                              plural(num(syn.rules), 'rule'),
                                              plural(num(syn.steps), 'step'),
                                              plural(num(syn.knowledge), 'fact'),
                                              plural(num(syn.test_cases), 'test'),
                                              num(syn.inconsistencies) ? plural(num(syn.inconsistencies), 'disagreement') : null,
                                          ]
                                              .filter(Boolean)
                                              .join(' · ')
                                        : '—'}
                                </td>
                                <td className="py-2">
                                    <ProposalRef refValue={c.proposal} />
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

function InconsistencyList({ items }: { items: MiningInconsistency[] }) {
    return (
        <ul className="space-y-3">
            {items.map((inc, i) => (
                <li key={`${inc.situation ?? ''}-${inc.topic ?? i}`} className="space-y-1.5 rounded-lg border border-border p-3">
                    <p className="text-sm font-medium">
                        {humanize(inc.topic ?? 'Topic')}
                        {inc.situation_name && (
                            <span className="ml-2 text-xs font-normal text-muted-foreground">in {inc.situation_name}</span>
                        )}
                    </p>
                    {inc.summary && <p className="text-xs text-muted-foreground">{inc.summary}</p>}
                    <ul className="space-y-1">
                        {asArray<NonNullable<MiningInconsistency['versions']>[number]>(inc.versions).map((v, j) => (
                            <li key={j} className={cn('rounded-md px-2 py-1 text-xs', j === 0 ? 'bg-success/5' : 'bg-muted/40')}>
                                <span>“{v.statement}”</span>
                                <span className="ml-1 text-muted-foreground">
                                    {v.last_seen ? `last said ${dateOnly(v.last_seen)}` : ''}
                                    {j === 0 ? ' · most recent' : ''}
                                </span>
                                {asArray<number>(v.evidence).length > 0 && (
                                    <span className="ml-1 inline-flex flex-wrap gap-1">
                                        {asArray<number>(v.evidence).map((id) => (
                                            <a
                                                key={id}
                                                href={conversationUrl(id)}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="inline-flex items-center gap-0.5 text-primary hover:underline"
                                            >
                                                #{id}
                                                <ExternalLinkIcon className="h-2.5 w-2.5" />
                                            </a>
                                        ))}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                    {inc.most_recent && (
                        <p className="text-xs">
                            <span className="font-medium">Most recent answer:</span> {inc.most_recent}
                        </p>
                    )}
                    {inc.recommendation && (
                        <p className="text-xs">
                            <span className="font-medium">Suggestion:</span> {inc.recommendation}
                        </p>
                    )}
                </li>
            ))}
        </ul>
    );
}
