import { useMemo, useState } from 'react';
import { PlayIcon, CalculatorIcon } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import { ErrorLine, Spinner } from '@/Components/agent/LoopParts';
import { loopApi, plural, useAction, usd } from '@/lib/agentLoop';
import type { EvidenceQuality, Simulation, SimulationEstimate, SimulationFilters } from '@/types/agentLoop';
import { SELECT_CLASS } from './simulationShared';

interface Form {
    playbook: string;
    date_from: string;
    date_to: string;
    only_pinned: boolean;
    min_evidence: '' | EvidenceQuality;
    sample: 'stratified' | 'latest' | 'random';
    limit: string;
}

const INITIAL: Form = { playbook: '', date_from: '', date_to: '', only_pinned: false, min_evidence: '', sample: 'stratified', limit: '50' };

function toFilters(form: Form): SimulationFilters {
    const limit = Math.max(1, Math.min(500, parseInt(form.limit, 10) || 50));
    return {
        playbook: form.playbook.trim() || undefined,
        date_from: form.date_from || undefined,
        date_to: form.date_to || undefined,
        only_pinned: form.only_pinned || undefined,
        min_evidence: form.min_evidence || undefined,
        sample: form.sample,
        limit,
    };
}

/** Suggested budget cap: half as much again as the estimate, rounded up to 10 cents. */
function suggestedBudget(estimate: SimulationEstimate): string {
    const usdValue = typeof estimate.estimated_usd === 'number' ? estimate.estimated_usd : 0;
    return Math.max(0.1, Math.ceil(usdValue * 1.5 * 10) / 10).toFixed(2);
}

/**
 * New batch replay: pick past conversations, see what it will cost, set a
 * spending cap, start. Start stays disabled until the current filters have
 * been estimated.
 */
export default function CreateBatch({
    playbookSlugs,
    onCreated,
}: {
    playbookSlugs: string[];
    onCreated: (simulation: Simulation) => void;
}) {
    const [form, setForm] = useState<Form>(INITIAL);
    const [estimate, setEstimate] = useState<{ key: string; value: SimulationEstimate } | null>(null);
    const [budget, setBudget] = useState('');
    const [budgetTouched, setBudgetTouched] = useState(false);
    const { busy, error, run } = useAction();

    const filters = useMemo(() => toFilters(form), [form]);
    const key = JSON.stringify(filters);
    const current = estimate && estimate.key === key ? estimate.value : null;
    const caseCount = current ? (typeof current.cases === 'number' ? current.cases : 0) : 0;

    function set<K extends keyof Form>(field: K, value: Form[K]) {
        setForm((f) => ({ ...f, [field]: value }));
    }

    async function doEstimate() {
        const r = await run('estimate', () => loopApi.estimateSimulation(filters));
        if (!r?.estimate) return;
        setEstimate({ key, value: r.estimate });
        if (!budgetTouched) setBudget(suggestedBudget(r.estimate));
    }

    async function start() {
        const budgetUsd = budget.trim() === '' ? null : Number(budget);
        const sim = await run('start', () =>
            loopApi.createSimulation({ ...filters, budget_usd: Number.isFinite(budgetUsd) ? budgetUsd : null }),
        );
        if (sim) {
            setEstimate(null);
            onCreated(sim);
        }
    }

    return (
        <form
            className="space-y-3"
            onSubmit={(e) => {
                e.preventDefault();
                void doEstimate();
            }}
        >
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1">
                    <Label htmlFor="sim-playbook">Playbook</Label>
                    <Input
                        id="sim-playbook"
                        list="sim-playbook-options"
                        value={form.playbook}
                        placeholder="Any situation"
                        onChange={(e) => set('playbook', e.target.value)}
                    />
                    <datalist id="sim-playbook-options">
                        {playbookSlugs.map((slug) => (
                            <option key={slug} value={slug} />
                        ))}
                    </datalist>
                    <p className="text-[11px] text-muted-foreground">Leave empty for every situation, or type a playbook's short name.</p>
                </div>
                <div className="space-y-1">
                    <Label htmlFor="sim-sample">Which conversations</Label>
                    <select
                        id="sim-sample"
                        className={SELECT_CLASS}
                        value={form.sample}
                        onChange={(e) => set('sample', e.target.value as Form['sample'])}
                    >
                        <option value="stratified">A mix across situations (recommended)</option>
                        <option value="latest">The most recent</option>
                        <option value="random">Random</option>
                    </select>
                </div>
                <div className="space-y-1">
                    <Label htmlFor="sim-from">From</Label>
                    <Input id="sim-from" type="date" value={form.date_from} onChange={(e) => set('date_from', e.target.value)} />
                </div>
                <div className="space-y-1">
                    <Label htmlFor="sim-to">To</Label>
                    <Input id="sim-to" type="date" value={form.date_to} onChange={(e) => set('date_to', e.target.value)} />
                </div>
                <div className="space-y-1">
                    <Label htmlFor="sim-evidence">Order details needed</Label>
                    <select
                        id="sim-evidence"
                        className={SELECT_CLASS}
                        value={form.min_evidence}
                        onChange={(e) => set('min_evidence', e.target.value as Form['min_evidence'])}
                    >
                        <option value="">Any (include old tickets without order data)</option>
                        <option value="reconstructed">At least reconstructed</option>
                        <option value="recorded">Only recorded (strictest fact checks)</option>
                    </select>
                </div>
                <div className="space-y-1">
                    <Label htmlFor="sim-limit">How many (1-500)</Label>
                    <Input
                        id="sim-limit"
                        type="number"
                        min={1}
                        max={500}
                        value={form.limit}
                        onChange={(e) => set('limit', e.target.value)}
                    />
                </div>
            </div>
            <label className="flex items-center gap-2 text-sm">
                <Switch checked={form.only_pinned} onCheckedChange={(v) => set('only_pinned', v)} />
                Only the pinned test cases (the core set)
            </label>

            <div className="flex flex-wrap items-center gap-2">
                <Button type="submit" variant="outline" size="sm" disabled={busy !== null}>
                    {busy === 'estimate' ? <Spinner /> : <CalculatorIcon className="h-3.5 w-3.5" />}
                    Estimate cost
                </Button>
                {estimate && !current && (
                    <span className="text-xs text-muted-foreground">Filters changed: estimate again before starting.</span>
                )}
            </div>

            {current && (
                <div className="space-y-3 rounded-lg border border-border bg-muted/30 p-3">
                    {caseCount === 0 ? (
                        <p className="text-sm">No past conversations match these filters. Widen the dates or the evidence setting.</p>
                    ) : (
                        <p className="text-sm">
                            <span className="font-semibold">{plural(caseCount, 'conversation')}</span> will be replayed
                            {typeof current.cached_cases === 'number' && current.cached_cases > 0
                                ? ` (${current.cached_cases} already scored with this setup, so free)`
                                : ''}
                            . Estimated cost <span className="font-semibold">{usd(current.estimated_usd)}</span>
                            {typeof current.per_case_micro_usd === 'number'
                                ? ` (about ${usd(current.per_case_micro_usd / 1_000_000)} each)`
                                : ''}
                            .
                        </p>
                    )}
                    {caseCount > 0 && (
                        <div className="flex flex-wrap items-end gap-2">
                            <div className="space-y-1">
                                <Label htmlFor="sim-budget">Stop if spending reaches ($)</Label>
                                <Input
                                    id="sim-budget"
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    className="w-36"
                                    value={budget}
                                    onChange={(e) => {
                                        setBudget(e.target.value);
                                        setBudgetTouched(true);
                                    }}
                                />
                            </div>
                            <Button type="button" size="sm" onClick={() => void start()} disabled={busy !== null}>
                                {busy === 'start' ? <Spinner /> : <PlayIcon className="h-3.5 w-3.5" />}
                                Start replay
                            </Button>
                        </div>
                    )}
                    <p className="text-[11px] text-muted-foreground">
                        Nothing is sent to customers and Shopify is not contacted: each conversation is replayed with the order details
                        saved for it.
                    </p>
                </div>
            )}
            <ErrorLine error={error} />
        </form>
    );
}
