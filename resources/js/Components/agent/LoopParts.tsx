import { useEffect, useRef, useState, type ReactNode } from 'react';
import {
    AlertTriangleIcon,
    CheckCircle2Icon,
    ChevronDownIcon,
    ChevronRightIcon,
    ExternalLinkIcon,
    InfoIcon,
    LightbulbIcon,
    Loader2Icon,
    ShieldAlertIcon,
} from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { cn } from '@/lib/utils';
import {
    CONFLICT_TYPE_LABELS,
    EVIDENCE_HELP,
    EVIDENCE_LABELS,
    PROPOSAL_STATUS_LABELS,
    RESOLUTION_LABELS,
    SIMULATION_STATUS_LABELS,
    VERDICT_HELP,
    VERDICT_LABELS,
    asArray,
    conversationUrl,
    isSimulationActive,
    loopApi,
    plural,
    proposalStatusVariant,
    toApiError,
    usd,
    verdictVariant,
    type ApiError,
} from '@/lib/agentLoop';
import type {
    ConflictItem,
    ConflictResolution,
    DiffEntry,
    EvidenceQuality,
    ExampleFlag,
    Proposal,
    ProposalSuggestion,
    Simulation,
    SimulationResult,
    Verdict,
} from '@/types/agentLoop';

// ── Small badges ─────────────────────────────────────────────────────────────

export function ProposalStatusBadge({ status }: { status: string | null | undefined }) {
    return <Badge variant={proposalStatusVariant(status)}>{(status && PROPOSAL_STATUS_LABELS[status]) || status || 'Unknown'}</Badge>;
}

export function VerdictBadge({ verdict, overridden }: { verdict: Verdict | string | null | undefined; overridden?: boolean }) {
    const label = (verdict && VERDICT_LABELS[verdict as Verdict]) || verdict || 'Not scored';
    return (
        <Badge variant={verdictVariant(verdict)} title={(verdict && VERDICT_HELP[verdict as Verdict]) || undefined}>
            {label}
            {overridden ? ' (set by you)' : ''}
        </Badge>
    );
}

export function EvidenceBadge({ quality }: { quality: EvidenceQuality | string | null | undefined }) {
    const q = (quality ?? 'none') as EvidenceQuality;
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-medium',
                q === 'recorded' && 'border-success/30 bg-success/5 text-success',
                q === 'reconstructed' && 'border-info/30 bg-info/5 text-info',
                q !== 'recorded' && q !== 'reconstructed' && 'border-border bg-muted/40 text-muted-foreground',
            )}
            title={EVIDENCE_HELP[q] ?? undefined}
        >
            <InfoIcon className="h-2.5 w-2.5" />
            {EVIDENCE_LABELS[q] ?? quality}
        </span>
    );
}

export function SimulationStatusBadge({ status }: { status: string | null | undefined }) {
    const variant =
        status === 'completed'
            ? 'success'
            : status === 'failed' || status === 'budget_exhausted'
              ? 'destructive'
              : status === 'running' || status === 'pending'
                ? 'default'
                : 'secondary';
    return <Badge variant={variant}>{(status && SIMULATION_STATUS_LABELS[status]) || status || 'Unknown'}</Badge>;
}

export function ErrorLine({ error, className }: { error: ApiError | string | null | undefined; className?: string }) {
    if (!error) return null;
    const message = typeof error === 'string' ? error : error.message;
    return (
        <p className={cn('flex items-start gap-1.5 text-xs text-destructive', className)} role="alert">
            <AlertTriangleIcon className="mt-0.5 h-3 w-3 shrink-0" />
            <span>{message}</span>
        </p>
    );
}

export function Spinner({ className }: { className?: string }) {
    return <Loader2Icon className={cn('h-3.5 w-3.5 animate-spin', className)} />;
}

export function ProgressBar({ value, className }: { value: number; className?: string }) {
    const p = Math.max(0, Math.min(1, Number.isFinite(value) ? value : 0));
    return (
        <div
            className={cn('h-1.5 w-full overflow-hidden rounded-full bg-muted', className)}
            role="progressbar"
            aria-valuenow={Math.round(p * 100)}
        >
            <div className="h-full rounded-full bg-primary transition-all" style={{ width: `${p * 100}%` }} />
        </div>
    );
}

/** Collapsible block with a plain button header (works inside other forms and panels). */
export function Disclosure({
    title,
    children,
    defaultOpen = false,
    className,
    right,
}: {
    title: ReactNode;
    children: ReactNode;
    defaultOpen?: boolean;
    className?: string;
    right?: ReactNode;
}) {
    const [open, setOpen] = useState(defaultOpen);
    return (
        <div className={className}>
            <div className="flex items-center gap-2">
                <button
                    type="button"
                    className="flex min-w-0 flex-1 items-center gap-1 text-left text-xs font-medium text-foreground hover:text-primary"
                    aria-expanded={open}
                    onClick={() => setOpen((v) => !v)}
                >
                    {open ? <ChevronDownIcon className="h-3 w-3 shrink-0" /> : <ChevronRightIcon className="h-3 w-3 shrink-0" />}
                    <span className="min-w-0 truncate">{title}</span>
                </button>
                {right}
            </div>
            {open && <div className="mt-1.5">{children}</div>}
        </div>
    );
}

// ── Instruction diff ─────────────────────────────────────────────────────────

/** "Add don't phrase" etc. come from the API; fall back to the op name. */
function entryLabel(entry: DiffEntry): string {
    if (entry.label) return entry.label;
    return entry.op.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
}

function DiffEntryView({ entry }: { entry: DiffEntry }) {
    const before = entry.before ?? null;
    const after = entry.after ?? null;
    return (
        <li className="space-y-0.5">
            <p className="text-[11px] font-medium text-muted-foreground">{entryLabel(entry)}</p>
            {before !== null && (
                <p className="whitespace-pre-wrap rounded bg-destructive/5 px-2 py-0.5 text-xs text-destructive line-through decoration-destructive/40">
                    − {before}
                </p>
            )}
            {after !== null && <p className="whitespace-pre-wrap rounded bg-success/5 px-2 py-0.5 text-xs text-success">+ {after}</p>}
            {before === null && after === null && <p className="text-xs italic text-muted-foreground">No visible text change.</p>}
        </li>
    );
}

/** The instruction change as small before/after lines, grouped by what it changes. */
export function DiffEntries({ entries, empty = 'No instruction changes.' }: { entries: DiffEntry[] | null | undefined; empty?: string }) {
    const list = asArray<DiffEntry>(entries);
    if (list.length === 0) return <p className="text-xs italic text-muted-foreground">{empty}</p>;
    const groups = new Map<string, DiffEntry[]>();
    for (const entry of list) {
        const key = entry.target_label || 'Change';
        groups.set(key, [...(groups.get(key) ?? []), entry]);
    }
    return (
        <div className="space-y-2">
            {[...groups.entries()].map(([label, items]) => (
                <div key={label} className="space-y-1">
                    {groups.size > 1 || label !== 'Change' ? <p className="text-xs font-semibold">{label}</p> : null}
                    <ul className="space-y-1.5">
                        {items.map((entry, i) => (
                            <DiffEntryView key={`${entry.item_id ?? ''}-${entry.op}-${i}`} entry={entry} />
                        ))}
                    </ul>
                </div>
            ))}
        </div>
    );
}

// ── Advice the code refused to turn into changes ─────────────────────────────

function suggestionOp(s: ProposalSuggestion): { name: string; detail: string } {
    const op = s.op;
    if (typeof op === 'string') return { name: op, detail: '' };
    if (op && typeof op === 'object') {
        const o = op as Record<string, unknown>;
        const name = typeof o.op === 'string' ? o.op : typeof o.field === 'string' ? `change ${o.field}` : 'change';
        const bits = ['action', 'field', 'value', 'playbook_slug']
            .map((k) =>
                o[k] !== undefined && o[k] !== null && typeof o[k] !== 'object' ? `${k.replace('_', ' ')}: ${String(o[k])}` : null,
            )
            .filter(Boolean);
        return { name, detail: bits.join(' · ') };
    }
    return { name: 'change', detail: '' };
}

function suggestionLink(s: ProposalSuggestion): { href: string; label: string } {
    const { name } = suggestionOp(s);
    const actionish = /action|allow/i.test(name);
    if (s.target_type === 'playbook' && s.target_id)
        return { href: `/agent/playbooks/${s.target_id}`, label: 'Open the playbook settings' };
    if (actionish) return { href: '/agent/playbooks', label: 'Open playbooks (allowed actions)' };
    return { href: '/agent/settings', label: 'Open agent settings' };
}

/**
 * Permission / limit / mode / rollout changes are never made by the AI: shown
 * as plain advice with a link to where a person changes them.
 */
export function SuggestionNotes({ suggestions, intro }: { suggestions: ProposalSuggestion[] | null | undefined; intro?: ReactNode }) {
    const list = asArray<ProposalSuggestion>(suggestions);
    if (list.length === 0) return null;
    return (
        <div className="space-y-1.5 rounded-md border border-border bg-muted/30 px-2.5 py-2">
            <p className="flex items-center gap-1.5 text-xs font-medium">
                <LightbulbIcon className="h-3.5 w-3.5 text-warning" />
                {intro ?? 'Suggestions only (the AI cannot change permissions or limits)'}
            </p>
            <ul className="space-y-1.5">
                {list.map((s, i) => {
                    const { name, detail } = suggestionOp(s);
                    const link = suggestionLink(s);
                    return (
                        <li key={i} className="text-xs">
                            <span className="font-medium">{name.replace(/_/g, ' ')}</span>
                            {detail && <span className="text-muted-foreground"> ({detail})</span>}
                            {s.reason && <span className="block text-muted-foreground">{s.reason}</span>}
                            <a href={link.href} className="inline-flex items-center gap-0.5 text-primary hover:underline">
                                {link.label} <ExternalLinkIcon className="h-2.5 w-2.5" />
                            </a>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

// ── Conflicts ────────────────────────────────────────────────────────────────

function ConflictRow({
    proposal,
    item,
    canManage,
    onChange,
}: {
    proposal: Proposal;
    item: ConflictItem;
    canManage: boolean;
    onChange: (p: Proposal) => void;
}) {
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [scoping, setScoping] = useState(false);
    const [condition, setCondition] = useState('');
    const blocking = item.severity === 'blocking';
    const resolved = !!item.resolution;
    const options = asArray<ConflictResolution>(item.options);
    const isKnowledge = item.new?.target_type === 'knowledge';

    async function resolve(resolution: ConflictResolution, cond?: string) {
        setBusy(resolution);
        setError(null);
        try {
            onChange(await loopApi.resolveConflict(proposal.id, { conflict_id: item.id, resolution, condition: cond }));
            setScoping(false);
        } catch (e) {
            setError(toApiError(e));
        } finally {
            setBusy(null);
        }
    }

    return (
        <li
            className={cn(
                'space-y-1.5 rounded-md border px-2.5 py-2 text-xs',
                resolved
                    ? 'border-border bg-muted/20'
                    : blocking
                      ? 'border-destructive/30 bg-destructive/5'
                      : 'border-warning/30 bg-warning/5',
            )}
        >
            <div className="flex items-start gap-1.5">
                {blocking ? (
                    <ShieldAlertIcon
                        className={cn('mt-0.5 h-3.5 w-3.5 shrink-0', resolved ? 'text-muted-foreground' : 'text-destructive')}
                    />
                ) : (
                    <AlertTriangleIcon className={cn('mt-0.5 h-3.5 w-3.5 shrink-0', resolved ? 'text-muted-foreground' : 'text-warning')} />
                )}
                <div className="min-w-0 flex-1">
                    <p className="font-medium">
                        {CONFLICT_TYPE_LABELS[item.type] ?? item.type}
                        {!resolved && (
                            <span className={cn('ml-1.5 text-[10px] uppercase', blocking ? 'text-destructive' : 'text-warning')}>
                                {blocking ? 'must resolve' : 'warning'}
                            </span>
                        )}
                    </p>
                    {item.explanation && <p className="text-muted-foreground">{item.explanation}</p>}
                </div>
            </div>
            <div className="grid gap-1 sm:grid-cols-2">
                {item.existing?.text && (
                    <p className="rounded border border-border bg-background px-2 py-1">
                        <span className="block text-[10px] font-semibold uppercase text-muted-foreground">
                            Existing{item.existing.label ? ` · ${item.existing.label}` : ''}
                        </span>
                        {item.existing.text}
                    </p>
                )}
                {item.new?.text && (
                    <p className="rounded border border-primary/20 bg-background px-2 py-1">
                        <span className="block text-[10px] font-semibold uppercase text-primary">
                            New{item.new.label ? ` · ${item.new.label}` : ''}
                        </span>
                        {item.new.text}
                    </p>
                )}
            </div>
            {resolved ? (
                <p className="flex items-center gap-1 text-success">
                    <CheckCircle2Icon className="h-3 w-3" /> {RESOLUTION_LABELS[String(item.resolution)] ?? item.resolution}
                    {item.note ? <span className="text-muted-foreground"> · {item.note}</span> : null}
                </p>
            ) : canManage ? (
                <div className="space-y-1.5">
                    <div className="flex flex-wrap gap-1.5">
                        {options.map((opt) =>
                            opt === 'scope_new' ? (
                                <Button
                                    key={opt}
                                    size="xs"
                                    variant="outline"
                                    className="h-6 text-[11px]"
                                    onClick={() => setScoping((v) => !v)}
                                >
                                    {RESOLUTION_LABELS.scope_new}
                                </Button>
                            ) : (
                                <Button
                                    key={opt}
                                    size="xs"
                                    variant={opt === 'acknowledge' || opt === 'keep_both' ? 'ghost' : 'outline'}
                                    className="h-6 text-[11px]"
                                    disabled={busy !== null}
                                    onClick={() => void resolve(opt)}
                                >
                                    {busy === opt ? <Spinner className="h-3 w-3" /> : null}
                                    {RESOLUTION_LABELS[opt] ?? opt}
                                </Button>
                            ),
                        )}
                    </div>
                    {scoping && (
                        <form
                            className="flex gap-1.5"
                            onSubmit={(e) => {
                                e.preventDefault();
                                if (condition.trim()) void resolve('scope_new', condition.trim());
                            }}
                        >
                            <Input
                                value={condition}
                                onChange={(e) => setCondition(e.target.value)}
                                className="h-7 text-xs"
                                autoFocus
                                aria-label="Only when"
                                placeholder={
                                    isKnowledge
                                        ? 'Playbook slugs, e.g. order_status, returns'
                                        : 'e.g. the customer asks for a delivery date'
                                }
                            />
                            <Button type="submit" size="xs" className="h-7" disabled={!condition.trim() || busy !== null}>
                                {busy === 'scope_new' ? <Spinner className="h-3 w-3" /> : 'Apply'}
                            </Button>
                        </form>
                    )}
                    <ErrorLine error={error} />
                </div>
            ) : blocking ? (
                <p className="text-muted-foreground">An admin will choose which one wins.</p>
            ) : null}
        </li>
    );
}

function ExampleFlagRow({
    proposal,
    flag,
    canManage,
    onChange,
}: {
    proposal: Proposal;
    flag: ExampleFlag;
    canManage: boolean;
    onChange: (p: Proposal) => void;
}) {
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const options = asArray<ConflictResolution>(flag.options).length
        ? asArray<ConflictResolution>(flag.options)
        : (['retire', 'keep'] as const);

    async function resolve(resolution: ConflictResolution) {
        setBusy(resolution);
        setError(null);
        try {
            onChange(await loopApi.resolveConflict(proposal.id, { conflict_id: flag.id, resolution }));
        } catch (e) {
            setError(toApiError(e));
        } finally {
            setBusy(null);
        }
    }

    return (
        <li className="space-y-1 rounded-md border border-border bg-muted/20 px-2.5 py-2 text-xs">
            <p className="font-medium">
                Example reply X{flag.example_id} goes against this change{flag.phrase ? ` (“${flag.phrase}”)` : ''}
            </p>
            {flag.reason && <p className="text-muted-foreground">{flag.reason}</p>}
            {flag.reply_excerpt && <p className="rounded border border-border bg-background px-2 py-1 italic">“{flag.reply_excerpt}”</p>}
            {flag.resolution ? (
                <p className="flex items-center gap-1 text-success">
                    <CheckCircle2Icon className="h-3 w-3" /> {RESOLUTION_LABELS[String(flag.resolution)] ?? flag.resolution}
                </p>
            ) : canManage ? (
                <div className="flex flex-wrap gap-1.5">
                    {options.map((opt) => (
                        <Button
                            key={opt}
                            size="xs"
                            variant="outline"
                            className="h-6 text-[11px]"
                            disabled={busy !== null}
                            onClick={() => void resolve(opt)}
                        >
                            {busy === opt ? <Spinner className="h-3 w-3" /> : null}
                            {RESOLUTION_LABELS[opt] ?? opt}
                        </Button>
                    ))}
                </div>
            ) : null}
            <ErrorLine error={error} />
        </li>
    );
}

/** Conflicts with existing instructions: blocking ones need a choice, warnings can be accepted. */
export function ConflictList({
    proposal,
    canManage,
    onChange,
}: {
    proposal: Proposal;
    canManage: boolean;
    onChange: (p: Proposal) => void;
}) {
    const [checking, setChecking] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const conflicts = proposal.conflicts;
    const items = asArray<ConflictItem>(conflicts?.items);
    const flags = asArray<ExampleFlag>(conflicts?.example_flags);
    const open = isOpen(proposal);

    async function recheck() {
        setChecking(true);
        setError(null);
        try {
            onChange(await loopApi.check(proposal.id));
        } catch (e) {
            setError(toApiError(e));
        } finally {
            setChecking(false);
        }
    }

    if (!conflicts) {
        return (
            <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                <span>Not checked against existing instructions yet{canManage ? '' : '.'}</span>
                {canManage && open && (
                    <Button size="xs" variant="outline" className="h-6 text-[11px]" onClick={() => void recheck()} disabled={checking}>
                        {checking ? <Spinner className="h-3 w-3" /> : null} Check for conflicts
                    </Button>
                )}
                <ErrorLine error={error} />
            </div>
        );
    }

    const llmFailed = conflicts.llm?.status === 'failed';
    return (
        <div className="space-y-1.5">
            {items.length === 0 && flags.length === 0 ? (
                <p className="flex items-center gap-1.5 text-xs text-success">
                    <CheckCircle2Icon className="h-3.5 w-3.5" /> No conflicts with existing instructions.
                </p>
            ) : (
                <ul className="space-y-1.5">
                    {items.map((item) => (
                        <ConflictRow key={item.id} proposal={proposal} item={item} canManage={canManage && open} onChange={onChange} />
                    ))}
                    {flags.map((flag) => (
                        <ExampleFlagRow key={flag.id} proposal={proposal} flag={flag} canManage={canManage && open} onChange={onChange} />
                    ))}
                </ul>
            )}
            {llmFailed && (
                <p className="text-[11px] text-warning">
                    The AI part of the check failed{conflicts.llm?.error ? `: ${conflicts.llm.error}` : ''}.
                </p>
            )}
            {canManage && open && (
                <button
                    type="button"
                    className="text-[11px] text-muted-foreground underline hover:text-foreground"
                    onClick={() => void recheck()}
                >
                    {checking ? 'Checking…' : 'Check again'}
                </button>
            )}
            <ErrorLine error={error} />
        </div>
    );
}

function isOpen(p: Proposal): boolean {
    return ['draft', 'testing', 'ready', 'stale'].includes(p.status);
}

// ── Test results (gate / playbook tests) ─────────────────────────────────────

function n(value: number | undefined | null): number {
    return typeof value === 'number' && Number.isFinite(value) ? value : 0;
}

/** "5 passed · 1 got worse · 1 got better" from a simulation's totals. */
export function TotalsLine({ simulation, className }: { simulation: Simulation; className?: string }) {
    const t = simulation.totals ?? {};
    const parts: { label: string; value: number; tone: string }[] = [
        { label: 'passed', value: n(t.pass), tone: 'text-success' },
        { label: 'failed', value: n(t.fail), tone: 'text-destructive' },
        { label: 'got worse', value: n(t.regressed), tone: 'text-destructive font-semibold' },
        { label: 'got better', value: n(t.improved), tone: 'text-success' },
        { label: 'changed (noise)', value: n(t.changed), tone: 'text-muted-foreground' },
        { label: 'errors', value: n(t.error), tone: 'text-warning' },
    ].filter((p) => p.value > 0);
    return (
        <p className={cn('text-xs', className)}>
            {parts.length === 0 ? (
                <span className="text-muted-foreground">No results yet.</span>
            ) : (
                parts.map((p, i) => (
                    <span key={p.label}>
                        {i > 0 && <span className="text-muted-foreground"> · </span>}
                        <span className={p.tone}>
                            {p.value} {p.label}
                        </span>
                    </span>
                ))
            )}
        </p>
    );
}

function TextBox({ title, text, tone }: { title: string; text: string | null | undefined; tone?: 'primary' | 'muted' }) {
    return (
        <div className={cn('min-w-0 rounded-md border bg-background p-2', tone === 'primary' ? 'border-primary/20' : 'border-border')}>
            <p className={cn('mb-1 text-[10px] font-semibold uppercase', tone === 'primary' ? 'text-primary' : 'text-muted-foreground')}>
                {title}
            </p>
            {text ? (
                <p className="max-h-56 overflow-y-auto whitespace-pre-wrap text-xs leading-relaxed">{text}</p>
            ) : (
                <p className="text-xs italic text-muted-foreground">Nothing</p>
            )}
        </div>
    );
}

function Score({ label, value }: { label: string; value: number | null | undefined }) {
    return (
        <span className="rounded bg-muted px-1.5 py-0.5 text-[11px] tabular-nums" title={`${label} (1-5)`}>
            {label} {typeof value === 'number' ? `${value}/5` : '—'}
        </span>
    );
}

/** One scored case: verdict, scores, judge reason, failed checks and the replies side by side. */
export function ResultDetail({ result, compareBaseline }: { result: SimulationResult; compareBaseline?: boolean }) {
    const judge = result.scores?.judge ?? null;
    const det = result.scores?.deterministic ?? null;
    const failures = asArray<{ check: string; detail?: string | null }>(det?.failures);
    const aiText =
        result.ai_decision === 'handover'
            ? `(Handed to a person${result.ai_handover_reason ? `: ${result.ai_handover_reason}` : ''})`
            : result.ai_reply;
    const factsRelaxed = result.scores?.facts_scored === false || (result.evidence_quality && result.evidence_quality !== 'recorded');

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap items-center gap-1.5">
                {judge && judge.status !== 'failed' && (
                    <>
                        <Score label="Outcome" value={judge.outcome_match} />
                        <Score label="Policy" value={judge.policy_consistency} />
                        <Score label="Facts" value={judge.factual_grounding} />
                        <Score label="Tone" value={judge.tone_match} />
                        {typeof judge.would_send === 'boolean' && (
                            <span className={cn('text-[11px]', judge.would_send ? 'text-success' : 'text-destructive')}>
                                {judge.would_send ? 'Would send' : 'Would not send'}
                            </span>
                        )}
                    </>
                )}
                {judge?.status === 'failed' && (
                    <span className="text-[11px] text-warning">Judge failed: {judge.error ?? 'unknown error'}</span>
                )}
                <EvidenceBadge quality={result.evidence_quality} />
                {result.cached && <span className="text-[10px] text-muted-foreground">cached</span>}
            </div>
            {(judge?.reason || result.judge_notes) && (
                <p className="text-xs text-muted-foreground">{judge?.reason ?? result.judge_notes}</p>
            )}
            {factsRelaxed && (
                <p className="text-[11px] text-muted-foreground">{EVIDENCE_HELP[(result.evidence_quality ?? 'none') as EvidenceQuality]}</p>
            )}
            {failures.length > 0 && (
                <ul className="space-y-0.5">
                    {failures.map((f, i) => (
                        <li key={i} className="text-xs text-destructive">
                            ✗ {f.check.replace(/_/g, ' ')}
                            {f.detail ? `: ${f.detail}` : ''}
                        </li>
                    ))}
                </ul>
            )}
            {result.error && <ErrorLine error={result.error} />}
            {result.customer_message && <TextBox title="Customer wrote" text={result.customer_message} />}
            <div className={cn('grid gap-2', compareBaseline && result.baseline_run_id ? 'lg:grid-cols-3' : 'sm:grid-cols-2')}>
                <TextBox title="AI reply" text={aiText} tone="primary" />
                {compareBaseline && result.baseline_run_id ? (
                    <TextBox title="AI reply before this change" text={result.baseline_reply} />
                ) : null}
                <TextBox title="What the person actually sent" text={result.human_reply} />
            </div>
            {result.human_override_note && <p className="text-[11px] text-muted-foreground">Your note: {result.human_override_note}</p>}
        </div>
    );
}

/**
 * Test results of a gate or playbook-test run: a one-line summary and, for
 * admins, the per-case detail (loaded on demand).
 */
export function GateSummary({
    simulation,
    canManage,
    title = 'Saved tests',
}: {
    simulation: Simulation | null | undefined;
    canManage: boolean;
    title?: string;
}) {
    const [results, setResults] = useState<SimulationResult[] | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const [open, setOpen] = useState<number | null>(null);

    if (!simulation) return null;
    const active = isSimulationActive(simulation.status);
    const total = n(simulation.totals?.cases);
    const done = n(simulation.totals?.done);

    async function load() {
        if (!simulation) return;
        setLoading(true);
        setError(null);
        try {
            const r = await loopApi.simulationResults(simulation.id, { per_page: 100 });
            setResults(asArray<SimulationResult>(r.data));
        } catch (e) {
            setError(toApiError(e));
        } finally {
            setLoading(false);
        }
    }

    if (total === 0 && !active) {
        return <p className="text-xs text-muted-foreground">{title}: no saved test covers this change yet.</p>;
    }

    return (
        <div className="space-y-1.5">
            <div className="flex flex-wrap items-center gap-2 text-xs">
                <span className="font-medium">{title}</span>
                <SimulationStatusBadge status={simulation.status} />
                <span className="text-muted-foreground">
                    {done}/{plural(total, 'case')}
                    {simulation.cost_usd > 0 ? ` · ${usd(simulation.cost_usd)}` : ''}
                </span>
                {active && <Spinner className="h-3 w-3 text-muted-foreground" />}
            </div>
            {active && <ProgressBar value={simulation.progress} />}
            <TotalsLine simulation={simulation} />
            {simulation.error && <ErrorLine error={simulation.error} />}
            {canManage && (
                <Disclosure
                    title={results ? `Each test (${results.length})` : 'Show each test'}
                    right={loading ? <Spinner className="h-3 w-3" /> : null}
                    className="pt-0.5"
                >
                    <ResultsLoader results={results} loading={loading} error={error} onLoad={load}>
                        <ul className="space-y-1">
                            {(results ?? []).map((r) => (
                                <li key={r.id} className="rounded-md border border-border bg-background">
                                    <button
                                        type="button"
                                        className="flex w-full items-center gap-2 px-2 py-1.5 text-left text-xs"
                                        onClick={() => setOpen((v) => (v === r.id ? null : r.id))}
                                        aria-expanded={open === r.id}
                                    >
                                        <VerdictBadge verdict={r.effective_verdict ?? r.verdict} overridden={!!r.human_override} />
                                        <span className="min-w-0 flex-1 truncate">
                                            {r.test_case_name || r.subject || `Case ${r.frozen_case_id}`}
                                        </span>
                                        {r.conversation_id && (
                                            <a
                                                href={conversationUrl(r.conversation_id)}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="text-muted-foreground hover:text-primary"
                                                onClick={(e) => e.stopPropagation()}
                                                aria-label="Open the conversation in a new tab"
                                            >
                                                <ExternalLinkIcon className="h-3 w-3" />
                                            </a>
                                        )}
                                    </button>
                                    {open === r.id && (
                                        <div className="border-t border-border p-2">
                                            <ResultDetail result={r} compareBaseline={simulation.compares_baseline} />
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </ResultsLoader>
                </Disclosure>
            )}
        </div>
    );
}

/** Loads once when first rendered (i.e. when the disclosure opens). */
function ResultsLoader({
    results,
    loading,
    error,
    onLoad,
    children,
}: {
    results: SimulationResult[] | null;
    loading: boolean;
    error: ApiError | null;
    onLoad: () => void;
    children: ReactNode;
}) {
    const loadRef = useRef(onLoad);
    loadRef.current = onLoad;
    const needed = results === null && !loading;
    useEffect(() => {
        if (needed) loadRef.current();
        // Only on mount (the disclosure opening); later loads are explicit.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
    if (error) return <ErrorLine error={error} />;
    if (results === null) return <p className="text-xs text-muted-foreground">Loading…</p>;
    if (results.length === 0) return <p className="text-xs text-muted-foreground">No results yet.</p>;
    return <>{children}</>;
}
