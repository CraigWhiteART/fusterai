import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ExternalLinkIcon, FlaskConicalIcon, PinIcon, PlayIcon } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import { Textarea } from '@/Components/ui/textarea';
import { ErrorLine, GateSummary, ResultDetail, Spinner, VerdictBadge } from '@/Components/agent/LoopParts';
import { splitList } from '@/Components/conversation/SaveTestCase';
import {
    AGENT_SCREEN_PATHS,
    asArray,
    conversationUrl,
    isSimulationActive,
    loopApi,
    shortDate,
    toApiError,
    usePolling,
    useWorkspaceEvents,
    type ApiError,
} from '@/lib/agentLoop';
import { cn } from '@/lib/utils';
import type { Simulation, SimulationResult, TestCase, TestExpectation } from '@/types/agentLoop';
import { Section } from '../AgentParts';

const SELECT = 'h-8 w-full rounded-md border border-input bg-background px-2 text-xs';

function summary(e: TestExpectation | null | undefined): string {
    if (!e) return 'Judged against what the person actually sent.';
    const parts: string[] = [];
    if (asArray<string>(e.must_include).length) parts.push(`must mention ${e.must_include.map((s) => `“${s}”`).join(', ')}`);
    if (asArray<string>(e.must_not_include).length) parts.push(`must never say ${e.must_not_include.map((s) => `“${s}”`).join(', ')}`);
    if (e.expected_outcome === 'draft') parts.push('should write a reply');
    if (e.expected_outcome === 'handover') parts.push('should hand over to a person');
    if (asArray<string>(e.expected_actions).length) parts.push(`should propose ${e.expected_actions.join(', ')}`);
    return parts.length ? `The reply ${parts.join('; ')}.` : 'Judged against what the person actually sent.';
}

function EditForm({ test, onSaved, onCancel }: { test: TestCase; onSaved: (t: TestCase) => void; onCancel: () => void }) {
    const e = test.expectation;
    const [name, setName] = useState(test.name);
    const [mustInclude, setMustInclude] = useState(asArray<string>(e?.must_include).join('\n'));
    const [mustNot, setMustNot] = useState(asArray<string>(e?.must_not_include).join('\n'));
    const [outcome, setOutcome] = useState<string>(e?.expected_outcome ?? '');
    const [notes, setNotes] = useState(e?.notes ?? '');
    const [pinned, setPinned] = useState(test.pinned);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);

    async function save() {
        setBusy(true);
        setError(null);
        try {
            onSaved(
                await loopApi.updateTestCase(test.id, {
                    name: name.trim() || test.name,
                    pinned,
                    expectation: {
                        ...e,
                        must_include: splitList(mustInclude),
                        must_not_include: splitList(mustNot),
                        expected_outcome: outcome === 'draft' || outcome === 'handover' ? outcome : null,
                        notes: notes.trim() || null,
                    },
                }),
            );
        } catch (err) {
            setError(toApiError(err));
        } finally {
            setBusy(false);
        }
    }

    return (
        <form
            className="grid gap-2 sm:grid-cols-2"
            onSubmit={(ev) => {
                ev.preventDefault();
                void save();
            }}
        >
            <div className="space-y-1 sm:col-span-2">
                <Label className="text-xs">Name</Label>
                <Input value={name} onChange={(ev) => setName(ev.target.value)} maxLength={255} className="h-8 text-xs" />
            </div>
            <div className="space-y-1">
                <Label className="text-xs">Must mention (one per line)</Label>
                <Textarea value={mustInclude} onChange={(ev) => setMustInclude(ev.target.value)} rows={3} className="text-xs" />
            </div>
            <div className="space-y-1">
                <Label className="text-xs">Must never say (one per line)</Label>
                <Textarea value={mustNot} onChange={(ev) => setMustNot(ev.target.value)} rows={3} className="text-xs" />
            </div>
            <div className="space-y-1">
                <Label className="text-xs">The agent should</Label>
                <select value={outcome} onChange={(ev) => setOutcome(ev.target.value)} className={SELECT}>
                    <option value="draft">Write a reply</option>
                    <option value="handover">Hand it to a person</option>
                    <option value="">Either is fine</option>
                </select>
            </div>
            <div className="space-y-1">
                <Label className="text-xs">Notes for the judge</Label>
                <Input value={notes} onChange={(ev) => setNotes(ev.target.value)} className="h-8 text-xs" />
            </div>
            <label className="flex items-center gap-2 text-xs sm:col-span-2">
                <Switch checked={pinned} onCheckedChange={setPinned} /> Core test (also runs on every brand voice change)
            </label>
            <div className="flex gap-1.5 sm:col-span-2">
                <Button type="submit" size="sm" className="h-7 text-xs" disabled={busy}>
                    {busy ? <Spinner className="h-3 w-3" /> : null} Save
                </Button>
                <Button type="button" size="sm" variant="ghost" className="h-7 text-xs" onClick={onCancel}>
                    Cancel
                </Button>
            </div>
            <ErrorLine error={error} className="sm:col-span-2" />
        </form>
    );
}

function TestRow({
    test,
    result,
    compareBaseline,
    onChange,
}: {
    test: TestCase;
    result: SimulationResult | null;
    compareBaseline: boolean;
    onChange: (t: TestCase) => void;
}) {
    const [mode, setMode] = useState<'closed' | 'result' | 'edit'>('closed');
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const archived = test.status === 'archived';

    async function act(key: string, fn: () => Promise<TestCase>) {
        setBusy(key);
        setError(null);
        try {
            onChange(await fn());
        } catch (e) {
            setError(toApiError(e));
        } finally {
            setBusy(null);
        }
    }

    return (
        <li className={cn('rounded-lg border border-border bg-background p-3', archived && 'opacity-60')}>
            <div className="flex flex-wrap items-start gap-2">
                <div className="min-w-0 flex-1">
                    <p className="flex flex-wrap items-center gap-1.5 text-sm font-medium">
                        {test.pinned && <PinIcon className="h-3 w-3 text-primary" aria-label="Core test" />}
                        {test.name}
                        {test.status !== 'active' && (
                            <Badge variant="outline">{test.status === 'draft' ? 'Not active yet' : 'Archived'}</Badge>
                        )}
                    </p>
                    <p className="text-xs text-muted-foreground">{summary(test.expectation)}</p>
                    <p className="text-[11px] text-muted-foreground">
                        {test.source === 'feedback'
                            ? 'From feedback'
                            : test.source === 'mining'
                              ? 'From history mining'
                              : test.source === 'qa'
                                ? 'From a quality check'
                                : 'Added by hand'}
                        {' · '}
                        {shortDate(test.created_at)}
                        {test.frozen_case?.conversation_id ? (
                            <>
                                {' · '}
                                <a
                                    href={conversationUrl(test.frozen_case.conversation_id)}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="inline-flex items-center gap-0.5 hover:text-primary"
                                >
                                    conversation <ExternalLinkIcon className="h-2.5 w-2.5" />
                                </a>
                            </>
                        ) : null}
                    </p>
                </div>
                <div className="flex shrink-0 flex-wrap items-center gap-1">
                    {result ? (
                        <button
                            type="button"
                            onClick={() => setMode((m) => (m === 'result' ? 'closed' : 'result'))}
                            title="Show the last result"
                        >
                            <VerdictBadge verdict={result.effective_verdict ?? result.verdict} overridden={!!result.human_override} />
                        </button>
                    ) : (
                        <span className="text-[11px] text-muted-foreground">Not run yet</span>
                    )}
                    <Button
                        size="xs"
                        variant="ghost"
                        className="h-6 text-[11px]"
                        onClick={() => setMode((m) => (m === 'edit' ? 'closed' : 'edit'))}
                    >
                        Edit
                    </Button>
                    <Button
                        size="xs"
                        variant="ghost"
                        className="h-6 text-[11px]"
                        disabled={busy !== null}
                        onClick={() => void act('pin', () => loopApi.updateTestCase(test.id, { pinned: !test.pinned }))}
                    >
                        {busy === 'pin' ? <Spinner className="h-3 w-3" /> : null}
                        {test.pinned ? 'Unpin' : 'Pin'}
                    </Button>
                    {archived || test.status === 'draft' ? (
                        <Button
                            size="xs"
                            variant="ghost"
                            className="h-6 text-[11px]"
                            disabled={busy !== null}
                            onClick={() => void act('activate', () => loopApi.updateTestCase(test.id, { status: 'active' }))}
                        >
                            {archived ? 'Restore' : 'Activate'}
                        </Button>
                    ) : (
                        <Button
                            size="xs"
                            variant="ghost"
                            className="h-6 text-[11px] text-muted-foreground hover:text-destructive"
                            disabled={busy !== null}
                            onClick={() => {
                                if (window.confirm(`Archive “${test.name}”? It stops running but its past results are kept.`)) {
                                    void act('archive', () => loopApi.archiveTestCase(test.id));
                                }
                            }}
                        >
                            Archive
                        </Button>
                    )}
                </div>
            </div>
            {mode === 'result' && result && (
                <div className="mt-2 border-t border-border pt-2">
                    <ResultDetail result={result} compareBaseline={compareBaseline} />
                </div>
            )}
            {mode === 'edit' && (
                <div className="mt-2 border-t border-border pt-2">
                    <EditForm
                        test={test}
                        onSaved={(t) => {
                            onChange(t);
                            setMode('closed');
                        }}
                        onCancel={() => setMode('closed')}
                    />
                </div>
            )}
            <ErrorLine error={error} className="mt-1" />
        </li>
    );
}

/**
 * The playbook's saved test cases with their latest result, and "run all".
 * Test cases are added from a conversation ("Save as an agent test case" in
 * the side panel) or created when a proposal is approved.
 */
export default function PlaybookTests({ playbookId }: { playbookId: number }) {
    const [tests, setTests] = useState<TestCase[] | null>(null);
    const [simulation, setSimulation] = useState<Simulation | null>(null);
    const [results, setResults] = useState<Map<number, SimulationResult>>(new Map());
    const [showArchived, setShowArchived] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const [running, setRunning] = useState(false);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const loadResults = useCallback(async (sim: Simulation | null) => {
        if (!sim) return setResults(new Map());
        try {
            const r = await loopApi.simulationResults(sim.id, { per_page: 200 });
            const map = new Map<number, SimulationResult>();
            for (const row of asArray<SimulationResult>(r.data)) if (row.test_case_id) map.set(row.test_case_id, row);
            setResults(map);
        } catch {
            // results are a nice-to-have next to each test
        }
    }, []);

    const loadSimulation = useCallback(async () => {
        try {
            const r = await loopApi.simulations({ kind: 'playbook_tests', per_page: 50 });
            const latest = asArray<Simulation>(r.data).find((s) => s.playbook_id === playbookId) ?? null;
            setSimulation(latest);
            await loadResults(latest);
        } catch {
            // no simulations yet, or not reachable: the list still works
        }
    }, [playbookId, loadResults]);

    const loadTests = useCallback(async () => {
        try {
            const r = await loopApi.testCases({ playbook_id: playbookId, per_page: 200 });
            setTests(asArray<TestCase>(r.data));
            setError(null);
        } catch (e) {
            setError(toApiError(e));
            setTests([]);
        }
    }, [playbookId]);

    useEffect(() => {
        void loadTests();
        void loadSimulation();
    }, [loadTests, loadSimulation]);
    useEffect(
        () => () => {
            if (timer.current) clearTimeout(timer.current);
        },
        [],
    );

    const soon = useCallback(() => {
        if (timer.current) clearTimeout(timer.current);
        timer.current = setTimeout(() => void loadSimulation(), 1500);
    }, [loadSimulation]);
    useWorkspaceEvents({
        simulationProgress: (e) => e.playbook_id === playbookId && soon(),
        simulationFinished: (e) => e.playbook_id === playbookId && soon(),
    });
    usePolling(() => void loadSimulation(), 6000, !!simulation && isSimulationActive(simulation.status));

    async function runAll() {
        setRunning(true);
        setError(null);
        try {
            const sim = await loopApi.runPlaybookTests(playbookId);
            setSimulation(sim);
            setResults(new Map());
        } catch (e) {
            setError(toApiError(e));
        } finally {
            setRunning(false);
        }
    }

    const visible = useMemo(() => (tests ?? []).filter((t) => showArchived || t.status !== 'archived'), [tests, showArchived]);
    const activeCount = (tests ?? []).filter((t) => t.status === 'active').length;
    const archivedCount = (tests ?? []).filter((t) => t.status === 'archived').length;
    const simActive = !!simulation && isSimulationActive(simulation.status);

    return (
        <div id="tests">
            <Section
                title="Tests"
                description="Past conversations this playbook must keep getting right. They re-run automatically before a change to this playbook is approved, and you can run them any time."
                actions={
                    <Button size="sm" variant="outline" onClick={() => void runAll()} disabled={running || simActive || activeCount === 0}>
                        {running || simActive ? <Spinner /> : <PlayIcon className="h-3.5 w-3.5" />} Run all tests
                    </Button>
                }
            >
                {simulation && (
                    <div className="rounded-lg border border-border bg-muted/20 p-3">
                        <GateSummary simulation={simulation} canManage={false} title={`Last run ${shortDate(simulation.created_at)}`} />
                        <a href={`${AGENT_SCREEN_PATHS.simulations}&id=${simulation.id}`} className="text-xs text-primary hover:underline">
                            Full results
                        </a>
                    </div>
                )}
                {tests === null ? (
                    <p className="flex items-center gap-2 text-xs text-muted-foreground">
                        <Spinner /> Loading tests…
                    </p>
                ) : visible.length === 0 ? (
                    <div className="flex items-start gap-2 rounded-lg border border-dashed border-border p-4 text-xs text-muted-foreground">
                        <FlaskConicalIcon className="mt-0.5 h-4 w-4 shrink-0" />
                        <span>
                            No tests yet. Open a conversation this playbook should handle and use{' '}
                            <span className="font-medium">Save as an agent test case</span> in the side panel. Approving feedback in the
                            inbox also saves the conversation as a test.
                        </span>
                    </div>
                ) : (
                    <ul className="space-y-2">
                        {visible.map((t) => (
                            <TestRow
                                key={t.id}
                                test={t}
                                result={results.get(t.id) ?? null}
                                compareBaseline={!!simulation?.compares_baseline}
                                onChange={(next) =>
                                    setTests((prev) =>
                                        (prev ?? []).map((x) =>
                                            x.id === next.id ? { ...x, ...next, frozen_case: next.frozen_case ?? x.frozen_case } : x,
                                        ),
                                    )
                                }
                            />
                        ))}
                    </ul>
                )}
                {archivedCount > 0 && (
                    <label className="flex items-center gap-2 text-xs text-muted-foreground">
                        <Switch checked={showArchived} onCheckedChange={setShowArchived} /> Show {archivedCount} archived
                    </label>
                )}
                <ErrorLine error={error} />
            </Section>
        </div>
    );
}
