import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { PuzzleIcon, RefreshCwIcon, WrenchIcon } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { ErrorLine, ProgressBar, Spinner } from '@/Components/agent/LoopParts';
import { asArray, plural, proposalUrl, shortDate, type ApiError } from '@/lib/agentLoop';
import { QA_FAILURE_CLASS_HELP, QA_GAP_STATUS_HELP, QA_GAP_STATUS_LABELS, gapProgressText } from '@/lib/agentQa';
import { cn } from '@/lib/utils';
import type { QaCapabilityRequest, QaGap } from '@/types/agentQa';
import { EmptyState } from '../AgentParts';
import { ConversationLinks, FailureClassBadge, QA_SELECT, playbookName } from './qaParts';

const GAP_STATUS_ORDER = ['open', 'drafting', 'proposed', 'failed', 'closed'];

function gapStatusVariant(status: string): 'default' | 'secondary' | 'success' | 'destructive' | 'outline' {
    switch (status) {
        case 'drafting':
            return 'default';
        case 'proposed':
            return 'success';
        case 'failed':
            return 'destructive';
        case 'closed':
            return 'outline';
        default:
            return 'secondary';
    }
}

function RefreshButton({ loading, onReload }: { loading: boolean; onReload: () => void }) {
    return (
        <Button size="icon-sm" variant="ghost" onClick={onReload} aria-label="Refresh" title="Refresh">
            {loading ? <Spinner /> : <RefreshCwIcon className="h-3.5 w-3.5" />}
        </Button>
    );
}

// ── Gaps ─────────────────────────────────────────────────────────────────────

export function GapsTab({
    gaps,
    threshold,
    error,
    loading,
    onReload,
    onOpenCapabilities,
}: {
    gaps: QaGap[] | null;
    threshold: number;
    error: ApiError | null;
    loading: boolean;
    onReload: () => void;
    onOpenCapabilities: () => void;
}) {
    const [status, setStatus] = useState('');
    // Missing tools never become proposals: they are listed under Capability requests.
    const all = (gaps ?? []).filter((g) => g.failure_class !== 'missing_tool');
    const shown = status ? all.filter((g) => g.status === status) : all;

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <p className="max-w-3xl text-sm text-muted-foreground">
                    When full reviews keep finding the same missing playbook rule or missing knowledge, they are grouped here. Once{' '}
                    {plural(threshold, 'different conversation')} share a problem, the AI drafts a proposal (a suggested fix) for you to
                    review in Proposals. Reviews you take out do not count. Missing tools are listed under{' '}
                    <button type="button" className="text-primary hover:underline" onClick={onOpenCapabilities}>
                        Capability requests
                    </button>
                    .
                </p>
                <div className="flex items-center gap-1.5">
                    <select className={QA_SELECT} value={status} aria-label="Filter by status" onChange={(e) => setStatus(e.target.value)}>
                        <option value="">Every status</option>
                        {GAP_STATUS_ORDER.map((s) => (
                            <option key={s} value={s}>
                                {QA_GAP_STATUS_LABELS[s]}
                            </option>
                        ))}
                    </select>
                    <RefreshButton loading={loading} onReload={onReload} />
                </div>
            </div>

            <ErrorLine error={error} />

            {gaps === null ? (
                !error && <p className="text-sm text-muted-foreground">Loading…</p>
            ) : shown.length === 0 ? (
                <EmptyState
                    icon={<PuzzleIcon className="h-5 w-5 text-muted-foreground" />}
                    title={status ? 'Nothing with this status' : 'No gaps found yet'}
                >
                    {status
                        ? 'Pick another status to see the other groups.'
                        : 'Gaps appear when a full review finds a missing playbook rule or a missing fact. That is a good sign: nothing is missing so far.'}
                </EmptyState>
            ) : (
                <ul className="space-y-3">
                    {shown.map((gap) => (
                        <GapCard key={gap.id} gap={gap} threshold={threshold} />
                    ))}
                </ul>
            )}
        </div>
    );
}

function GapCard({ gap, threshold }: { gap: QaGap; threshold: number }) {
    const playbook = playbookName(gap.playbook);
    const ids = asArray<number>(gap.conversation_ids);
    return (
        <li className={cn('space-y-2 rounded-xl border border-border bg-card p-4', gap.status === 'closed' && 'opacity-75')}>
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0 space-y-1">
                    <p className="text-sm font-medium">{gap.label?.trim() || gap.topic?.replace(/_/g, ' ') || `Gap #${gap.id}`}</p>
                    <div className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                        <FailureClassBadge value={gap.failure_class} />
                        <span>{playbook ?? 'No playbook'}</span>
                        {gap.last_seen_at && <span>· last seen {shortDate(gap.last_seen_at)}</span>}
                    </div>
                </div>
                <Badge variant={gapStatusVariant(gap.status)} title={QA_GAP_STATUS_HELP[gap.status] ?? undefined}>
                    {QA_GAP_STATUS_LABELS[gap.status] ?? gap.status}
                </Badge>
            </div>
            {gap.failure_class && QA_FAILURE_CLASS_HELP[gap.failure_class] && (
                <p className="text-xs text-muted-foreground">{QA_FAILURE_CLASS_HELP[gap.failure_class]}</p>
            )}
            <div className="space-y-1">
                <p className="text-xs">{gapProgressText(gap.count, threshold, gap.status)}</p>
                {gap.status === 'open' && <ProgressBar value={threshold > 0 ? gap.count / threshold : 0} className="max-w-xs" />}
                {gap.count === 0 && (
                    <p className="text-[11px] text-muted-foreground">Every review in this group was taken out, so it no longer counts.</p>
                )}
            </div>
            {gap.status === 'failed' && (
                <p className="text-xs text-destructive">
                    {QA_GAP_STATUS_HELP.failed}
                    {gap.error ? ` (${gap.error})` : ''} It is not retried automatically.
                </p>
            )}
            {gap.status !== 'failed' && QA_GAP_STATUS_HELP[gap.status] && gap.status !== 'open' && (
                <p className="text-[11px] text-muted-foreground">{QA_GAP_STATUS_HELP[gap.status]}</p>
            )}
            {gap.proposal_id && (
                <p className="text-sm">
                    <Link href={proposalUrl(gap.proposal_id)} className="text-primary hover:underline">
                        Open the proposal →
                    </Link>
                </p>
            )}
            <ConversationLinks ids={ids} label="Seen in" />
        </li>
    );
}

// ── Capability requests ──────────────────────────────────────────────────────

export function CapabilitiesTab({
    requests,
    error,
    loading,
    onReload,
}: {
    requests: QaCapabilityRequest[] | null;
    error: ApiError | null;
    loading: boolean;
    onReload: () => void;
}) {
    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <p className="max-w-3xl text-sm text-muted-foreground">
                    Things customers needed that the agent has no tool for. These never become proposals — they&apos;re a wish list for new
                    features. Most requested first.
                </p>
                <RefreshButton loading={loading} onReload={onReload} />
            </div>

            <ErrorLine error={error} />

            {requests === null ? (
                !error && <p className="text-sm text-muted-foreground">Loading…</p>
            ) : requests.length === 0 ? (
                <EmptyState icon={<WrenchIcon className="h-5 w-5 text-muted-foreground" />} title="No capability requests yet">
                    When a review finds the agent would have needed a tool it does not have (for example changing an address on an order),
                    it shows up here.
                </EmptyState>
            ) : (
                <ul className="space-y-3">
                    {requests.map((req) => (
                        <CapabilityCard key={req.id} request={req} />
                    ))}
                </ul>
            )}
        </div>
    );
}

function CapabilityCard({ request }: { request: QaCapabilityRequest }) {
    const playbook = playbookName(request.playbook);
    const fixes = asArray<string>(request.suggested_fixes).filter((f) => typeof f === 'string' && f.trim());
    return (
        <li className="space-y-2 rounded-xl border border-border bg-card p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0 space-y-1">
                    <p className="text-sm font-medium">
                        {request.label?.trim() || request.topic?.replace(/_/g, ' ') || `Request #${request.id}`}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {playbook ?? 'No playbook'}
                        {request.first_seen_at && ` · first seen ${shortDate(request.first_seen_at)}`}
                        {request.last_seen_at && ` · last seen ${shortDate(request.last_seen_at)}`}
                    </p>
                </div>
                <Badge variant="secondary">{plural(request.count, 'conversation')}</Badge>
            </div>
            {fixes.length > 0 && (
                <div>
                    <p className="text-xs font-medium text-muted-foreground">What the reviews suggested</p>
                    <ul className="list-disc space-y-0.5 pl-4 text-sm">
                        {fixes.map((fix, i) => (
                            <li key={i}>{fix}</li>
                        ))}
                    </ul>
                </div>
            )}
            <ConversationLinks ids={asArray<number>(request.conversation_ids)} label="Seen in" />
        </li>
    );
}
