import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { CheckCircle2Icon, ExternalLinkIcon, MessagesSquareIcon, ShieldAlertIcon } from 'lucide-react';
import { actionStatusLabel } from '@/lib/agentActions';
import { agentScreenUrl } from '@/lib/agentLoop';
import { cn } from '@/lib/utils';
import type { AgentRunPayload, RunSource } from '@/types/agent';
import {
    autoSendBlockerLabel,
    capitalise,
    formatCost,
    formatTokens,
    proposedActionStatusLabel,
    sourceKindLabel,
    sourceText,
} from './agentLabels';

interface Props {
    run: AgentRunPayload;
    /** Admins get links to the playbook and knowledge items. */
    isAdmin: boolean;
    /** Enables "Ask the manager about this" (admins). */
    conversationId?: number;
}

/** Issues the validator raised, flattened across rounds and the LLM pass. */
function validatorIssues(run: AgentRunPayload): string[] {
    const v = run.validator;
    if (!v) return [];
    const issues: string[] = [];
    for (const round of Array.isArray(v.deterministic) ? v.deterministic : []) {
        for (const issue of Array.isArray(round) ? round : []) {
            if (issue?.problem) issues.push(issue.problem);
        }
    }
    const llm = v.llm;
    if (llm && 'issues' in llm && Array.isArray(llm.issues)) {
        for (const issue of llm.issues) {
            if (issue?.problem) issues.push(issue.quote ? `"${issue.quote}": ${issue.problem}` : issue.problem);
        }
    }
    if (llm && 'error' in llm && llm.error) issues.push(`The checker could not run: ${llm.error}`);
    return Array.from(new Set(issues));
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div className="space-y-1">
            <p className="text-[10px] font-semibold uppercase tracking-[0.08em] text-muted-foreground/70">{title}</p>
            {children}
        </div>
    );
}

function SourceChip({ source, isAdmin }: { source: RunSource; isAdmin: boolean }) {
    const unknown = source.type === 'unknown';
    const body = (
        <>
            <span className="font-medium">{sourceKindLabel(source)}</span>
            <span className="text-muted-foreground"> · {sourceText(source)}</span>
        </>
    );
    const className = cn(
        'inline-block max-w-full truncate rounded border px-1.5 py-0.5 text-[11px] align-middle',
        unknown ? 'border-destructive/30 bg-destructive/5 text-destructive' : 'border-border bg-muted/40',
    );

    if (source.type === 'knowledge' && source.knowledge_item_id && isAdmin) {
        return (
            <a
                href={`/agent/knowledge/${source.knowledge_item_id}/edit`}
                className={cn(className, 'hover:border-primary/40')}
                title={source.body ?? undefined}
            >
                {body}
            </a>
        );
    }
    return (
        <span className={className} title={source.type === 'knowledge' ? (source.body ?? undefined) : undefined}>
            {body}
        </span>
    );
}

function PlaybookLine({ run, isAdmin }: Props) {
    const playbook = run.playbook;
    const reason = run.triage?.reason;
    const confidence = typeof run.triage?.confidence === 'number' ? Math.round(run.triage.confidence * 100) : null;

    return (
        <Section title="Playbook">
            {playbook ? (
                <p>
                    {isAdmin ? (
                        <a href={`/agent/playbooks/${playbook.id}`} className="font-medium text-primary hover:underline">
                            {playbook.name}
                        </a>
                    ) : (
                        <span className="font-medium">{playbook.name}</span>
                    )}
                    {playbook.version ? <span className="text-muted-foreground"> v{playbook.version}</span> : null}
                    {confidence !== null && <span className="text-muted-foreground"> · {confidence}% match</span>}
                </p>
            ) : (
                <p className="text-muted-foreground">No playbook matched.</p>
            )}
            {reason && <p className="text-muted-foreground">Why: {reason}</p>}
            {(run.triage?.risk_flags?.length ?? 0) > 0 && (
                <p className="text-warning">Risk flags: {run.triage!.risk_flags.map((f) => f.replace(/_/g, ' ')).join(', ')}</p>
            )}
        </Section>
    );
}

function RulesList({ run, isAdmin }: Props) {
    const rules = (Array.isArray(run.rules_used) ? run.rules_used : []).filter((r) => r && (r.then || r.id));
    if (rules.length === 0) return null;
    const playbookId = run.playbook?.id;

    return (
        <Section title={`Rules used (${rules.length})`}>
            <ul className="space-y-0.5">
                {rules.map((rule) => (
                    <li key={rule.id} className="flex items-start gap-1.5">
                        <span className="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-muted-foreground/60" />
                        <span className="min-w-0 flex-1">
                            {rule.then ? (
                                <>
                                    {rule.if && rule.if !== 'always' && <span className="text-muted-foreground">If {rule.if}: </span>}
                                    {rule.then}
                                </>
                            ) : (
                                <span className="text-muted-foreground italic">Rule {rule.id} (no longer in this playbook)</span>
                            )}
                        </span>
                        {isAdmin && playbookId && (
                            <a
                                href={`/agent/playbooks/${playbookId}#rule-${rule.id}`}
                                className="shrink-0 text-muted-foreground hover:text-primary"
                                title="Edit this rule"
                                aria-label="Edit this rule"
                            >
                                <ExternalLinkIcon className="h-3 w-3" />
                            </a>
                        )}
                    </li>
                ))}
            </ul>
        </Section>
    );
}

function ClaimsList({ run, isAdmin }: Props) {
    const claims = (Array.isArray(run.claims) ? run.claims : []).filter((c) => c && c.text);
    if (claims.length === 0) return null;

    return (
        <Section title={`Facts the draft relies on (${claims.length})`}>
            <ul className="space-y-1.5">
                {claims.map((claim, i) => (
                    <li key={i} className="space-y-0.5">
                        <p>{claim.text}</p>
                        <div className="flex flex-wrap gap-1">
                            {(Array.isArray(claim.sources) ? claim.sources : []).map((source, j) => (
                                <SourceChip key={`${source.id}-${j}`} source={source} isAdmin={isAdmin} />
                            ))}
                            {(claim.sources?.length ?? 0) === 0 && <span className="text-[11px] text-destructive">No source given</span>}
                        </div>
                    </li>
                ))}
            </ul>
        </Section>
    );
}

function ValidatorLine({ run }: { run: AgentRunPayload }) {
    const v = run.validator;
    if (!v) return null;
    const issues = validatorIssues(run);

    if (v.passed === null && !v.error) return null;

    return (
        <Section title="Checks">
            {v.passed ? (
                <p className="flex items-center gap-1 text-success">
                    <CheckCircle2Icon className="h-3 w-3" />
                    Passed{v.rewrites > 0 ? ' after one rewrite' : ''}
                </p>
            ) : (
                <p className="flex items-center gap-1 text-destructive">
                    <ShieldAlertIcon className="h-3 w-3" />
                    {v.error ? 'The checker could not run' : 'Flagged'}
                </p>
            )}
            {issues.length > 0 && (
                <ul className={cn('list-disc pl-4', v.passed ? 'text-muted-foreground' : 'text-destructive')}>
                    {issues.map((issue, i) => (
                        <li key={i}>{issue}</li>
                    ))}
                </ul>
            )}
            {v.passed && issues.length > 0 && v.rewrites > 0 && (
                <p className="text-[11px] text-muted-foreground">These were fixed by the rewrite.</p>
            )}
        </Section>
    );
}

function ActionsList({ run }: { run: AgentRunPayload }) {
    const actions = (Array.isArray(run.proposed_actions) ? run.proposed_actions : []).filter((a) => a && a.action);
    if (actions.length === 0) return null;
    return (
        <Section title="Shopify actions it suggested">
            <ul className="list-disc pl-4">
                {actions.map((a, i) => {
                    const refused = proposedActionStatusLabel(a.status);
                    return (
                        <li key={i}>
                            <span className="font-medium">{capitalise(a.action.replace(/_/g, ' '))}</span>
                            {a.order_number ? ` · ${a.order_number}` : ''}
                            {a.reason ? <span className="text-muted-foreground"> · {a.reason}</span> : null}
                            {refused ? (
                                <span className="text-destructive"> · not done: {refused}</span>
                            ) : a.execution_status ? (
                                <span className="text-muted-foreground">
                                    {' '}
                                    · {actionStatusLabel(a.execution_status).toLowerCase()} (see the action card)
                                </span>
                            ) : (
                                <span className="text-muted-foreground"> · not carried out</span>
                            )}
                        </li>
                    );
                })}
            </ul>
        </Section>
    );
}

/** "Why this wasn't sent automatically": only when the playbook is on auto (no `playbook_mode` blocker). */
function AutoSendBlockers({ run }: { run: AgentRunPayload }) {
    const blockers = Array.isArray(run.auto_send?.blockers) ? run.auto_send!.blockers! : [];
    if (run.decision !== 'draft' || blockers.length === 0 || blockers.includes('playbook_mode')) return null;
    const labels = Array.from(new Set(blockers.map(autoSendBlockerLabel)));
    return (
        <Section title="Why this wasn’t sent automatically">
            <ul className="list-disc pl-4">
                {labels.map((label) => (
                    <li key={label}>{label}</li>
                ))}
            </ul>
        </Section>
    );
}

/** The customer's other conversations the agent was shown before it wrote. */
function RelatedLine({ run }: { run: AgentRunPayload }) {
    const related = (Array.isArray(run.related_conversations) ? run.related_conversations : []).filter((r) => r && r.id);
    if (related.length === 0) return null;
    return (
        <Section title="Other conversations from this customer it read">
            <ul className="list-disc pl-4">
                {related.map((r) => (
                    <li key={r.id}>
                        <Link href={`/conversations/${r.id}`} className="font-medium text-primary hover:underline">
                            Conversation #{r.id}
                        </Link>
                        <span className="text-muted-foreground">
                            {' '}
                            · {r.status}
                            {r.waiting_on_us ? ' · waiting on us' : ''}
                        </span>
                    </li>
                ))}
            </ul>
        </Section>
    );
}

/** Admins: open a new manager chat pre-filled with this conversation and run. */
function AskManager({ run, conversationId }: { run: AgentRunPayload; conversationId?: number }) {
    if (!conversationId) return null;
    const question =
        run.decision === 'handover'
            ? `Why did the agent hand conversation #${conversationId} over to a person (run #${run.id})? Should a playbook or knowledge item change so it can handle this next time?`
            : `Look at conversation #${conversationId} (run #${run.id}). Was this draft right, and should anything in the playbook, voice or knowledge change?`;
    return (
        <Link
            href={agentScreenUrl('manager', { new: 1, prompt: question })}
            className="inline-flex items-center gap-1 text-[11px] font-medium text-primary hover:underline"
        >
            <MessagesSquareIcon className="h-3 w-3" /> Ask the manager about this
        </Link>
    );
}

/** Tokens, cost, latency and models: collapsed by default. */
function RunDetails({ run, children }: { run: AgentRunPayload; children?: ReactNode }) {
    const t = run.tokens ?? { in: 0, out: 0, cache_read: 0, cache_write: 0, reasoning: 0 };
    const models = run.models && typeof run.models === 'object' ? Object.entries(run.models) : [];

    return (
        <details className="group">
            <summary className="cursor-pointer select-none text-[11px] text-muted-foreground hover:text-foreground">Details</summary>
            <dl className="mt-1 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-[11px] text-muted-foreground">
                <dt>Cost</dt>
                <dd className="tabular-nums">{formatCost(run.cost_usd ?? 0)}</dd>
                <dt>Tokens</dt>
                <dd className="tabular-nums">
                    {formatTokens(t.in)} in · {formatTokens(t.out)} out
                    {t.cache_read > 0 ? ` · ${formatTokens(t.cache_read)} cached` : ''}
                </dd>
                {run.latency_ms != null && (
                    <>
                        <dt>Time</dt>
                        <dd className="tabular-nums">{(run.latency_ms / 1000).toFixed(1)}s</dd>
                    </>
                )}
                {models.map(([role, model]) => (
                    <FragmentRow key={role} label={capitalise(role)} value={model} />
                ))}
                <dt>Run</dt>
                <dd>#{run.id}</dd>
            </dl>
            {children}
        </details>
    );
}

function FragmentRow({ label, value }: { label: string; value: string }) {
    return (
        <>
            <dt>{label}</dt>
            <dd className="truncate">{value}</dd>
        </>
    );
}

/** "Why this draft": collapsed disclosure inside the draft card. */
export function DraftWhy({ run, isAdmin, conversationId }: Props) {
    const claims = Array.isArray(run.claims) ? run.claims.length : 0;
    const passed = run.validator?.passed;
    const summary = [
        run.playbook?.name ?? 'No playbook',
        claims > 0 ? `${claims} fact${claims === 1 ? '' : 's'} cited` : null,
        passed === true ? 'checks passed' : passed === false ? 'checks flagged issues' : null,
    ]
        .filter(Boolean)
        .join(' · ');

    const note = run.internal_note?.trim();
    const blockers = Array.isArray(run.auto_send?.blockers) ? run.auto_send!.blockers! : [];
    const needsHuman = blockers.includes('needs_human');

    return (
        <>
            {note && (
                <p
                    className={`mb-2 whitespace-pre-wrap rounded-md border px-2.5 py-1.5 text-xs leading-relaxed ${
                        needsHuman ? 'border-warning/40 bg-warning/10' : 'border-border bg-background'
                    }`}
                >
                    <span className="font-medium">{needsHuman ? 'Needs you before or after sending: ' : 'Note from the agent: '}</span>
                    {note}
                </p>
            )}
            <DraftWhyDetails run={run} isAdmin={isAdmin} conversationId={conversationId} summary={summary} />
        </>
    );
}

function DraftWhyDetails({ run, isAdmin, conversationId, summary }: Props & { summary: string }) {
    return (
        <details className="mb-2 rounded-md border border-info/20 bg-background px-2.5 py-1.5 text-xs">
            <summary className="cursor-pointer select-none text-muted-foreground">
                <span className="font-medium text-foreground">Why this draft</span>
                <span className="ml-1.5">{summary}</span>
            </summary>
            <div className="mt-2 space-y-2.5">
                <PlaybookLine run={run} isAdmin={isAdmin} />
                <RulesList run={run} isAdmin={isAdmin} />
                <ClaimsList run={run} isAdmin={isAdmin} />
                <RelatedLine run={run} />
                <ValidatorLine run={run} />
                <ActionsList run={run} />
                <AutoSendBlockers run={run} />
                <RunDetails run={run} />
                {isAdmin && <AskManager run={run} conversationId={conversationId} />}
            </div>
        </details>
    );
}

/** What the agent found and why it stopped, under the "Needs a human" banner. */
export function HandoverWhy({ run, isAdmin, conversationId }: Props) {
    const note = run.internal_note?.trim();
    const rejected = run.validator?.rejected_draft?.trim();

    return (
        <details className="mt-1.5 text-xs" open={!!note}>
            <summary className="cursor-pointer select-none text-muted-foreground hover:text-foreground">What the agent found</summary>
            <div className="mt-1.5 space-y-2.5">
                {note && (
                    <p className="whitespace-pre-wrap rounded-md border border-border bg-background px-2.5 py-1.5 leading-relaxed">
                        {note}
                    </p>
                )}
                <PlaybookLine run={run} isAdmin={isAdmin} />
                <RelatedLine run={run} />
                <ValidatorLine run={run} />
                <ActionsList run={run} />
                <RunDetails run={run}>
                    {rejected && (
                        <div className="mt-1.5">
                            <p className="text-[11px] text-muted-foreground">Draft that failed the checks (never shown to the customer):</p>
                            <p className="mt-0.5 whitespace-pre-wrap rounded border border-dashed border-border px-2 py-1 text-[11px]">
                                {rejected}
                            </p>
                        </div>
                    )}
                </RunDetails>
                {isAdmin && <AskManager run={run} conversationId={conversationId} />}
            </div>
        </details>
    );
}
