import { Fragment, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ExternalLinkIcon, PauseIcon, PlayIcon, SquareIcon } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import {
    ErrorLine,
    EvidenceBadge,
    ProgressBar,
    ResultDetail,
    SimulationStatusBadge,
    Spinner,
    TotalsLine,
    VerdictBadge,
} from '@/Components/agent/LoopParts';
import { Notice } from '../AgentParts';
import {
    EVIDENCE_HELP,
    VERDICT_LABELS,
    asArray,
    conversationUrl,
    isSimulationActive,
    loopApi,
    pct,
    plural,
    shortDate,
    toApiError,
    useAction,
    usePolling,
    usd,
    useWorkspaceEvents,
    type ApiError,
} from '@/lib/agentLoop';
import { cn } from '@/lib/utils';
import type { Simulation, SimulationResult, Verdict } from '@/types/agentLoop';
import { SELECT_CLASS, kindLabel } from './simulationShared';

const RESULTS_PAGE = 50;
const FETCH_PAGE = 200;
const MAX_FETCH_PAGES = 5;
const VERDICTS: Verdict[] = ['pass', 'fail', 'regressed', 'improved', 'changed', 'error'];

function num(v: number | null | undefined): number {
    return typeof v === 'number' && Number.isFinite(v) ? v : 0;
}

/** Pass rate = (pass + got better) / (scored cases − errors). */
const PASS_RATE_HELP = 'Pass rate = (passed + got better) ÷ (cases scored so far − errors). Your own pass/fail overrides count.';

function passRate(results: SimulationResult[]): { rate: number | null; passed: number; scored: number } {
    let passed = 0;
    let scored = 0;
    for (const r of results) {
        const v = r.effective_verdict ?? r.verdict;
        if (!v || v === 'error') continue;
        scored++;
        if (v === 'pass' || v === 'improved') passed++;
    }
    return { rate: scored > 0 ? passed / scored : null, passed, scored };
}

function OverrideControl({
    simulationId,
    result,
    onChange,
}: {
    simulationId: number;
    result: SimulationResult;
    onChange: (r: SimulationResult) => void;
}) {
    const [note, setNote] = useState(result.human_override_note ?? '');
    const { busy, error, run } = useAction();

    async function save(verdict: 'pass' | 'fail' | null) {
        const updated = await run(String(verdict), () =>
            loopApi.overrideResult(simulationId, result.id, verdict, note.trim() || undefined),
        );
        if (updated) onChange(updated);
    }

    return (
        <div className="space-y-1.5 rounded-md border border-border bg-muted/20 p-2">
            <p className="text-xs font-medium">Disagree with the judge?</p>
            <div className="flex flex-wrap items-center gap-1.5">
                <Input
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    placeholder="Optional note (why)"
                    className="h-8 max-w-xs text-xs"
                />
                <Button size="sm" variant="outline" className="h-8" disabled={busy !== null} onClick={() => void save('pass')}>
                    {busy === 'pass' ? <Spinner /> : null} Mark pass
                </Button>
                <Button size="sm" variant="outline" className="h-8" disabled={busy !== null} onClick={() => void save('fail')}>
                    {busy === 'fail' ? <Spinner /> : null} Mark fail
                </Button>
                {result.human_override && (
                    <Button size="sm" variant="ghost" className="h-8" disabled={busy !== null} onClick={() => void save(null)}>
                        {busy === 'null' ? <Spinner /> : null} Clear my override
                    </Button>
                )}
            </div>
            <ErrorLine error={error} />
        </div>
    );
}

/**
 * One simulation: progress and controls, pass rates (overall and per
 * playbook), and the scored cases with the AI reply next to what the person
 * actually sent.
 */
export default function SimulationDetail({
    simulationId,
    initial,
    playbookByCase,
    onChanged,
}: {
    simulationId: number;
    initial: Simulation | null;
    playbookByCase: Map<number, string | null>;
    onChanged: (simulation: Simulation) => void;
}) {
    const [sim, setSim] = useState<Simulation | null>(initial);
    const [results, setResults] = useState<SimulationResult[] | null>(null);
    const [loadError, setLoadError] = useState<ApiError | null>(null);
    const [verdict, setVerdict] = useState<'' | Verdict>('');
    const [page, setPage] = useState(1);
    const [expanded, setExpanded] = useState<number | null>(null);
    const { busy, error, run } = useAction();
    const lastResultsLoad = useRef(0);
    const resultsTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const alive = useRef(true);
    const onChangedRef = useRef(onChanged);
    onChangedRef.current = onChanged;

    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
            if (resultsTimer.current) clearTimeout(resultsTimer.current);
        };
    }, []);

    const loadSim = useCallback(async () => {
        try {
            const s = await loopApi.simulation(simulationId);
            if (!alive.current) return;
            setSim(s);
            onChangedRef.current(s);
        } catch (e) {
            if (alive.current) setLoadError(toApiError(e));
        }
    }, [simulationId]);

    const loadResults = useCallback(async () => {
        lastResultsLoad.current = Date.now();
        try {
            const all: SimulationResult[] = [];
            for (let p = 1; p <= MAX_FETCH_PAGES; p++) {
                const r = await loopApi.simulationResults(simulationId, { per_page: FETCH_PAGE, page: p });
                all.push(...asArray<SimulationResult>(r.data));
                if (!r.meta || r.meta.current_page >= r.meta.last_page) break;
            }
            if (alive.current) setResults(all);
        } catch (e) {
            if (alive.current) setLoadError(toApiError(e));
        }
    }, [simulationId]);

    /** At most one results reload every 3 seconds while cases stream in. */
    const scheduleResults = useCallback(() => {
        if (resultsTimer.current) return;
        const wait = Math.max(0, 3000 - (Date.now() - lastResultsLoad.current));
        resultsTimer.current = setTimeout(() => {
            resultsTimer.current = null;
            void loadResults();
        }, wait);
    }, [loadResults]);

    useEffect(() => {
        void loadSim();
        void loadResults();
    }, [loadSim, loadResults]);

    useWorkspaceEvents({
        simulationProgress: (e) => {
            if (e.simulation_id !== simulationId) return;
            void loadSim();
            scheduleResults();
        },
        simulationFinished: (e) => {
            if (e.simulation_id !== simulationId) return;
            void loadSim();
            void loadResults();
        },
    });

    const active = isSimulationActive(sim?.status);
    usePolling(
        () => {
            void loadSim();
            scheduleResults();
        },
        5000,
        active,
    );

    const all = useMemo(() => results ?? [], [results]);
    const filtered = useMemo(() => (verdict ? all.filter((r) => (r.effective_verdict ?? r.verdict) === verdict) : all), [all, verdict]);
    const pages = Math.max(1, Math.ceil(filtered.length / RESULTS_PAGE));
    const shown = filtered.slice((Math.min(page, pages) - 1) * RESULTS_PAGE, Math.min(page, pages) * RESULTS_PAGE);

    const overall = useMemo(() => passRate(all), [all]);
    const byPlaybook = useMemo(() => {
        const groups = new Map<string, SimulationResult[]>();
        for (const r of all) {
            const slug = playbookByCase.get(r.frozen_case_id) ?? null;
            const key = slug ?? 'Unknown';
            groups.set(key, [...(groups.get(key) ?? []), r]);
        }
        return [...groups.entries()]
            .map(([slug, rs]) => ({ slug, ...passRate(rs), total: rs.length }))
            .sort((a, b) => b.total - a.total || a.slug.localeCompare(b.slug));
    }, [all, playbookByCase]);

    async function act(action: 'pause' | 'resume' | 'cancel') {
        if (action === 'cancel' && !window.confirm('Cancel this simulation? Cases already scored are kept; the rest are skipped.')) return;
        const fn = action === 'pause' ? loopApi.pauseSimulation : action === 'resume' ? loopApi.resumeSimulation : loopApi.cancelSimulation;
        const s = await run(action, () => fn(simulationId));
        if (s) {
            setSim(s);
            onChanged(s);
        }
    }

    function updateRow(updated: SimulationResult) {
        setResults((rs) => (rs ?? []).map((r) => (r.id === updated.id ? updated : r)));
    }

    if (!sim) {
        return loadError ? <ErrorLine error={loadError} /> : <p className="text-sm text-muted-foreground">Loading…</p>;
    }

    const totals = sim.totals ?? {};
    const casesTotal = num(totals.cases);
    const done = num(totals.done);
    const budgetUsd = typeof sim.budget_usd === 'number' ? sim.budget_usd : null;
    const hasRecordedGap = all.some((r) => r.evidence_quality && r.evidence_quality !== 'recorded');

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 space-y-1">
                    <h2 className="text-base font-semibold">
                        {kindLabel(sim.kind)} #{sim.id}
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        Started {shortDate(sim.started_at ?? sim.created_at) || 'not yet'}
                        {sim.finished_at ? ` · finished ${shortDate(sim.finished_at)}` : ''}
                        {sim.proposal_id ? ' · tests for a proposed change' : ''}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <SimulationStatusBadge status={sim.status} />
                    {active && (
                        <Button size="sm" variant="outline" onClick={() => void act('pause')} disabled={busy !== null}>
                            {busy === 'pause' ? <Spinner /> : <PauseIcon className="h-3.5 w-3.5" />} Pause
                        </Button>
                    )}
                    {sim.status === 'paused' && (
                        <Button size="sm" variant="outline" onClick={() => void act('resume')} disabled={busy !== null}>
                            {busy === 'resume' ? <Spinner /> : <PlayIcon className="h-3.5 w-3.5" />} Resume
                        </Button>
                    )}
                    {(active || sim.status === 'paused') && (
                        <Button size="sm" variant="ghost" onClick={() => void act('cancel')} disabled={busy !== null}>
                            {busy === 'cancel' ? <Spinner /> : <SquareIcon className="h-3.5 w-3.5" />} Cancel
                        </Button>
                    )}
                </div>
            </div>
            <ErrorLine error={error} />

            <div className="space-y-1.5">
                <div className="flex flex-wrap items-center justify-between gap-2 text-xs">
                    <span>
                        {done} of {plural(casesTotal, 'case')} scored
                        {num(totals.cached) > 0 ? ` (${num(totals.cached)} reused from earlier, free)` : ''}
                    </span>
                    <span className="text-muted-foreground">
                        Spent {usd(sim.cost_usd)}
                        {budgetUsd !== null ? ` of ${usd(budgetUsd)} cap` : ''}
                        {typeof sim.estimated_cost_usd === 'number' && sim.estimated_cost_usd > 0
                            ? ` · estimate ${usd(sim.estimated_cost_usd)}`
                            : ''}
                    </span>
                </div>
                <ProgressBar value={num(sim.progress)} />
                <TotalsLine simulation={sim} />
            </div>

            {sim.status === 'budget_exhausted' && (
                <Notice tone="warning">
                    This run stopped because the next case would have gone over the spending cap. The cases scored so far are below. To
                    score the rest, start a new batch with a higher cap.
                </Notice>
            )}
            {sim.status === 'failed' && sim.error && <Notice tone="danger">The run failed: {sim.error}</Notice>}

            <div className="grid gap-3 sm:grid-cols-[200px_minmax(0,1fr)]">
                <div className="rounded-lg border border-border p-3" title={PASS_RATE_HELP}>
                    <p className="text-xs text-muted-foreground">Pass rate</p>
                    <p className="mt-1 text-2xl font-semibold tabular-nums">{pct(overall.rate)}</p>
                    <p className="text-[11px] text-muted-foreground">
                        {overall.passed} of {overall.scored} scored
                    </p>
                </div>
                <div className="rounded-lg border border-border p-3">
                    <p className="mb-1.5 text-xs text-muted-foreground" title={PASS_RATE_HELP}>
                        By playbook
                    </p>
                    {byPlaybook.length === 0 ? (
                        <p className="text-xs text-muted-foreground">No scored cases yet.</p>
                    ) : (
                        <ul className="space-y-1">
                            {byPlaybook.map((g) => (
                                <li key={g.slug} className="flex items-center gap-2 text-xs">
                                    <span className="w-40 truncate font-medium">{g.slug}</span>
                                    <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                                        <div className="h-full rounded-full bg-success" style={{ width: `${(g.rate ?? 0) * 100}%` }} />
                                    </div>
                                    <span className="w-24 text-right tabular-nums text-muted-foreground">
                                        {pct(g.rate)} · {g.passed}/{g.scored}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>

            {hasRecordedGap && (
                <Notice>
                    <p className="font-medium">About order details on old tickets</p>
                    <ul className="mt-1 space-y-0.5 text-xs">
                        <li>
                            <EvidenceBadge quality="recorded" /> {EVIDENCE_HELP.recorded}
                        </li>
                        <li>
                            <EvidenceBadge quality="reconstructed" /> {EVIDENCE_HELP.reconstructed}
                        </li>
                        <li>
                            <EvidenceBadge quality="none" /> {EVIDENCE_HELP.none}
                        </li>
                    </ul>
                </Notice>
            )}

            <div className="space-y-2">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h3 className="text-sm font-semibold">Each case</h3>
                    <select
                        className={cn(SELECT_CLASS, 'h-8 w-auto text-xs')}
                        value={verdict}
                        aria-label="Filter by verdict"
                        onChange={(e) => {
                            setVerdict(e.target.value as '' | Verdict);
                            setPage(1);
                        }}
                    >
                        <option value="">All verdicts ({all.length})</option>
                        {VERDICTS.map((v) => (
                            <option key={v} value={v}>
                                {VERDICT_LABELS[v]} ({all.filter((r) => (r.effective_verdict ?? r.verdict) === v).length})
                            </option>
                        ))}
                    </select>
                </div>
                {loadError && <ErrorLine error={loadError} />}
                {results === null ? (
                    <p className="text-xs text-muted-foreground">Loading results…</p>
                ) : filtered.length === 0 ? (
                    <p className="text-xs text-muted-foreground">{active ? 'Results appear here as cases are scored.' : 'No results.'}</p>
                ) : (
                    <div className="overflow-x-auto rounded-lg border border-border">
                        <table className="w-full text-xs">
                            <thead className="bg-muted/40 text-left text-[11px] text-muted-foreground">
                                <tr>
                                    <th className="px-2 py-1.5 font-medium">Case</th>
                                    <th className="px-2 py-1.5 font-medium">Verdict</th>
                                    <th className="px-2 py-1.5 font-medium" title="Judge scores out of 5: outcome / policy / facts / tone">
                                        Scores
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">Order data</th>
                                    <th className="px-2 py-1.5 font-medium">Judge says</th>
                                </tr>
                            </thead>
                            <tbody>
                                {shown.map((r) => {
                                    const judge = r.scores?.judge ?? null;
                                    const s = (v: number | null | undefined) => (typeof v === 'number' ? v : '–');
                                    const open = expanded === r.id;
                                    return (
                                        <Fragment key={r.id}>
                                            <tr
                                                className={cn(
                                                    'cursor-pointer border-t border-border hover:bg-muted/30',
                                                    open && 'bg-muted/30',
                                                )}
                                                onClick={() => setExpanded(open ? null : r.id)}
                                                aria-expanded={open}
                                            >
                                                <td className="max-w-[16rem] px-2 py-1.5">
                                                    <div className="flex items-center gap-1">
                                                        <span className="truncate font-medium">
                                                            {r.test_case_name || r.subject || `Case ${r.frozen_case_id}`}
                                                        </span>
                                                        {r.conversation_id && (
                                                            <a
                                                                href={conversationUrl(r.conversation_id)}
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                className="shrink-0 text-muted-foreground hover:text-primary"
                                                                onClick={(e) => e.stopPropagation()}
                                                                aria-label="Open the conversation in a new tab"
                                                            >
                                                                <ExternalLinkIcon className="h-3 w-3" />
                                                            </a>
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="px-2 py-1.5">
                                                    <VerdictBadge
                                                        verdict={r.effective_verdict ?? r.verdict}
                                                        overridden={!!r.human_override}
                                                    />
                                                </td>
                                                <td className="whitespace-nowrap px-2 py-1.5 tabular-nums text-muted-foreground">
                                                    {judge && judge.status !== 'failed'
                                                        ? `${s(judge.outcome_match)} / ${s(judge.policy_consistency)} / ${s(judge.factual_grounding)} / ${s(judge.tone_match)}`
                                                        : '—'}
                                                </td>
                                                <td className="px-2 py-1.5">
                                                    <EvidenceBadge quality={r.evidence_quality} />
                                                </td>
                                                <td className="max-w-[20rem] truncate px-2 py-1.5 text-muted-foreground">
                                                    {judge?.reason ?? r.judge_notes ?? r.error ?? ''}
                                                </td>
                                            </tr>
                                            {open && (
                                                <tr className="border-t border-border">
                                                    <td colSpan={5} className="space-y-2 bg-background p-3">
                                                        <ResultDetail result={r} compareBaseline={sim.compares_baseline} />
                                                        <OverrideControl simulationId={sim.id} result={r} onChange={updateRow} />
                                                    </td>
                                                </tr>
                                            )}
                                        </Fragment>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
                {pages > 1 && (
                    <div className="flex items-center justify-end gap-2 text-xs">
                        <Button size="sm" variant="outline" className="h-7" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                            Previous
                        </Button>
                        <span className="text-muted-foreground">
                            Page {Math.min(page, pages)} of {pages}
                        </span>
                        <Button size="sm" variant="outline" className="h-7" disabled={page >= pages} onClick={() => setPage((p) => p + 1)}>
                            Next
                        </Button>
                    </div>
                )}
            </div>
        </div>
    );
}
