import { useCallback, useEffect, useRef, useState } from 'react';
import { FlaskConicalIcon, RefreshCwIcon } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { ErrorLine, ProgressBar, SimulationStatusBadge, Spinner } from '@/Components/agent/LoopParts';
import { AgentPage, EmptyState, Section } from '../AgentParts';
import {
    AGENT_SCREEN_PATHS,
    asArray,
    isSimulationActive,
    loopApi,
    queryParam,
    setQueryParam,
    shortDate,
    toApiError,
    usd,
    useWorkspaceEvents,
    type ApiError,
} from '@/lib/agentLoop';
import { cn } from '@/lib/utils';
import type { Simulation } from '@/types/agentLoop';
import CasesPanel from './CasesPanel';
import CreateBatch from './CreateBatch';
import SimulationDetail from './SimulationDetail';
import { KIND_LABELS, SELECT_CLASS, kindLabel, useFrozenCaseIndex } from './simulationShared';

function initialId(): number | null {
    const raw = queryParam('id');
    const id = raw ? parseInt(raw, 10) : NaN;
    return Number.isFinite(id) && id > 0 ? id : null;
}

/**
 * Simulations: replay past conversations against the current instructions
 * (or a proposed change) and score the AI's reply against what the person
 * actually sent. Also hosts the practice-case build / prepare triggers.
 */
export default function SimulationsIndex() {
    const [list, setList] = useState<Simulation[] | null>(null);
    const [kind, setKind] = useState('');
    const [selectedId, setSelectedId] = useState<number | null>(initialId);
    const [error, setError] = useState<ApiError | null>(null);
    const [loading, setLoading] = useState(false);
    const cases = useFrozenCaseIndex();
    const reloadTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const loadList = useCallback(async () => {
        setLoading(true);
        try {
            const r = await loopApi.simulations({ kind: kind || undefined, per_page: 25 });
            setList(asArray<Simulation>(r.data));
            setError(null);
        } catch (e) {
            setError(toApiError(e));
        } finally {
            setLoading(false);
        }
    }, [kind]);

    useEffect(() => {
        void loadList();
    }, [loadList]);

    useEffect(
        () => () => {
            if (reloadTimer.current) clearTimeout(reloadTimer.current);
        },
        [],
    );

    useWorkspaceEvents({
        // Patch the row in place; a finished run (or one we have not listed yet) reloads the list.
        simulationProgress: (e) => {
            if (list && !list.some((s) => s.id === e.simulation_id)) {
                if (!reloadTimer.current) {
                    reloadTimer.current = setTimeout(() => {
                        reloadTimer.current = null;
                        void loadList();
                    }, 1500);
                }
                return;
            }
            setList((rows) => {
                if (!rows) return rows;
                return rows.map((s) => {
                    if (s.id !== e.simulation_id) return s;
                    const totals = { ...(s.totals ?? {}), ...(e.totals ?? {}) };
                    const casesTotal = typeof totals.cases === 'number' ? totals.cases : 0;
                    const done = typeof totals.done === 'number' ? totals.done : 0;
                    return {
                        ...s,
                        status: e.status ?? s.status,
                        totals,
                        progress: casesTotal > 0 ? done / casesTotal : s.progress,
                        cost_micro_usd: typeof e.cost_micro_usd === 'number' ? e.cost_micro_usd : s.cost_micro_usd,
                        cost_usd: typeof e.cost_micro_usd === 'number' ? e.cost_micro_usd / 1_000_000 : s.cost_usd,
                    };
                });
            });
        },
        simulationFinished: () => void loadList(),
    });

    function select(id: number | null) {
        setSelectedId(id);
        setQueryParam('id', id);
    }

    function replace(sim: Simulation) {
        setList((rows) => (rows ?? []).map((s) => (s.id === sim.id ? sim : s)));
    }

    function created(sim: Simulation) {
        setList((rows) => [sim, ...(rows ?? []).filter((s) => s.id !== sim.id)]);
        select(sim.id);
    }

    const selected = list?.find((s) => s.id === selectedId) ?? null;

    return (
        <AgentPage
            title="Simulations"
            current={AGENT_SCREEN_PATHS.simulations}
            width="max-w-6xl"
            description="Replay past conversations with the agent's current instructions and compare its replies with what your team actually sent. Nothing is sent to customers."
        >
            <div className="grid gap-6 lg:grid-cols-2">
                <Section
                    title="New batch replay"
                    description="Choose which past conversations to replay. You see the cost before anything starts."
                >
                    <CreateBatch playbookSlugs={cases.slugs} onCreated={created} />
                </Section>
                <Section
                    title="Practice cases"
                    description="Past conversations frozen at a customer message, with the reply your team sent as the answer key."
                >
                    <CasesPanel index={cases} />
                </Section>
            </div>

            <div className="grid gap-6 lg:grid-cols-[300px_minmax(0,1fr)]">
                <Section
                    title="Runs"
                    actions={
                        <div className="flex items-center gap-1.5">
                            <select
                                className={cn(SELECT_CLASS, 'h-8 w-auto text-xs')}
                                value={kind}
                                aria-label="Filter by kind"
                                onChange={(e) => setKind(e.target.value)}
                            >
                                <option value="">All kinds</option>
                                {Object.entries(KIND_LABELS).map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                            <Button size="icon-sm" variant="ghost" onClick={() => void loadList()} aria-label="Refresh">
                                {loading ? <Spinner /> : <RefreshCwIcon className="h-3.5 w-3.5" />}
                            </Button>
                        </div>
                    }
                >
                    <ErrorLine error={error} />
                    {list === null ? (
                        <p className="text-sm text-muted-foreground">Loading…</p>
                    ) : list.length === 0 ? (
                        <p className="text-sm text-muted-foreground">No simulations yet. Start a batch replay above.</p>
                    ) : (
                        <ul className="-mx-2 space-y-1">
                            {list.map((s) => {
                                const active = isSimulationActive(s.status);
                                return (
                                    <li key={s.id}>
                                        <button
                                            type="button"
                                            onClick={() => select(s.id)}
                                            aria-current={s.id === selectedId ? 'true' : undefined}
                                            className={cn(
                                                'w-full space-y-1 rounded-lg px-2 py-2 text-left transition-colors hover:bg-muted/50',
                                                s.id === selectedId && 'bg-primary/5 ring-1 ring-primary/20',
                                            )}
                                        >
                                            <div className="flex items-center justify-between gap-2">
                                                <span className="truncate text-sm font-medium">
                                                    {kindLabel(s.kind)} #{s.id}
                                                </span>
                                                <SimulationStatusBadge status={s.status} />
                                            </div>
                                            {(active || s.status === 'paused') && <ProgressBar value={s.progress} />}
                                            <p className="text-[11px] text-muted-foreground">
                                                {typeof s.totals?.done === 'number'
                                                    ? `${s.totals.done}/${s.totals?.cases ?? 0} cases · `
                                                    : ''}
                                                {usd(s.cost_usd)}
                                                {typeof s.budget_usd === 'number' ? ` of ${usd(s.budget_usd)}` : ''}
                                                {s.created_at ? ` · ${shortDate(s.created_at)}` : ''}
                                            </p>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </Section>

                <Section>
                    {selectedId ? (
                        <SimulationDetail
                            key={selectedId}
                            simulationId={selectedId}
                            initial={selected}
                            playbookByCase={cases.playbookByCase}
                            onChanged={replace}
                        />
                    ) : (
                        <EmptyState icon={<FlaskConicalIcon className="h-5 w-5 text-muted-foreground" />} title="Pick a run">
                            Choose a simulation on the left to see its pass rate and each reply next to what your team sent.
                        </EmptyState>
                    )}
                </Section>
            </div>
        </AgentPage>
    );
}
