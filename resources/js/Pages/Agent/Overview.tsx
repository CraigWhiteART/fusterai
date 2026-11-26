import React, { useEffect } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { AlertTriangleIcon, OctagonXIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { AGENT_SCREEN_PATHS, LEGACY_VIEW_SCREENS, useOpenProposalCount } from '@/lib/agentLoop';
import { usePendingActionCount } from '@/lib/agentActions';
import { AgentPage, Notice, Section } from './AgentParts';
import type { AgentMode } from '@/types/agent';

interface Props {
    days: number;
    since: string;
    settings: { mode: AgentMode; kill_switch: boolean };
    connections: { shopify: boolean; tracking: boolean };
    counts: {
        playbooks_active: number;
        playbooks_total: number;
        knowledge_active: number;
        knowledge_total: number;
        knowledge_needs_scope: number;
    };
    runs: {
        total: number;
        live: number;
        shadow: number;
        draft: number;
        handover: number;
        failed: number;
        top_handover_reasons: { reason: string; label: string; count: number }[];
        daily: { date: string; draft: number; handover: number }[];
    };
    feedback: {
        sent_as_is: number;
        sent_edited: number;
        gmail_reply: number;
        rejected: number;
        edit_rate: number | null;
        avg_percent_changed: number | null;
    };
    cost: {
        total_usd: number;
        per_run_usd: number | null;
        by_purpose: { purpose: string; runs: number; usd: number }[];
    };
}

const MODE_SUMMARY: Record<AgentMode, { label: string; text: string }> = {
    off: { label: 'Off', text: 'The agent is not reading email.' },
    shadow: { label: 'Shadow', text: 'The agent practises on every email; nobody sees its drafts.' },
    draft: { label: 'Drafting', text: 'The agent drafts replies for a person to review and send.' },
    live: { label: 'Live', text: 'Playbooks set to Auto may send replies by themselves; everything else is drafted.' },
};

const PURPOSE_LABEL: Record<string, string> = {
    live: 'Drafts on real email',
    shadow: 'Shadow practice',
    preview: 'Previews',
    simulation: 'Simulations',
    test: 'Tests',
};

function usd(value: number | null): string {
    if (value === null) return '—';
    return value > 0 && value < 0.01 ? `$${value.toFixed(4)}` : `$${value.toFixed(2)}`;
}

function percent(value: number | null): string {
    return value === null ? '—' : `${Math.round(value * 100)}%`;
}

function Stat({
    label,
    value,
    hint,
    href,
    tone,
}: {
    label: string;
    value: React.ReactNode;
    hint?: React.ReactNode;
    href?: string;
    tone?: 'warning';
}) {
    const body = (
        <>
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className={cn('mt-1 text-2xl font-semibold tabular-nums', tone === 'warning' && 'text-warning')}>{value}</p>
            {hint && <p className="mt-0.5 text-xs text-muted-foreground">{hint}</p>}
        </>
    );
    const className = 'block rounded-xl border border-border bg-card p-4';
    return href ? (
        <Link href={href} className={cn(className, 'transition-colors hover:bg-muted/40')}>
            {body}
        </Link>
    ) : (
        <div className={className}>{body}</div>
    );
}

/** Seven stacked bars: drafts (solid) over handovers (light), with a hover title and the numbers below. */
function DailyRuns({ daily }: { daily: Props['runs']['daily'] }) {
    const max = Math.max(1, ...daily.map((d) => d.draft + d.handover));
    return (
        <figure className="space-y-2">
            <div className="flex items-center gap-4 text-xs text-muted-foreground">
                <span className="flex items-center gap-1.5">
                    <span className="h-2.5 w-2.5 rounded-sm bg-primary" /> Drafted
                </span>
                <span className="flex items-center gap-1.5">
                    <span className="h-2.5 w-2.5 rounded-sm bg-primary/30" /> Handed to a person
                </span>
            </div>
            <div className="grid grid-cols-7 gap-2" role="img" aria-label="Runs per day, drafts and handovers">
                {daily.map((day) => {
                    const total = day.draft + day.handover;
                    const date = new Date(`${day.date}T00:00:00`);
                    return (
                        <div key={day.date} className="group flex flex-col items-center gap-1">
                            <div
                                className="flex h-28 w-full max-w-10 flex-col justify-end gap-0.5 rounded-sm group-hover:bg-muted/40"
                                title={`${date.toLocaleDateString()}: ${day.draft} drafted, ${day.handover} handed over`}
                            >
                                {day.handover > 0 && (
                                    <div
                                        className={cn('w-full bg-primary/30', day.draft === 0 && 'rounded-t')}
                                        style={{ height: `${(day.handover / max) * 100}%` }}
                                    />
                                )}
                                {day.draft > 0 && (
                                    <div
                                        className={cn('w-full bg-primary', day.handover === 0 && 'rounded-t')}
                                        style={{ height: `${(day.draft / max) * 100}%` }}
                                    />
                                )}
                            </div>
                            <span className="text-[11px] tabular-nums text-foreground">{total}</span>
                            <span className="text-[10px] text-muted-foreground">
                                {date.toLocaleDateString(undefined, { weekday: 'short' })}
                            </span>
                        </div>
                    );
                })}
            </div>
        </figure>
    );
}

/**
 * `/agent?view=…` hosted the review screens before they had page routes;
 * old links are sent on to the real route (other query params kept).
 */
export default function AgentOverviewPage(props: Props) {
    const { url } = usePage();
    const query = new URLSearchParams(url.split('?')[1] ?? '');
    const view = query.get('view');
    const screen = view ? LEGACY_VIEW_SCREENS[view] : undefined;

    useEffect(() => {
        if (!screen) return;
        query.delete('view');
        const rest = query.toString();
        router.visit(`${AGENT_SCREEN_PATHS[screen]}${rest ? `?${rest}` : ''}`, { replace: true });
        // Only when the requested screen changes.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [screen]);

    if (screen) return null;
    return <AgentOverview {...props} />;
}

function ProposalsWaiting() {
    const count = useOpenProposalCount(true);
    if (!count) return null;
    return (
        <Link
            href={AGENT_SCREEN_PATHS.proposals}
            className="flex items-center justify-between gap-3 rounded-xl border border-primary/30 bg-primary/5 px-4 py-3 text-sm transition-colors hover:bg-primary/10"
        >
            <span>
                <span className="font-semibold tabular-nums">{count}</span> proposal{count === 1 ? '' : 's'} waiting for review
                <span className="block text-xs text-muted-foreground">
                    Instruction changes from feedback, history mining and quality checks. Nothing changes until you approve.
                </span>
            </span>
            <span className="shrink-0 text-xs font-medium text-primary">Review →</span>
        </Link>
    );
}

function ApprovalsWaiting() {
    const count = usePendingActionCount(true);
    if (!count) return null;
    return (
        <Link
            href={AGENT_SCREEN_PATHS.actions}
            className="flex items-center justify-between gap-3 rounded-xl border border-primary/30 bg-primary/5 px-4 py-3 text-sm transition-colors hover:bg-primary/10"
        >
            <span>
                <span className="font-semibold tabular-nums">{count}</span> Shopify action{count === 1 ? '' : 's'} waiting for approval
                <span className="block text-xs text-muted-foreground">
                    Refunds, cancellations and other order changes the agent suggested.
                </span>
            </span>
            <span className="shrink-0 text-xs font-medium text-primary">Review →</span>
        </Link>
    );
}

function AgentOverview({ days, settings, connections, counts, runs, feedback, cost }: Props) {
    const mode = MODE_SUMMARY[settings.mode] ?? MODE_SUMMARY.off;
    const decided = runs.draft + runs.handover;
    const sentFromDraft = feedback.sent_as_is + feedback.sent_edited;

    return (
        <AgentPage
            title="AI agent"
            headTitle="AI agent overview"
            current="/agent"
            width="max-w-5xl"
            description={`How the agent is set up and what it did in the last ${days} days.`}
        >
            <ApprovalsWaiting />
            <ProposalsWaiting />
            {settings.kill_switch && (
                <Notice tone="danger">
                    <span className="flex items-center gap-2">
                        <OctagonXIcon className="h-4 w-4 text-destructive" />
                        <span>
                            <span className="font-semibold">The kill switch is on.</span> The agent is stopped everywhere.{' '}
                            <Link href="/agent/settings" className="text-primary underline">
                                Settings
                            </Link>
                        </span>
                    </span>
                </Notice>
            )}

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Stat label="Mode" value={mode.label} hint={mode.text} href="/agent/settings" />
                <Stat
                    label="Playbooks in use"
                    value={counts.playbooks_active}
                    hint={`of ${counts.playbooks_total} playbook${counts.playbooks_total === 1 ? '' : 's'}`}
                    href="/agent/playbooks"
                />
                <Stat
                    label="Knowledge items"
                    value={counts.knowledge_active}
                    hint={`active, of ${counts.knowledge_total}`}
                    href="/agent/knowledge"
                />
                <Stat
                    label="Waiting for a scope"
                    value={counts.knowledge_needs_scope}
                    hint={counts.knowledge_needs_scope > 0 ? 'Ignored until scoped. Review →' : 'Nothing waiting'}
                    href="/agent/knowledge?status=needs_scope"
                    tone={counts.knowledge_needs_scope > 0 ? 'warning' : undefined}
                />
            </div>

            {(!connections.shopify || !connections.tracking) && (
                <Notice tone="warning">
                    <span className="flex items-start gap-2">
                        <AlertTriangleIcon className="mt-0.5 h-4 w-4 shrink-0 text-warning" />
                        <span>
                            {!connections.shopify
                                ? 'Shopify is not connected, so the agent cannot look up orders and hands order questions to a person.'
                                : 'Carrier tracking is not connected, so the agent only knows what Shopify says about shipments.'}{' '}
                            <Link href="/agent/connections" className="text-primary underline">
                                Connections
                            </Link>
                        </span>
                    </span>
                </Notice>
            )}

            <div className="grid gap-4 lg:grid-cols-[3fr_2fr]">
                <Section
                    title={`Runs, last ${days} days`}
                    description={
                        runs.total === 0
                            ? 'No runs yet. Runs appear once the agent is in Shadow or Draft mode and email arrives.'
                            : `${runs.total} run${runs.total === 1 ? '' : 's'}: ${runs.live} on real email, ${runs.shadow} in shadow${runs.failed ? `, ${runs.failed} failed` : ''}.`
                    }
                >
                    {runs.total > 0 && (
                        <>
                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <p className="text-xs text-muted-foreground">Drafted</p>
                                    <p className="text-xl font-semibold tabular-nums">
                                        {runs.draft}
                                        <span className="ml-1 text-xs font-normal text-muted-foreground">
                                            {decided ? percent(runs.draft / decided) : ''}
                                        </span>
                                    </p>
                                </div>
                                <div>
                                    <p className="text-xs text-muted-foreground">Handed to a person</p>
                                    <p className="text-xl font-semibold tabular-nums">
                                        {runs.handover}
                                        <span className="ml-1 text-xs font-normal text-muted-foreground">
                                            {decided ? percent(runs.handover / decided) : ''}
                                        </span>
                                    </p>
                                </div>
                            </div>
                            <DailyRuns daily={runs.daily} />
                        </>
                    )}
                </Section>

                <Section title="Why it handed over" description="Most common reasons, last 7 days.">
                    {runs.top_handover_reasons.length === 0 ? (
                        <p className="text-sm text-muted-foreground">No handovers.</p>
                    ) : (
                        <ul className="space-y-2">
                            {runs.top_handover_reasons.map((r) => (
                                <li key={r.reason} className="flex items-start justify-between gap-3 text-sm">
                                    <span className="first-letter:uppercase">{r.label}</span>
                                    <span className="shrink-0 tabular-nums text-muted-foreground">{r.count}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {runs.top_handover_reasons.some((r) => r.reason === 'no_instruction') && (
                        <p className="text-xs text-muted-foreground">
                            “No playbook covers this situation” usually means a{' '}
                            <Link href="/agent/playbooks/create" className="text-primary hover:underline">
                                new playbook
                            </Link>{' '}
                            would help.
                        </p>
                    )}
                </Section>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <Section title="How drafts were used" description="What people did with the agent's drafts, last 7 days.">
                    {sentFromDraft + feedback.gmail_reply + feedback.rejected === 0 ? (
                        <p className="text-sm text-muted-foreground">No drafts used yet.</p>
                    ) : (
                        <>
                            <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                <dt className="text-muted-foreground">Sent as written</dt>
                                <dd className="text-right tabular-nums">{feedback.sent_as_is}</dd>
                                <dt className="text-muted-foreground">Edited, then sent</dt>
                                <dd className="text-right tabular-nums">
                                    {feedback.sent_edited}
                                    {feedback.avg_percent_changed !== null && (
                                        <span className="ml-1 text-xs text-muted-foreground">
                                            (~{feedback.avg_percent_changed}% changed)
                                        </span>
                                    )}
                                </dd>
                                <dt className="text-muted-foreground">Answered in Gmail instead</dt>
                                <dd className="text-right tabular-nums">{feedback.gmail_reply}</dd>
                                {feedback.rejected > 0 && (
                                    <>
                                        <dt className="text-muted-foreground">Rejected</dt>
                                        <dd className="text-right tabular-nums">{feedback.rejected}</dd>
                                    </>
                                )}
                            </dl>
                            <p className="text-sm">
                                <span className="font-semibold">{percent(feedback.edit_rate)}</span>{' '}
                                <span className="text-muted-foreground">of drafts sent from here were edited first.</span>
                            </p>
                        </>
                    )}
                </Section>

                <Section title="Cost, last 7 days" description="AI spend for every agent run, including practice and tests.">
                    <p className="text-2xl font-semibold tabular-nums">{usd(cost.total_usd)}</p>
                    {cost.per_run_usd !== null && (
                        <p className="text-xs text-muted-foreground">{usd(cost.per_run_usd)} per run on average</p>
                    )}
                    {cost.by_purpose.length > 0 && (
                        <ul className="space-y-1 text-sm">
                            {cost.by_purpose.map((row) => (
                                <li key={row.purpose} className="flex justify-between gap-3">
                                    <span className="text-muted-foreground">
                                        {PURPOSE_LABEL[row.purpose] ?? row.purpose} ({row.runs})
                                    </span>
                                    <span className="tabular-nums">{usd(row.usd)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>
            </div>
        </AgentPage>
    );
}
