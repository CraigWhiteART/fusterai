import React, { useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import {
    AlertTriangleIcon,
    CheckCircle2Icon,
    CircleDotIcon,
    ExternalLinkIcon,
    GhostIcon,
    HourglassIcon,
    MinusCircleIcon,
    ShieldIcon,
    SendIcon,
} from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Disclosure, ErrorLine, Spinner } from '@/Components/agent/LoopParts';
import { capitalise, handoverLabel } from '@/Components/conversation/agentLabels';
import {
    AGENT_SCREEN_PATHS,
    agentScreenUrl,
    asArray,
    conversationUrl,
    pct,
    queryParam,
    setQueryParam,
    shortDate,
    toApiError,
    usd,
    type ApiError,
} from '@/lib/agentLoop';
import {
    CLOSE_MIN_AGREEMENT,
    PLAYBOOK_MODE_LABELS,
    READINESS_LABELS,
    READY_MIN_AGREEMENT,
    READY_MIN_AUTO_COMPARED,
    READY_MIN_COMPARED,
    count,
    num,
    numOrNull,
    readinessOf,
    reportsApi,
    score5,
    type Readiness,
} from '@/lib/agentReports';
import { cn } from '@/lib/utils';
import type { ShadowDisagreement, ShadowJudge, ShadowPlaybookRow, ShadowReportPayload } from '@/types/agentReports';
import { AgentPage, EmptyState, Section } from '../AgentParts';

const DAY_OPTIONS = [7, 30, 90] as const;

function initialDays(): number {
    const d = Number(queryParam('days'));
    return (DAY_OPTIONS as readonly number[]).includes(d) ? d : 30;
}

// ── Page ─────────────────────────────────────────────────────────────────────

export default function ShadowReportPage() {
    const [days, setDays] = useState<number>(initialDays);
    const [data, setData] = useState<ShadowReportPayload | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<ApiError | null>(null);
    const [reloadKey, setReloadKey] = useState(0);

    useEffect(() => {
        setQueryParam('days', days === 30 ? null : days);
        let alive = true;
        setLoading(true);
        setError(null);
        reportsApi
            .shadowReport(days, 10)
            .then((d) => alive && setData(d))
            .catch((e) => alive && setError(toApiError(e)))
            .finally(() => alive && setLoading(false));
        return () => {
            alive = false;
        };
    }, [days, reloadKey]);

    const forbidden = error?.status === 403;

    return (
        <AgentPage
            title="Shadow report"
            headTitle="Shadow report"
            current={AGENT_SCREEN_PATHS.shadow}
            width="max-w-5xl"
            description="While the agent practises in Shadow mode, a judge compares what it would have sent with what your team actually sent. Use this to decide when a playbook is ready to send by itself."
        >
            {forbidden ? (
                <EmptyState icon={<ShieldIcon className="h-5 w-5 text-muted-foreground" />} title="Only admins can see this report">
                    Ask the workspace owner to give you admin access if you need it.
                </EmptyState>
            ) : (
                <>
                    <div className="flex flex-wrap items-center gap-3">
                        <div className="inline-flex rounded-lg border border-border p-0.5" role="group" aria-label="Period">
                            {DAY_OPTIONS.map((d) => (
                                <button
                                    key={d}
                                    type="button"
                                    aria-pressed={days === d}
                                    className={cn(
                                        'rounded-md px-3 py-1.5 text-sm',
                                        days === d ? 'bg-muted font-medium text-foreground' : 'text-muted-foreground hover:text-foreground',
                                    )}
                                    onClick={() => setDays(d)}
                                >
                                    Last {d} days
                                </button>
                            ))}
                        </div>
                        {loading && data !== null && <Spinner className="text-muted-foreground" />}
                    </div>
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
                                <Spinner /> Loading the shadow report…
                            </p>
                        )
                    ) : (
                        <div className={cn('space-y-6 transition-opacity', loading && 'opacity-60')} aria-busy={loading}>
                            <ShadowBody data={data} />
                        </div>
                    )}
                    <GoLiveChecklist />
                </>
            )}
        </AgentPage>
    );
}

// ── Body ─────────────────────────────────────────────────────────────────────

function ShadowBody({ data }: { data: ShadowReportPayload }) {
    const t = data.totals;
    const playbooks = asArray<ShadowPlaybookRow>(data.playbooks);
    const worst = asArray<ShadowDisagreement>(data.worst_disagreements);

    if (num(t?.shadow_runs) === 0 && num(t?.compared) === 0) {
        return (
            <EmptyState
                icon={<GhostIcon className="h-5 w-5 text-muted-foreground" />}
                title={`No shadow practice in the last ${data.window_days ?? ''} days`}
                action={
                    <Button asChild size="sm">
                        <Link href="/agent/settings">Open Agent settings</Link>
                    </Button>
                }
            >
                Turn on Shadow mode in Agent settings and leave it on for at least a week of normal email. The agent reads every email and
                records what it would have done; nobody sees anything and nothing is sent.
            </EmptyState>
        );
    }

    const drafts = playbooks.reduce((s, p) => s + num(p.drafts), 0);
    const handovers = playbooks.reduce((s, p) => s + num(p.handovers), 0);
    // Server totals (would_auto_send_*), summed from the playbook rows for an older payload.
    const autoCompared =
        typeof t.would_auto_send_compared === 'number'
            ? t.would_auto_send_compared
            : playbooks.reduce((s, p) => s + num(p.would_auto_send_compared), 0);
    const autoAgreed =
        typeof t.would_auto_send_agreed === 'number'
            ? t.would_auto_send_agreed
            : playbooks.reduce((s, p) => s + num(p.would_auto_send_agreed), 0);
    const autoRate = autoCompared > 0 ? autoAgreed / autoCompared : null;
    const nameOf = (id: number | null) => {
        const row = playbooks.find((p) => p.playbook && p.playbook.id === id);
        return row?.playbook?.name ?? (id === null ? 'No playbook' : `Playbook ${id}`);
    };

    return (
        <>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Tile
                    label="Practice replies"
                    value={count(t.shadow_runs)}
                    hint={`${count(drafts)} drafted, ${count(handovers)} handed to a person`}
                />
                <Tile
                    label="Would have sent by itself"
                    value={count(t.would_auto_send)}
                    hint={`${count(t.eligible_if_auto)} if their playbook were set to Auto`}
                    help="Replies that passed every safety check for automatic sending, apart from the agent not being live."
                />
                <Tile label="Compared with your team" value={count(t.compared)} hint={`Agreed on ${count(t.agreed)}`} />
                <Tile
                    label="Agreement"
                    value={pct(t.agreement_rate)}
                    hint={
                        autoRate === null
                            ? 'None it would have sent by itself compared yet'
                            : `${pct(autoRate)} on the ${count(autoCompared)} it would have sent by itself`
                    }
                    help="Share of compared replies where the judge says the agent's reply would have done the same job as your team's."
                />
            </div>

            <GoLiveSummary data={data} playbooks={playbooks} autoCompared={autoCompared} autoRate={autoRate} />

            <PlaybookTable playbooks={playbooks} />

            <Section
                title="Biggest disagreements"
                description="Where the agent's reply and your team's reply differed most. The ones it would have sent by itself come first, lowest score first."
            >
                {worst.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {num(t.compared) > 0 ? 'No disagreements in this period.' : 'Nothing compared yet.'}
                    </p>
                ) : (
                    <ul className="space-y-3">
                        {worst.map((d) => (
                            <DisagreementCard key={d.comparison_id} item={d} playbookName={nameOf(d.playbook_id)} />
                        ))}
                    </ul>
                )}
            </Section>

            <Section>
                <Disclosure title="Sampling and cost">
                    <ul className="space-y-1 text-sm text-muted-foreground">
                        <li>
                            {num(data.sampling?.sample_rate) >= 1
                                ? 'Every practice reply is compared once your team has answered the email.'
                                : `About ${pct(num(data.sampling?.sample_rate))} of practice replies are compared once your team has answered the email.`}{' '}
                            At most {count(data.sampling?.daily_cap)} comparisons are made per day.
                        </li>
                        {num(t.skipped) > 0 && <li>{count(t.skipped)} were skipped by the sampling or the daily limit.</li>}
                        {num(t.failed) > 0 && (
                            <li>{count(t.failed)} comparisons failed (the judge could not score them) and are not counted.</li>
                        )}
                        <li>Judging cost in this period: {usd(num(t.cost_usd))}.</li>
                    </ul>
                </Disclosure>
            </Section>
        </>
    );
}

function Tile({ label, value, hint, help }: { label: string; value: React.ReactNode; hint?: React.ReactNode; help?: string }) {
    return (
        <div className="rounded-xl border border-border bg-card p-4" title={help}>
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="mt-1 text-2xl font-semibold">{value}</p>
            {hint && <p className="mt-0.5 text-xs text-muted-foreground">{hint}</p>}
        </div>
    );
}

// ── What this means ──────────────────────────────────────────────────────────

function joinNames(names: string[]): string {
    if (names.length <= 1) return names.join('');
    return `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
}

function GoLiveSummary({
    data,
    playbooks,
    autoCompared,
    autoRate,
}: {
    data: ShadowReportPayload;
    playbooks: ShadowPlaybookRow[];
    autoCompared: number;
    autoRate: number | null;
}) {
    const t = data.totals;
    const compared = num(t.compared);
    const rate = numOrNull(t.agreement_rate);
    const levels = playbooks.map((p) => ({ name: p.playbook?.name ?? '', ...readinessOf(p) }));
    const good = levels.filter((l) => l.level === 'good').map((l) => l.name);
    const close = levels.filter((l) => l.level === 'close').map((l) => l.name);
    const autoPlaybooks = playbooks.filter((p) => p.playbook?.mode === 'auto').map((p) => p.playbook?.name ?? '');
    const lines: React.ReactNode[] = [];

    if (compared === 0) {
        lines.push('Nothing has been compared yet: comparisons start once your team answers the emails the agent practised on.');
    } else if (compared < READY_MIN_COMPARED) {
        lines.push(
            `Only ${compared} practice ${compared === 1 ? 'reply has' : 'replies have'} been compared with your team so far. That is too few to judge; leave Shadow mode on for at least a week of normal email.`,
        );
    } else if (rate !== null) {
        lines.push(`Overall the agent agreed with your team on ${pct(rate)} of ${compared} compared replies.`);
    }

    if (autoRate !== null) {
        lines.push(
            `On the ${autoCompared} replies it would have sent by itself, it agreed ${pct(autoRate)} of the time. This is the number that matters most before going live.`,
        );
    } else if (num(t.would_auto_send) === 0 && num(t.eligible_if_auto) > 0) {
        lines.push(
            `It would not have sent anything by itself${autoPlaybooks.length === 0 ? ' because no playbook is set to Auto (expected while practising)' : ''}. ${count(t.eligible_if_auto)} replies would have qualified if their playbook were on Auto.`,
        );
    }

    if (good.length > 0) {
        lines.push(
            <>
                Good {good.length === 1 ? 'candidate' : 'candidates'} to automate first:{' '}
                <span className="font-medium">{joinNames(good)}</span>. Start with the lowest-risk one (for example order status) and follow
                the checklist below.
            </>,
        );
    } else if (compared >= READY_MIN_COMPARED) {
        lines.push('No playbook is ready to send by itself yet. Keep practising and fix what the disagreements below show.');
    }
    if (close.length > 0) lines.push(`Getting close: ${joinNames(close)}.`);
    if (asArray(data.worst_disagreements).length > 0) {
        lines.push(
            'Read the disagreements below and fix what they show (feedback on drafts, playbook rules, knowledge), then check this report again in a few days.',
        );
    }

    return (
        <Section title="What this means for going live">
            <ul className="list-disc space-y-1.5 pl-5 text-sm">
                {lines.map((line, i) => (
                    <li key={i}>{line}</li>
                ))}
            </ul>
            <p className="text-xs text-muted-foreground">
                We call a playbook a good candidate when it has at least {READY_MIN_COMPARED} comparisons and agrees with your team at least{' '}
                {pct(READY_MIN_AGREEMENT)} of the time (and, once it has {READY_MIN_AUTO_COMPARED} or more replies it would have sent by
                itself, agrees on those at least {pct(READY_MIN_AGREEMENT)} too). From {pct(CLOSE_MIN_AGREEMENT)} it is getting close.
            </p>
        </Section>
    );
}

// ── Per playbook ─────────────────────────────────────────────────────────────

const READINESS_ICON: Record<Readiness, { icon: React.ComponentType<{ className?: string }>; className: string }> = {
    good: { icon: CheckCircle2Icon, className: 'text-[#0ca30c]' },
    close: { icon: CircleDotIcon, className: 'text-[#c98500] dark:text-[#fab219]' },
    not_ready: { icon: AlertTriangleIcon, className: 'text-[#d03b3b] dark:text-[#e66767]' },
    too_few: { icon: HourglassIcon, className: 'text-muted-foreground' },
    no_playbook: { icon: MinusCircleIcon, className: 'text-muted-foreground' },
};

function ReadinessPill({ level }: { level: Readiness }) {
    const { icon: Icon, className } = READINESS_ICON[level];
    return (
        <span className="inline-flex items-center gap-1 whitespace-nowrap rounded-full border border-border bg-muted/40 px-2 py-0.5 text-[11px] font-medium">
            <Icon className={cn('h-3 w-3', className)} />
            {READINESS_LABELS[level]}
        </span>
    );
}

function modeText(row: ShadowPlaybookRow): string {
    const pb = row.playbook;
    if (!pb) return '';
    if (pb.mode === 'auto') return `Auto, ${num(pb.rollout_percent)}% of conversations`;
    return PLAYBOOK_MODE_LABELS[pb.mode] ?? capitalise(String(pb.mode));
}

function Ratio({ rate, agreed, compared }: { rate: number | null; agreed: number; compared: number }) {
    if (compared === 0) return <span className="text-muted-foreground">—</span>;
    return (
        <>
            {pct(rate)}
            <span className="block text-[11px] text-muted-foreground">
                {agreed} of {compared}
            </span>
        </>
    );
}

function PlaybookTable({ playbooks }: { playbooks: ShadowPlaybookRow[] }) {
    if (playbooks.length === 0) return null;
    const rows = [...playbooks].sort((a, b) => num(b.compared) - num(a.compared) || num(b.runs) - num(a.runs));
    return (
        <Section title="By playbook" description="Hover a column heading for what it means.">
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="text-left text-xs text-muted-foreground">
                        <tr>
                            <th className="py-1.5 pr-3 font-medium">Playbook</th>
                            <th className="py-1.5 pr-3 text-right font-medium" title="Emails the agent practised on">
                                Practice replies
                            </th>
                            <th className="py-1.5 pr-3 text-right font-medium" title="Drafted / handed to a person">
                                Drafted / handed over
                            </th>
                            <th
                                className="py-1.5 pr-3 text-right font-medium"
                                title="Would have been sent with no person involved (in brackets: if the playbook were on Auto)"
                            >
                                Would send by itself
                            </th>
                            <th
                                className="py-1.5 pr-3 text-right font-medium"
                                title="Compared replies where the agent did the same job as your team"
                            >
                                Agreement
                            </th>
                            <th
                                className="py-1.5 pr-3 text-right font-medium"
                                title="Agreement on the replies it would have sent by itself"
                            >
                                When sending by itself
                            </th>
                            <th className="py-1.5 pr-3 text-right font-medium" title="Judge's average score, out of 5">
                                Avg score
                            </th>
                            <th className="py-1.5 font-medium">Ready?</th>
                        </tr>
                    </thead>
                    <tbody className="tabular-nums">
                        {rows.map((row) => {
                            const readiness = readinessOf(row);
                            const pb = row.playbook;
                            return (
                                <tr key={pb?.id ?? 'none'} className="border-t border-border align-top">
                                    <td className="max-w-72 py-2 pr-3">
                                        {pb ? (
                                            <Link
                                                href={`/agent/playbooks/${pb.id}`}
                                                className="font-medium hover:text-primary hover:underline"
                                            >
                                                {pb.name}
                                            </Link>
                                        ) : (
                                            <span className="font-medium text-muted-foreground">No playbook</span>
                                        )}
                                        {pb && <span className="block text-[11px] text-muted-foreground">{modeText(row)}</span>}
                                        <span className="mt-0.5 block text-xs text-muted-foreground">{readiness.text}</span>
                                    </td>
                                    <td className="py-2 pr-3 text-right">{count(row.runs)}</td>
                                    <td className="py-2 pr-3 text-right">
                                        {count(row.drafts)} / {count(row.handovers)}
                                    </td>
                                    <td className="py-2 pr-3 text-right">
                                        {count(row.would_auto_send)}
                                        <span className="block text-[11px] text-muted-foreground">({count(row.eligible_if_auto)})</span>
                                    </td>
                                    <td className="py-2 pr-3 text-right">
                                        <Ratio rate={numOrNull(row.agreement_rate)} agreed={num(row.agreed)} compared={num(row.compared)} />
                                    </td>
                                    <td className="py-2 pr-3 text-right">
                                        <Ratio
                                            rate={numOrNull(row.would_auto_send_agreement_rate)}
                                            agreed={num(row.would_auto_send_agreed)}
                                            compared={num(row.would_auto_send_compared)}
                                        />
                                    </td>
                                    <td className="py-2 pr-3 text-right">{score5(numOrNull(row.avg_score))}</td>
                                    <td className="py-2">
                                        <ReadinessPill level={readiness.level} />
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </Section>
    );
}

// ── Disagreements ────────────────────────────────────────────────────────────

function SubScore({ label, value }: { label: string; value: number | null | undefined }) {
    return (
        <span className="rounded bg-muted px-1.5 py-0.5 text-[11px] tabular-nums" title={`${label}, out of 5`}>
            {label} {typeof value === 'number' ? `${value}/5` : '—'}
        </span>
    );
}

function ReplyBox({ title, text, tone }: { title: string; text: React.ReactNode; tone?: 'ai' }) {
    return (
        <div className={cn('min-w-0 rounded-md border bg-background p-2.5', tone === 'ai' ? 'border-primary/30' : 'border-border')}>
            <p className={cn('mb-1 text-[10px] font-semibold uppercase', tone === 'ai' ? 'text-primary' : 'text-muted-foreground')}>
                {title}
            </p>
            <div className="max-h-64 overflow-y-auto whitespace-pre-wrap text-xs leading-relaxed">{text}</div>
        </div>
    );
}

function DisagreementCard({ item, playbookName }: { item: ShadowDisagreement; playbookName: string }) {
    const judge: Partial<ShadowJudge> = item.judge && !Array.isArray(item.judge) && typeof item.judge === 'object' ? item.judge : {};
    const handedOver = item.ai_decision === 'handover';
    const aiText = handedOver ? (
        <span className="italic text-muted-foreground">
            The agent would have handed this to a person
            {item.ai_handover_reason ? `: ${handoverLabel(item.ai_handover_reason)}` : ''}.
        </span>
    ) : (
        item.ai_reply || <span className="italic text-muted-foreground">No reply text saved.</span>
    );

    return (
        <li className="space-y-2.5 rounded-xl border border-border p-4">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                <span className="text-sm font-medium">{playbookName}</span>
                {item.would_auto_send && (
                    <span className="inline-flex items-center gap-1 rounded-full border border-border bg-muted/40 px-2 py-0.5 font-medium">
                        <SendIcon className="h-3 w-3 text-[#d03b3b] dark:text-[#e66767]" /> Would have sent this by itself
                    </span>
                )}
                <span className="tabular-nums text-muted-foreground">Score {score5(numOrNull(item.score))}</span>
                {item.created_at && <span className="text-muted-foreground">{shortDate(item.created_at)}</span>}
                {item.conversation_id && (
                    <Link
                        href={conversationUrl(item.conversation_id)}
                        className="ml-auto inline-flex items-center gap-1 text-primary hover:underline"
                    >
                        Open the conversation <ExternalLinkIcon className="h-3 w-3" />
                    </Link>
                )}
            </div>
            {judge.reason && <p className="text-sm">{judge.reason}</p>}
            <div className="flex flex-wrap items-center gap-1.5">
                <SubScore label="Outcome" value={judge.outcome_match} />
                <SubScore label="Policy" value={judge.policy_consistency} />
                <SubScore label="Facts" value={judge.factual_grounding} />
                <SubScore label="Tone" value={judge.tone_match} />
                {typeof judge.would_send === 'boolean' && (
                    <span className="text-[11px] text-muted-foreground">
                        {judge.would_send
                            ? 'The judge would still have sent the agent’s reply.'
                            : 'The judge would not have sent the agent’s reply.'}
                    </span>
                )}
            </div>
            <div className="grid gap-2 sm:grid-cols-2">
                <ReplyBox title="What the agent would have sent" text={aiText} tone="ai" />
                <ReplyBox
                    title="What your team sent"
                    text={item.human_reply || <span className="italic text-muted-foreground">The reply text is not available.</span>}
                />
            </div>
        </li>
    );
}

// ── Go-live checklist (docs/agent-autosend.md "Go-live checklist for the owner") ─

function A({ href, children }: { href: string; children: React.ReactNode }) {
    return (
        <Link href={href} className="text-primary hover:underline">
            {children}
        </Link>
    );
}

function GoLiveChecklist() {
    return (
        <Section
            title="Going live, step by step"
            description="Do this one playbook at a time. Nothing is sent by itself until you finish step 5."
        >
            <ol className="list-decimal space-y-2.5 pl-5 text-sm">
                <li>
                    <span className="font-medium">Shadow first.</span> In <A href="/agent/settings">Agent settings</A>, set the mode to{' '}
                    <span className="font-medium">Shadow</span>. The agent reads every email and records what it would have done; nobody
                    sees anything. Leave it on for at least a week of normal email.
                </li>
                <li>
                    <span className="font-medium">Read the shadow report</span> (<A href={AGENT_SCREEN_PATHS.shadow}>this page</A>). Look at
                    what it would have sent by itself, the agreement per playbook (especially when it would have sent by itself) and every
                    disagreement. Fix what they show (feedback on drafts, playbook rules, knowledge) and keep going until the playbook you
                    want to automate agrees with your team almost every time.
                </li>
                <li>
                    <span className="font-medium">Pick one low-risk playbook</span> in <A href="/agent/playbooks">Playbooks</A> (for example
                    order status or tracking). Give it at least 5 active test cases and run its tests until they pass (90% or more) on the
                    current version. Test runs also show up in <A href={agentScreenUrl('simulations')}>Simulations</A>.
                </li>
                <li>
                    <span className="font-medium">Switch the workspace to Draft, then Live</span> in{' '}
                    <A href="/agent/settings">Agent settings</A> (confirm the dialog). Set a long wait before sending (for example 30
                    minutes), a low daily limit (for example 10) and only allow languages you can read.
                </li>
                <li>
                    <span className="font-medium">Turn that one playbook to Auto at 10%</span> on its page in{' '}
                    <A href="/agent/playbooks">Playbooks</A>. The confirmation shows roughly how many recent conversations that is.
                </li>
                <li>
                    <span className="font-medium">Watch.</span> Every automatic reply appears in its conversation with a countdown,{' '}
                    <span className="font-medium">Cancel</span> and <span className="font-medium">Edit instead</span>; mark anything off
                    with <span className="font-medium">This was wrong</span>. Check the playbook page (safety switches, history) and the
                    activity log every day.
                </li>
                <li>
                    <span className="font-medium">Raise slowly</span> (10% → 25% → 50% → 100%), shorten the wait and raise the daily limit.
                    Repeat steps 3 to 7 for each playbook.
                </li>
                <li>
                    <span className="font-medium">Emergency:</span> “Pause AI agent” at the top of{' '}
                    <A href="/agent/settings">Agent settings</A> stops all automatic sending straight away and turns waiting replies back
                    into drafts. The kill switch stops the agent altogether.
                </li>
            </ol>
        </Section>
    );
}
