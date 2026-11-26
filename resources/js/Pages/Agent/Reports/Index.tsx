import React, { useEffect, useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import { BarChart3Icon, DownloadIcon, ExternalLinkIcon, ShieldIcon } from 'lucide-react';
import { Bar, BarChart, CartesianGrid, Line, LineChart, XAxis, YAxis } from 'recharts';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { ChartContainer, ChartTooltip, type ChartConfig } from '@/Components/ui/chart';
import { Disclosure, ErrorLine, Spinner } from '@/Components/agent/LoopParts';
import { AGENT_SCREEN_PATHS, asArray, asRecord, pct, queryParam, setQueryParam, toApiError, usd, type ApiError } from '@/lib/agentLoop';
import {
    AUTHOR_LABELS,
    AUTHOR_ORDER,
    DEFINITION_LABELS,
    PURPOSE_LABELS,
    ROLE_LABELS,
    SERIES_COLORS,
    count,
    dayLabel,
    humanDuration,
    isIsoDate,
    labelFor,
    num,
    numOrNull,
    performanceCsvUrl,
    periodLabel,
    presetRange,
    reportsApi,
    score5,
    type ReportQuery,
} from '@/lib/agentReports';
import { cn } from '@/lib/utils';
import type {
    PerformanceReportPayload,
    PerformanceRow,
    QaAggregateAuthorRow,
    QaAggregatesPayload,
    QaAuthorRow,
    ReportGroup,
} from '@/types/agentReports';
import { AgentPage, EmptyState, Notice, Section } from '../AgentParts';

// ── Filters (kept in the URL so the screen is shareable) ─────────────────────

const PRESETS = [7, 30, 90] as const;
type Preset = (typeof PRESETS)[number] | 'custom';

/** Below this many replies an average is a rough guide only. */
const SMALL_SAMPLE = 10;

interface Filters {
    preset: Preset;
    from: string;
    to: string;
    group: ReportGroup;
    playbookId: number | null;
}

function initialFilters(): Filters {
    const range = queryParam('range');
    const from = queryParam('from');
    const to = queryParam('to');
    const group: ReportGroup = queryParam('group') === 'week' ? 'week' : 'day';
    const pb = Number(queryParam('playbook'));
    const playbookId = Number.isInteger(pb) && pb > 0 ? pb : null;
    if ((range === 'custom' || !range) && isIsoDate(from) && isIsoDate(to)) {
        return { preset: 'custom', from, to, group, playbookId };
    }
    const preset = PRESETS.find((p) => String(p) === range) ?? 30;
    return { preset, ...presetRange(preset), group, playbookId };
}

function cleanDefinition(text: string | undefined): string | undefined {
    return text ? text.replace(/_/g, ' ') : undefined;
}

// ── Page ─────────────────────────────────────────────────────────────────────

export default function AgentReportsPage() {
    const [filters, setFilters] = useState<Filters>(initialFilters);
    const [data, setData] = useState<PerformanceReportPayload | null>(null);
    const [qa, setQa] = useState<QaAggregatesPayload | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<ApiError | null>(null);
    const [reloadKey, setReloadKey] = useState(0);
    const [playbookOptions, setPlaybookOptions] = useState<{ id: number; name: string }[]>([]);

    const query: ReportQuery = useMemo(
        () => ({ from: filters.from, to: filters.to, group: filters.group, playbook_id: filters.playbookId }),
        [filters.from, filters.to, filters.group, filters.playbookId],
    );

    useEffect(() => {
        setQueryParam('range', filters.preset === 30 ? null : String(filters.preset));
        setQueryParam('from', filters.preset === 'custom' ? filters.from : null);
        setQueryParam('to', filters.preset === 'custom' ? filters.to : null);
        setQueryParam('group', filters.group === 'day' ? null : filters.group);
        setQueryParam('playbook', filters.playbookId);
    }, [filters]);

    useEffect(() => {
        if (!isIsoDate(query.from) || !isIsoDate(query.to)) return;
        let alive = true;
        setLoading(true);
        setError(null);
        reportsApi
            .performance(query)
            .then((d) => {
                if (!alive) return;
                setData(d);
                setPlaybookOptions((prev) => {
                    // An unfiltered report lists every playbook with activity; a filtered one only adds to what we know.
                    const map = new Map(d.playbook_id === null ? [] : prev.map((o) => [o.id, o] as const));
                    for (const row of asArray<PerformanceRow>(d.playbooks)) {
                        if (typeof row.playbook_id === 'number') {
                            map.set(row.playbook_id, {
                                id: row.playbook_id,
                                name: row.playbook_name || row.playbook_slug || `Playbook ${row.playbook_id}`,
                            });
                        }
                    }
                    return [...map.values()].sort((a, b) => a.name.localeCompare(b.name));
                });
            })
            .catch((e) => alive && setError(toApiError(e)))
            .finally(() => alive && setLoading(false));
        // The QA sample averages are a bonus: without them the report still works.
        reportsApi
            .qaAggregates({ from: query.from, to: query.to, playbook_id: query.playbook_id })
            .then((d) => alive && setQa(d))
            .catch(() => alive && setQa(null));
        return () => {
            alive = false;
        };
    }, [query, reloadKey]);

    const forbidden = error?.status === 403;

    return (
        <AgentPage
            title="AI performance"
            headTitle="AI performance report"
            current={AGENT_SCREEN_PATHS.reports}
            width="max-w-5xl"
            description="How much of your email the agent handles, how good its replies are and what it costs."
            actions={
                !forbidden && (
                    <Button variant="outline" size="sm" asChild>
                        <a href={performanceCsvUrl(query)} download>
                            <DownloadIcon className="h-3.5 w-3.5" /> Download CSV
                        </a>
                    </Button>
                )
            }
        >
            {forbidden ? (
                <EmptyState icon={<ShieldIcon className="h-5 w-5 text-muted-foreground" />} title="Only admins can see this report">
                    Ask the workspace owner to give you admin access if you need it.
                </EmptyState>
            ) : (
                <>
                    <FilterRow filters={filters} onChange={setFilters} playbooks={playbookOptions} loading={loading && data !== null} />
                    {error && (
                        <div className="flex items-center gap-3">
                            <ErrorLine error={error} />
                            <Button size="sm" variant="outline" onClick={() => setReloadKey((k) => k + 1)}>
                                Try again
                            </Button>
                        </div>
                    )}
                    {data === null ? (
                        loading && (
                            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                                <Spinner /> Loading the report…
                            </p>
                        )
                    ) : (
                        <div className={cn('space-y-6 transition-opacity', loading && 'opacity-60')} aria-busy={loading}>
                            <Report data={data} qa={qa} onPickPlaybook={(id) => setFilters((f) => ({ ...f, playbookId: id }))} />
                        </div>
                    )}
                </>
            )}
        </AgentPage>
    );
}

// ── Filter row ───────────────────────────────────────────────────────────────

const selectClass = 'h-9 rounded-md border border-input bg-background px-2 text-sm';

function FilterRow({
    filters,
    onChange,
    playbooks,
    loading,
}: {
    filters: Filters;
    onChange: React.Dispatch<React.SetStateAction<Filters>>;
    playbooks: { id: number; name: string }[];
    loading: boolean;
}) {
    const options =
        filters.playbookId !== null && !playbooks.some((p) => p.id === filters.playbookId)
            ? [...playbooks, { id: filters.playbookId, name: `Playbook ${filters.playbookId}` }]
            : playbooks;

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap items-end gap-3">
                <div className="inline-flex rounded-lg border border-border p-0.5" role="group" aria-label="Date range">
                    {PRESETS.map((p) => (
                        <button
                            key={p}
                            type="button"
                            aria-pressed={filters.preset === p}
                            className={cn(
                                'rounded-md px-3 py-1.5 text-sm',
                                filters.preset === p
                                    ? 'bg-muted font-medium text-foreground'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                            onClick={() => onChange((f) => ({ ...f, preset: p, ...presetRange(p) }))}
                        >
                            Last {p} days
                        </button>
                    ))}
                    <button
                        type="button"
                        aria-pressed={filters.preset === 'custom'}
                        className={cn(
                            'rounded-md px-3 py-1.5 text-sm',
                            filters.preset === 'custom'
                                ? 'bg-muted font-medium text-foreground'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                        onClick={() => onChange((f) => ({ ...f, preset: 'custom' }))}
                    >
                        Custom
                    </button>
                </div>
                {filters.preset === 'custom' && (
                    <>
                        <label className="space-y-1 text-xs text-muted-foreground">
                            <span className="block">From</span>
                            <Input
                                type="date"
                                className="h-9 w-40"
                                value={filters.from}
                                max={filters.to}
                                onChange={(e) => isIsoDate(e.target.value) && onChange((f) => ({ ...f, from: e.target.value }))}
                            />
                        </label>
                        <label className="space-y-1 text-xs text-muted-foreground">
                            <span className="block">To</span>
                            <Input
                                type="date"
                                className="h-9 w-40"
                                value={filters.to}
                                min={filters.from}
                                onChange={(e) => isIsoDate(e.target.value) && onChange((f) => ({ ...f, to: e.target.value }))}
                            />
                        </label>
                    </>
                )}
                <select
                    aria-label="Group by"
                    className={selectClass}
                    value={filters.group}
                    onChange={(e) => onChange((f) => ({ ...f, group: e.target.value === 'week' ? 'week' : 'day' }))}
                >
                    <option value="day">By day</option>
                    <option value="week">By week</option>
                </select>
                <select
                    aria-label="Playbook"
                    className={cn(selectClass, 'max-w-60')}
                    value={filters.playbookId ?? ''}
                    onChange={(e) => onChange((f) => ({ ...f, playbookId: e.target.value ? Number(e.target.value) : null }))}
                >
                    <option value="">All playbooks</option>
                    {options.map((p) => (
                        <option key={p.id} value={p.id}>
                            {p.name}
                        </option>
                    ))}
                </select>
                {loading && <Spinner className="mb-2.5 text-muted-foreground" />}
            </div>
            <p className="text-xs text-muted-foreground">
                {dayLabel(filters.from, true)} to {dayLabel(filters.to, true)}. Imported history is left out everywhere.
            </p>
        </div>
    );
}

// ── Report body ──────────────────────────────────────────────────────────────

function hasActivity(t: PerformanceRow): boolean {
    const f = t.feedback ?? { sent_as_is: 0, sent_edited: 0, gmail_reply: 0, rejected: 0 };
    const triaged = Object.values(asRecord<QaAuthorRow>(t.qa?.by_author_type)).reduce((sum, q) => sum + num(q?.triaged), 0);
    return (
        num(t.drafts_produced) +
            num(t.handovers) +
            num(f.sent_as_is) +
            num(f.sent_edited) +
            num(f.gmail_reply) +
            num(f.rejected) +
            num(t.auto_sent) +
            num(t.auto_send_decisions) +
            num(t.actions?.proposed) +
            num(t.cost?.total_micro_usd) +
            triaged >
        0
    );
}

function Report({
    data,
    qa,
    onPickPlaybook,
}: {
    data: PerformanceReportPayload;
    qa: QaAggregatesPayload | null;
    onPickPlaybook: (id: number) => void;
}) {
    const t = data.totals;
    const definitions = asRecord<string>(data.definitions);
    const filtered = data.playbook_id !== null;

    if (!t || !hasActivity(t)) {
        return (
            <EmptyState icon={<BarChart3Icon className="h-5 w-5 text-muted-foreground" />} title="Nothing to report for these dates">
                The agent did not draft, hand over or get reviewed in this range{filtered ? ' for this playbook' : ''}. Numbers appear once
                the agent is in Draft mode and email arrives.{' '}
                <Link href="/agent/settings" className="text-primary hover:underline">
                    Agent settings
                </Link>
            </EmptyState>
        );
    }

    return (
        <>
            <Headline t={t} definitions={definitions} filtered={filtered} />
            <Trend periods={asArray<PerformanceRow>(data.periods)} group={data.group === 'week' ? 'week' : 'day'} />
            <div className="grid gap-4 lg:grid-cols-2">
                <Handovers t={t} />
                <DraftUse t={t} />
            </div>
            <Quality t={t} qa={qa} />
            <Cost t={t} filtered={filtered} />
            <AutomationAndActions t={t} definitions={definitions} />
            {!filtered && <PlaybookTable rows={asArray<PerformanceRow>(data.playbooks)} onPick={onPickPlaybook} />}
            <Section>
                <Disclosure title="How these are counted">
                    <dl className="space-y-2 text-xs">
                        {Object.entries(definitions).map(([key, text]) => (
                            <div key={key}>
                                <dt className="font-medium">{labelFor(DEFINITION_LABELS, key)}</dt>
                                <dd className="text-muted-foreground">{cleanDefinition(text)}</dd>
                            </div>
                        ))}
                        <div>
                            <dt className="font-medium">Imported history</dt>
                            <dd className="text-muted-foreground">
                                Left out everywhere: only email that arrived while the agent was set up counts.
                            </dd>
                        </div>
                    </dl>
                </Disclosure>
            </Section>
        </>
    );
}

// ── Stat tiles ───────────────────────────────────────────────────────────────

function Tile({ label, value, hint, help }: { label: string; value: React.ReactNode; hint?: React.ReactNode; help?: string }) {
    return (
        <div className="rounded-xl border border-border bg-card p-4" title={help}>
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="mt-1 text-2xl font-semibold">{value}</p>
            {hint && <p className="mt-0.5 text-xs text-muted-foreground">{hint}</p>}
        </div>
    );
}

function Headline({ t, definitions, filtered }: { t: PerformanceRow; definitions: Record<string, string>; filtered: boolean }) {
    const f = t.feedback;
    const ttfd = t.time_to_first_draft ?? { count: 0, avg_seconds: null, median_seconds: null };
    const resolvedCost = numOrNull(t.cost_per_resolved_usd);
    return (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Tile
                label="Sent without changes"
                value={pct(t.automation_rate)}
                hint={`${count(t.conversations_sent_as_is)} of ${count(t.conversations_with_draft)} conversations with a draft`}
                help={cleanDefinition(definitions.automation_rate)}
            />
            <Tile
                label="Edited before sending"
                value={pct(t.edit_rate)}
                hint={
                    t.avg_percent_changed !== null
                        ? `About ${t.avg_percent_changed}% of the text changed, on average`
                        : `${count(f?.sent_edited)} edited drafts`
                }
                help={cleanDefinition(definitions.edit_rate)}
            />
            <Tile
                label="Handed to a person"
                value={count(t.handovers)}
                hint={`${count(t.drafts_produced)} drafts written`}
                help={cleanDefinition(definitions.handovers)}
            />
            <Tile
                label="Answered in Gmail instead"
                value={count(f?.gmail_reply)}
                hint={t.gmail_reply_rate !== null ? `${pct(t.gmail_reply_rate)} of drafts were ignored this way` : 'No drafts used yet'}
                help={cleanDefinition(definitions.feedback)}
            />
            <Tile
                label="Time to first draft"
                value={humanDuration(ttfd.median_seconds)}
                hint={
                    ttfd.count > 0
                        ? `Typical (median). Average ${humanDuration(ttfd.avg_seconds)} over ${count(ttfd.count)} emails`
                        : 'No drafts yet'
                }
                help={cleanDefinition(definitions.time_to_first_draft)}
            />
            <Tile
                label="AI cost"
                value={usd(num(t.cost?.total_usd))}
                hint="Everything the AI did in this range"
                help={cleanDefinition(definitions.cost)}
            />
            <Tile
                label="Cost per resolved conversation"
                value={filtered ? '—' : usd(resolvedCost)}
                hint={
                    filtered
                        ? 'Not available for a single playbook'
                        : resolvedCost === null
                          ? 'No conversations closed in this range'
                          : `${usd(numOrNull(t.live_cost_per_resolved_usd))} for drafting real email only · ${count(t.resolved_conversations)} closed`
                }
                help={cleanDefinition(definitions.cost_per_resolved_usd)}
            />
            {num(t.auto_sent) > 0 ? (
                <Tile
                    label="Sent automatically"
                    value={count(t.auto_sent)}
                    hint={t.auto_sent_rate !== null ? `${pct(t.auto_sent_rate)} of conversations received` : undefined}
                    help={cleanDefinition(definitions.auto_sent)}
                />
            ) : (
                <Tile
                    label="Conversations received"
                    value={filtered ? '—' : count(t.conversations_received)}
                    hint={filtered ? 'Not available for a single playbook' : 'New, not spam'}
                    help={cleanDefinition(definitions.conversations_received)}
                />
            )}
        </div>
    );
}

/** Auto-send decisions and Shopify actions (hidden until there are any). */
function AutomationAndActions({ t, definitions }: { t: PerformanceRow; definitions: Record<string, string> }) {
    const a = t.actions;
    const decisions = num(t.auto_send_decisions);
    if (decisions === 0 && num(a?.proposed) === 0) return null;
    return (
        <Section
            title="Automatic sending and Shopify actions"
            description="What the agent chose to send by itself, and the Shopify actions it proposed in this range."
        >
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Tile
                    label="Chosen for automatic sending"
                    value={count(decisions)}
                    hint={`${count(t.auto_sent)} conversations actually got an automatic reply`}
                    help={cleanDefinition(definitions.auto_send_decisions)}
                />
                <Tile
                    label="Actions proposed"
                    value={count(num(a?.proposed))}
                    hint={`${count(num(a?.approved))} approved, ${count(num(a?.unattended))} of them ran on their own`}
                    help={cleanDefinition(definitions.actions)}
                />
                <Tile
                    label="Actions done in Shopify"
                    value={count(num(a?.succeeded))}
                    hint={`${count(num(a?.failed))} failed, ${count(num(a?.rejected))} rejected by a person`}
                />
                <Tile
                    label="Refunded"
                    value={num(a?.refunded_amount).toFixed(2)}
                    hint="Shop currency, refunds and cancellations that refunded"
                />
            </div>
        </Section>
    );
}

// ── Trend charts ─────────────────────────────────────────────────────────────

interface ChartRow {
    period: string;
    label: string;
    drafted: number;
    handed: number;
    rate: number | null;
    sentAsIs: number;
    withDraft: number;
}

const volumeConfig = {
    drafted: { label: 'Drafted', theme: SERIES_COLORS.blue },
    handed: { label: 'Handed to a person', theme: SERIES_COLORS.orange },
} satisfies ChartConfig;

const rateConfig = {
    rate: { label: 'Sent without changes', theme: SERIES_COLORS.blue },
} satisfies ChartConfig;

function PeriodTooltip({
    active,
    payload,
    group,
    kind,
}: {
    active?: boolean;
    payload?: { payload?: ChartRow }[];
    group: ReportGroup;
    kind: 'volume' | 'rate';
}) {
    const row = payload?.[0]?.payload;
    if (!active || !row) return null;
    const key = (color: string) => <span className="h-0.5 w-3 shrink-0 rounded-full" style={{ background: color }} />;
    return (
        <div className="grid min-w-40 gap-1.5 rounded-lg border border-border/50 bg-background px-2.5 py-1.5 text-xs shadow-xl">
            <p className="font-medium">{periodLabel(row.period, group)}</p>
            {kind === 'volume' ? (
                <>
                    <p className="flex items-center gap-2">
                        {key('var(--color-drafted)')}
                        <span className="font-semibold tabular-nums">{row.drafted}</span>
                        <span className="text-muted-foreground">drafted</span>
                    </p>
                    <p className="flex items-center gap-2">
                        {key('var(--color-handed)')}
                        <span className="font-semibold tabular-nums">{row.handed}</span>
                        <span className="text-muted-foreground">handed to a person</span>
                    </p>
                </>
            ) : row.rate === null ? (
                <p className="text-muted-foreground">No drafts in this {group}</p>
            ) : (
                <p className="flex items-center gap-2">
                    {key('var(--color-rate)')}
                    <span className="font-semibold tabular-nums">{pct(row.rate)}</span>
                    <span className="text-muted-foreground">
                        sent without changes ({row.sentAsIs} of {row.withDraft})
                    </span>
                </p>
            )}
        </div>
    );
}

/** Swatch classes are literal so Tailwind sees them; values match SERIES_COLORS. */
function LegendSwatch({ swatch, label }: { swatch: string; label: string }) {
    return (
        <span className="flex items-center gap-1.5">
            <span className={cn('h-2.5 w-2.5 rounded-[2px]', swatch)} /> {label}
        </span>
    );
}

function Trend({ periods, group }: { periods: PerformanceRow[]; group: ReportGroup }) {
    const rows: ChartRow[] = periods.map((p) => ({
        period: p.period_start ?? '',
        label: dayLabel(p.period_start),
        drafted: num(p.drafts_produced),
        handed: num(p.handovers),
        rate: numOrNull(p.automation_rate),
        sentAsIs: num(p.conversations_sent_as_is),
        withDraft: num(p.conversations_with_draft),
    }));
    const unit = group === 'week' ? 'week' : 'day';
    const showDots = rows.length <= 31;

    return (
        <Section
            title="Over time"
            description={`Each point is one ${unit}${group === 'week' ? ' (weeks start on Monday)' : ''}. Hover for the numbers.`}
        >
            <div className="grid gap-6 lg:grid-cols-2">
                <figure className="space-y-2">
                    <figcaption className="space-y-1">
                        <p className="text-sm font-medium">Drafts and handovers</p>
                        <div className="flex flex-wrap gap-4 text-xs text-muted-foreground">
                            <LegendSwatch swatch="bg-[#2a78d6] dark:bg-[#3987e5]" label="Drafted" />
                            <LegendSwatch swatch="bg-[#eb6834] dark:bg-[#d95926]" label="Handed to a person" />
                        </div>
                    </figcaption>
                    <ChartContainer config={volumeConfig} className="aspect-auto h-56 w-full">
                        <BarChart data={rows} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                            <CartesianGrid vertical={false} />
                            <XAxis dataKey="label" tickLine={false} axisLine={false} tickMargin={8} minTickGap={16} />
                            <YAxis allowDecimals={false} tickLine={false} axisLine={false} width={32} />
                            <ChartTooltip
                                cursor={{ fill: 'var(--muted)', opacity: 0.6 }}
                                content={<PeriodTooltip group={group} kind="volume" />}
                            />
                            <Bar
                                dataKey="drafted"
                                stackId="runs"
                                fill="var(--color-drafted)"
                                stroke="var(--card)"
                                strokeWidth={1}
                                maxBarSize={24}
                            />
                            <Bar
                                dataKey="handed"
                                stackId="runs"
                                fill="var(--color-handed)"
                                stroke="var(--card)"
                                strokeWidth={1}
                                maxBarSize={24}
                                radius={[4, 4, 0, 0]}
                            />
                        </BarChart>
                    </ChartContainer>
                </figure>
                <figure className="space-y-2">
                    <figcaption className="space-y-1">
                        <p className="text-sm font-medium">Share of drafts sent without changes</p>
                        <p className="text-xs text-muted-foreground">Gaps are {unit}s with no drafts.</p>
                    </figcaption>
                    <ChartContainer config={rateConfig} className="aspect-auto h-56 w-full">
                        <LineChart data={rows} margin={{ top: 8, right: 12, left: 0, bottom: 0 }}>
                            <CartesianGrid vertical={false} />
                            <XAxis dataKey="label" tickLine={false} axisLine={false} tickMargin={8} minTickGap={16} />
                            <YAxis
                                domain={[0, 1]}
                                ticks={[0, 0.25, 0.5, 0.75, 1]}
                                tickFormatter={(v: number) => pct(v)}
                                tickLine={false}
                                axisLine={false}
                                width={40}
                            />
                            <ChartTooltip cursor={{ stroke: 'var(--border)' }} content={<PeriodTooltip group={group} kind="rate" />} />
                            <Line
                                dataKey="rate"
                                type="linear"
                                stroke="var(--color-rate)"
                                strokeWidth={2}
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                connectNulls={false}
                                isAnimationActive={false}
                                dot={showDots ? { r: 4, fill: 'var(--color-rate)', stroke: 'var(--card)', strokeWidth: 2 } : false}
                                activeDot={{ r: 5, fill: 'var(--color-rate)', stroke: 'var(--card)', strokeWidth: 2 }}
                            />
                        </LineChart>
                    </ChartContainer>
                </figure>
            </div>
            <Disclosure title="Show the numbers as a table">
                <div className="max-h-80 overflow-auto rounded-md border border-border">
                    <table className="w-full text-xs">
                        <thead className="sticky top-0 bg-muted text-left text-muted-foreground">
                            <tr>
                                <th className="px-2 py-1.5 font-medium">{group === 'week' ? 'Week of' : 'Day'}</th>
                                <th className="px-2 py-1.5 text-right font-medium">Received</th>
                                <th className="px-2 py-1.5 text-right font-medium">Drafted</th>
                                <th className="px-2 py-1.5 text-right font-medium">Handed over</th>
                                <th className="px-2 py-1.5 text-right font-medium">Sent without changes</th>
                                <th className="px-2 py-1.5 text-right font-medium">Edited</th>
                                <th className="px-2 py-1.5 text-right font-medium">Gmail instead</th>
                                <th className="px-2 py-1.5 text-right font-medium">AI cost</th>
                            </tr>
                        </thead>
                        <tbody className="tabular-nums">
                            {periods.map((p) => (
                                <tr key={p.period_start} className="border-t border-border">
                                    <td className="px-2 py-1">{dayLabel(p.period_start, true)}</td>
                                    <td className="px-2 py-1 text-right">
                                        {p.conversations_received === null ? '—' : count(p.conversations_received)}
                                    </td>
                                    <td className="px-2 py-1 text-right">{count(p.drafts_produced)}</td>
                                    <td className="px-2 py-1 text-right">{count(p.handovers)}</td>
                                    <td className="px-2 py-1 text-right">{pct(p.automation_rate)}</td>
                                    <td className="px-2 py-1 text-right">{pct(p.edit_rate)}</td>
                                    <td className="px-2 py-1 text-right">{count(p.feedback?.gmail_reply)}</td>
                                    <td className="px-2 py-1 text-right">{usd(num(p.cost?.total_usd))}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Disclosure>
        </Section>
    );
}

// ── Handovers and draft use ──────────────────────────────────────────────────

function Handovers({ t }: { t: PerformanceRow }) {
    const reasons = asArray<{ reason: string; label: string; count: number }>(t.handover_reasons);
    const total = num(t.handovers);
    return (
        <Section title="Why it handed over" description="Emails the agent passed to a person instead of drafting, by reason.">
            {reasons.length === 0 ? (
                <p className="text-sm text-muted-foreground">No handovers in this range.</p>
            ) : (
                <ul className="space-y-1.5">
                    {reasons.slice(0, 8).map((r) => (
                        <li key={r.reason} className="flex items-start justify-between gap-3 text-sm">
                            <span className="first-letter:uppercase">{r.label || r.reason}</span>
                            <span className="shrink-0 tabular-nums text-muted-foreground">
                                {count(r.count)}
                                {total > 0 && <span className="ml-1 text-xs">({pct(num(r.count) / total)})</span>}
                            </span>
                        </li>
                    ))}
                    {reasons.length > 8 && (
                        <li className="text-xs text-muted-foreground">
                            and {reasons.length - 8} more reasons ({count(reasons.slice(8).reduce((s, r) => s + num(r.count), 0))}{' '}
                            handovers)
                        </li>
                    )}
                </ul>
            )}
        </Section>
    );
}

function DraftUse({ t }: { t: PerformanceRow }) {
    const f = t.feedback ?? { sent_as_is: 0, sent_edited: 0, gmail_reply: 0, rejected: 0 };
    return (
        <Section title="What people did with drafts" description="Every time someone used, changed or ignored a draft.">
            <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1.5 text-sm">
                <dt className="text-muted-foreground">Sent as written</dt>
                <dd className="text-right tabular-nums">{count(f.sent_as_is)}</dd>
                <dt className="text-muted-foreground">Edited, then sent</dt>
                <dd className="text-right tabular-nums">
                    {count(f.sent_edited)}
                    {t.avg_percent_changed !== null && (
                        <span className="ml-1 text-xs text-muted-foreground">(~{t.avg_percent_changed}% changed)</span>
                    )}
                </dd>
                <dt className="text-muted-foreground">Answered in Gmail instead</dt>
                <dd className="text-right tabular-nums">
                    {count(f.gmail_reply)}
                    {t.gmail_reply_rate !== null && <span className="ml-1 text-xs text-muted-foreground">({pct(t.gmail_reply_rate)})</span>}
                </dd>
                <dt className="text-muted-foreground">Rejected</dt>
                <dd className="text-right tabular-nums">{count(f.rejected)}</dd>
            </dl>
        </Section>
    );
}

// ── Quality ──────────────────────────────────────────────────────────────────

function SampleNote({ n }: { n: number }) {
    if (n === 0) return null;
    return (
        <span className="block text-[11px] text-muted-foreground">
            {n} {n === 1 ? 'reply' : 'replies'}
            {n < SMALL_SAMPLE && <span className="font-medium text-foreground"> · small sample</span>}
        </span>
    );
}

function Quality({ t, qa }: { t: PerformanceRow; qa: QaAggregatesPayload | null }) {
    const byType = asRecord<QaAuthorRow>(t.qa?.by_author_type);
    const sampleRows = asArray<QaAggregateAuthorRow>(qa?.by_author_type);
    const sampleFor = (type: string) => sampleRows.find((r) => r.author_type === type) ?? null;
    const showSample = qa !== null && sampleRows.some((r) => num(r.sampled) > 0);
    const anyTriaged = AUTHOR_ORDER.some((type) => num(byType[type]?.triaged) > 0);
    const anySmall = AUTHOR_ORDER.some((type) => {
        const n = num(byType[type]?.triaged);
        return n > 0 && n < SMALL_SAMPLE;
    });

    const ai = numOrNull(byType.ai?.triage_avg);
    const human = numOrNull(byType.human?.triage_avg);

    return (
        <Section
            title="Reply quality: AI and your team"
            description="Every closed reply gets a quick check; a random sample also gets a full review. We compare those, not the flagged ones, so the comparison is fair."
        >
            {!anyTriaged ? (
                <p className="text-sm text-muted-foreground">No replies were checked in this range.</p>
            ) : (
                <>
                    {ai !== null && human !== null && (
                        <p className="text-sm">
                            On the quick check, replies the AI wrote by itself average <span className="font-semibold">{score5(ai)}</span>;
                            replies written by your team average <span className="font-semibold">{score5(human)}</span>.
                        </p>
                    )}
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-left text-xs text-muted-foreground">
                                <tr>
                                    <th className="py-1.5 pr-3 font-medium">Reply</th>
                                    <th className="py-1.5 pr-3 font-medium">Quick check (every closed reply)</th>
                                    {showSample && <th className="py-1.5 font-medium">Full review (random sample)</th>}
                                </tr>
                            </thead>
                            <tbody>
                                {AUTHOR_ORDER.map((type) => {
                                    const row = byType[type];
                                    const sample = sampleFor(type);
                                    return (
                                        <tr key={type} className="border-t border-border align-top">
                                            <td className="py-2 pr-3" title={AUTHOR_LABELS[type].help}>
                                                {AUTHOR_LABELS[type].label}
                                            </td>
                                            <td className="py-2 pr-3 tabular-nums">
                                                {score5(numOrNull(row?.triage_avg))}
                                                <SampleNote n={num(row?.triaged)} />
                                            </td>
                                            {showSample && (
                                                <td className="py-2 tabular-nums">
                                                    {score5(numOrNull(sample?.sample_avg))}
                                                    <SampleNote n={num(sample?.sampled)} />
                                                </td>
                                            )}
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Scores are out of 5. Replies marked as dismissed or wrong by a person are left out.
                        {anySmall || (showSample && sampleRows.some((r) => num(r.sampled) > 0 && num(r.sampled) < SMALL_SAMPLE))
                            ? ` With fewer than ${SMALL_SAMPLE} replies an average can swing a lot: treat it as a rough guide.`
                            : ''}
                    </p>
                </>
            )}
            <Link href={AGENT_SCREEN_PATHS.qa} className="inline-flex items-center gap-1 text-xs text-primary hover:underline">
                See individual quality checks →
            </Link>
        </Section>
    );
}

// ── Cost ─────────────────────────────────────────────────────────────────────

function Cost({ t, filtered }: { t: PerformanceRow; filtered: boolean }) {
    const byRole = Object.entries(asRecord<number>(t.cost?.by_role))
        .map(([k, v]) => [k, num(v)] as const)
        .filter(([, v]) => v > 0)
        .sort((a, b) => b[1] - a[1]);
    const byPurpose = Object.entries(asRecord<number>(t.cost?.by_purpose))
        .map(([k, v]) => [k, num(v)] as const)
        .filter(([, v]) => v > 0)
        .sort((a, b) => b[1] - a[1]);
    const total = num(t.cost?.total_usd);
    const max = Math.max(0, ...byRole.map(([, v]) => v));

    return (
        <Section
            title="Where the AI money goes"
            description={
                filtered
                    ? 'Costs of this playbook only. Workspace-wide work (manager chat, history learning, simulations) is left out.'
                    : 'Every AI call in this range, including practice, tests and quality checks.'
            }
        >
            {total === 0 ? (
                <p className="text-sm text-muted-foreground">No AI cost in this range.</p>
            ) : (
                <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
                    <div className="space-y-2">
                        <p className="text-xs font-medium text-muted-foreground">By job</p>
                        <ul className="space-y-2" aria-label="Cost by job">
                            {byRole.map(([role, value]) => {
                                const label = labelFor(ROLE_LABELS, role);
                                return (
                                    <li
                                        key={role}
                                        className="grid grid-cols-[minmax(0,11rem)_1fr_auto] items-center gap-3 text-sm"
                                        title={`${label}: ${usd(value)} (${pct(value / total)} of the total)`}
                                    >
                                        <span className="truncate">{label}</span>
                                        <span className="h-2 overflow-hidden rounded-r-[4px]">
                                            <span
                                                className="block h-full rounded-r-[4px] bg-[#2a78d6] dark:bg-[#3987e5]"
                                                style={{ width: `${max > 0 ? Math.max(1, (value / max) * 100) : 0}%` }}
                                            />
                                        </span>
                                        <span className="text-right tabular-nums">{usd(value)}</span>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                    <div className="space-y-2">
                        <p className="text-xs font-medium text-muted-foreground">By what it was for</p>
                        <ul className="space-y-1 text-sm">
                            {byPurpose.map(([purpose, value]) => (
                                <li key={purpose} className="flex justify-between gap-3">
                                    <span className="text-muted-foreground">{labelFor(PURPOSE_LABELS, purpose)}</span>
                                    <span className="tabular-nums">{usd(value)}</span>
                                </li>
                            ))}
                            <li className="flex justify-between gap-3 border-t border-border pt-1 font-medium">
                                <span>Total</span>
                                <span className="tabular-nums">{usd(total)}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            )}
        </Section>
    );
}

// ── Per playbook ─────────────────────────────────────────────────────────────

function PlaybookTable({ rows, onPick }: { rows: PerformanceRow[]; onPick: (id: number) => void }) {
    if (rows.length === 0) return null;
    return (
        <Section title="By playbook" description="Click a playbook to see the whole report for it alone.">
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="text-left text-xs text-muted-foreground">
                        <tr>
                            <th className="py-1.5 pr-3 font-medium">Playbook</th>
                            <th className="py-1.5 pr-3 text-right font-medium">Drafted</th>
                            <th className="py-1.5 pr-3 text-right font-medium">Handed over</th>
                            <th className="py-1.5 pr-3 text-right font-medium">Sent without changes</th>
                            <th className="py-1.5 pr-3 text-right font-medium">Edited</th>
                            <th className="py-1.5 pr-3 text-right font-medium">AI quick check</th>
                            <th className="py-1.5 pr-3 text-right font-medium">First draft (typical)</th>
                            <th className="py-1.5 text-right font-medium">AI cost</th>
                        </tr>
                    </thead>
                    <tbody className="tabular-nums">
                        {rows.map((row) => {
                            const id = typeof row.playbook_id === 'number' ? row.playbook_id : null;
                            const name = id === null ? 'No playbook' : row.playbook_name || row.playbook_slug || `Playbook ${id}`;
                            const aiQa = asRecord<QaAuthorRow>(row.qa?.by_author_type).ai;
                            return (
                                <tr key={id ?? 'none'} className="border-t border-border">
                                    <td className="py-2 pr-3">
                                        {id === null ? (
                                            <span
                                                className="text-muted-foreground"
                                                title="Emails no playbook matched: always handed to a person"
                                            >
                                                {name}
                                            </span>
                                        ) : (
                                            <span className="inline-flex items-center gap-1.5">
                                                <button
                                                    type="button"
                                                    className="text-left hover:text-primary hover:underline"
                                                    onClick={() => onPick(id)}
                                                    title="Show the report for this playbook only"
                                                >
                                                    {name}
                                                </button>
                                                <Link
                                                    href={`/agent/playbooks/${id}`}
                                                    className="text-muted-foreground hover:text-primary"
                                                    aria-label={`Open the ${name} playbook`}
                                                >
                                                    <ExternalLinkIcon className="h-3 w-3" />
                                                </Link>
                                            </span>
                                        )}
                                    </td>
                                    <td className="py-2 pr-3 text-right">{count(row.drafts_produced)}</td>
                                    <td className="py-2 pr-3 text-right">{count(row.handovers)}</td>
                                    <td className="py-2 pr-3 text-right">{pct(row.automation_rate)}</td>
                                    <td className="py-2 pr-3 text-right">{pct(row.edit_rate)}</td>
                                    <td
                                        className="py-2 pr-3 text-right"
                                        title={aiQa?.triaged ? `${aiQa.triaged} replies checked` : undefined}
                                    >
                                        {score5(numOrNull(aiQa?.triage_avg))}
                                    </td>
                                    <td className="py-2 pr-3 text-right">{humanDuration(row.time_to_first_draft?.median_seconds)}</td>
                                    <td className="py-2 text-right">{usd(num(row.cost?.total_usd))}</td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
            <Notice>
                Workspace-wide costs (manager chat, history learning, simulation judging) are not split by playbook, so the playbook costs
                add up to less than the total.
            </Notice>
        </Section>
    );
}
