import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { DownloadIcon, ExternalLinkIcon, LockIcon, ScrollTextIcon } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { ErrorLine, Spinner } from '@/Components/agent/LoopParts';
import {
    AGENT_SCREEN_PATHS,
    agentScreenUrl,
    asArray,
    conversationUrl,
    plural,
    queryParam,
    setQueryParam,
    shortDate,
    toApiError,
    type ApiError,
} from '@/lib/agentLoop';
import { actionStatusLabel, actionStatusVariant, money } from '@/lib/agentActions';
import { AUDIT_STATUSES, auditCsvUrl, policiesApi, routeLabel } from '@/lib/agentActionPolicies';
import type { ActionExecutionList, ActionExecutionSummary } from '@/types/agentActions';
import type { AuditFilters, CatalogueAction } from '@/types/agentActionPolicies';
import { AgentPage, EmptyState } from '../AgentParts';

const PER_PAGE = 50;

function readFilters(): AuditFilters {
    const page = Number(queryParam('page'));
    return {
        status: queryParam('status') ?? '',
        action_key: queryParam('action_key') ?? '',
        from: queryParam('from') ?? '',
        to: queryParam('to') ?? '',
        page: Number.isInteger(page) && page > 1 ? page : 1,
    };
}

function Field({ id, label, children }: { id: string; label: string; children: ReactNode }) {
    return (
        <div className="space-y-1">
            <label htmlFor={id} className="text-xs font-medium">
                {label}
            </label>
            {children}
        </div>
    );
}

function WhoCell({ row }: { row: ActionExecutionSummary }) {
    if (row.rejected_by || row.rejection_reason) {
        return (
            <span>
                Rejected{row.rejected_by ? ` by ${row.rejected_by.name}` : ''}
                {row.rejection_reason && <span className="block text-muted-foreground">“{row.rejection_reason}”</span>}
            </span>
        );
    }
    if (row.approved_by) {
        return (
            <span>
                Approved by {row.approved_by.name}
                {row.approved_at && <span className="block text-muted-foreground">{shortDate(row.approved_at)}</span>}
            </span>
        );
    }
    return <span className="text-muted-foreground">—</span>;
}

/** Admin audit log of every Shopify action the agent proposed, with filters and a CSV download. */
export default function ActionAuditPage() {
    const [filters, setFilters] = useState<AuditFilters>(readFilters);
    const [list, setList] = useState<ActionExecutionList | null>(null);
    const [actions, setActions] = useState<CatalogueAction[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<ApiError | null>(null);
    const requestId = useRef(0);

    const load = useCallback(async (f: AuditFilters) => {
        const id = ++requestId.current;
        setLoading(true);
        try {
            const result = await policiesApi.audit({ ...f, per_page: PER_PAGE });
            if (id !== requestId.current) return;
            setList(result);
            setError(null);
        } catch (e) {
            if (id === requestId.current) setError(toApiError(e));
        } finally {
            if (id === requestId.current) setLoading(false);
        }
    }, []);

    useEffect(() => {
        void load(filters);
    }, [filters, load]);

    useEffect(() => {
        let alive = true;
        policiesApi
            .catalogue()
            .then((c) => alive && setActions(asArray<CatalogueAction>(c.actions)))
            .catch(() => undefined);
        return () => {
            alive = false;
        };
    }, []);

    function change(patch: Partial<AuditFilters>) {
        const next = { ...filters, page: 1, ...patch };
        (['status', 'action_key', 'from', 'to'] as const).forEach((k) => setQueryParam(k, next[k] ?? null));
        setQueryParam('page', next.page && next.page > 1 ? next.page : null);
        setFilters(next);
    }

    const rows = asArray<ActionExecutionSummary>(list?.data);
    const meta = list?.meta;
    const labelFor = (key: string, fallback: string) => actions.find((a) => a.key === key)?.label ?? fallback ?? key;
    const filtered = !!(filters.status || filters.action_key || filters.from || filters.to);
    const csvHref = auditCsvUrl(filters);

    let body: ReactNode;
    if (error?.status === 403) {
        body = (
            <EmptyState icon={<LockIcon className="h-4 w-4" />} title="Only admins can see the action log">
                Ask the shop owner (an admin) if you need to check what the agent did in Shopify.
            </EmptyState>
        );
    } else if (!list && loading) {
        body = (
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                <Spinner /> Loading…
            </p>
        );
    } else if (!list) {
        body = (
            <div className="space-y-2">
                <ErrorLine error={error} />
                <Button size="sm" variant="outline" onClick={() => void load(filters)}>
                    Try again
                </Button>
            </div>
        );
    } else if (rows.length === 0) {
        body = (
            <EmptyState icon={<ScrollTextIcon className="h-4 w-4" />} title={filtered ? 'Nothing matches these filters' : 'No actions yet'}>
                {filtered
                    ? 'Try a wider date range or clear the filters.'
                    : 'Every refund, cancellation, address change and other Shopify action the agent asks for will be listed here, whether a person approved it or it ran on its own.'}
            </EmptyState>
        );
    } else {
        body = (
            <div className="space-y-3">
                {error && <ErrorLine error={error} />}
                <div className="overflow-x-auto rounded-xl border border-border bg-card">
                    <table className="w-full min-w-[960px] text-left text-xs">
                        <thead className="border-b border-border bg-muted/40 text-muted-foreground">
                            <tr>
                                <th className="px-3 py-2 font-medium">When</th>
                                <th className="px-3 py-2 font-medium">Action</th>
                                <th className="px-3 py-2 font-medium">Order</th>
                                <th className="px-3 py-2 font-medium">Amount</th>
                                <th className="px-3 py-2 font-medium">Status</th>
                                <th className="px-3 py-2 font-medium">How</th>
                                <th className="px-3 py-2 font-medium">Who</th>
                                <th className="px-3 py-2 font-medium">Conversation</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border">
                            {rows.map((row) => (
                                <tr key={row.id} className="align-top">
                                    <td className="whitespace-nowrap px-3 py-2">{shortDate(row.created_at)}</td>
                                    <td className="max-w-sm px-3 py-2">
                                        <p className="font-medium">{labelFor(row.action_key, row.label)}</p>
                                        {row.description && <p className="text-muted-foreground">{row.description}</p>}
                                        {row.dry_run && <p className="text-muted-foreground">Test only: nothing was sent to Shopify.</p>}
                                        {row.error && <p className="mt-0.5 text-destructive">Problem: {row.error}</p>}
                                    </td>
                                    <td className="whitespace-nowrap px-3 py-2">
                                        {row.order_number ? `#${row.order_number.replace(/^#/, '')}` : '—'}
                                    </td>
                                    <td className="whitespace-nowrap px-3 py-2 tabular-nums">{money(row.amount, row.currency) ?? '—'}</td>
                                    <td className="px-3 py-2">
                                        <Badge variant={actionStatusVariant(row.status)}>{actionStatusLabel(row.status)}</Badge>
                                        {row.completed_at && (
                                            <span className="mt-0.5 block text-muted-foreground">{shortDate(row.completed_at)}</span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">{routeLabel(row.route, row.approved_by?.name)}</td>
                                    <td className="px-3 py-2">
                                        <WhoCell row={row} />
                                    </td>
                                    <td className="px-3 py-2">
                                        {row.conversation_id ? (
                                            <Link
                                                href={conversationUrl(row.conversation_id)}
                                                className="inline-flex items-center gap-1 text-primary hover:underline"
                                            >
                                                <span className="max-w-[12rem] truncate">
                                                    {row.conversation_subject || `Conversation ${row.conversation_id}`}
                                                </span>
                                                <ExternalLinkIcon className="h-3 w-3 shrink-0" />
                                            </Link>
                                        ) : (
                                            <span className="text-muted-foreground">—</span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {meta && (
                    <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                        <span>
                            {plural(meta.total, 'action')} · page {meta.current_page} of {Math.max(1, meta.last_page)}
                        </span>
                        <div className="flex items-center gap-2">
                            {loading && <Spinner />}
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={loading || meta.current_page <= 1}
                                onClick={() => change({ page: meta.current_page - 1 })}
                            >
                                Newer
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={loading || meta.current_page >= meta.last_page}
                                onClick={() => change({ page: meta.current_page + 1 })}
                            >
                                Older
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        );
    }

    return (
        <AgentPage
            title="Shopify action log"
            headTitle="Shopify action log"
            current={AGENT_SCREEN_PATHS.actionAudit}
            width="max-w-6xl"
            description="Everything the agent asked to do in Shopify: who approved or rejected it, whether it ran on its own, and how it ended. Newest first."
            actions={
                <>
                    <Button asChild size="sm" variant="outline">
                        <Link href={agentScreenUrl('actionPolicies')}>Permissions</Link>
                    </Button>
                    <Button asChild size="sm" variant="outline">
                        <a href={csvHref} download>
                            <DownloadIcon className="h-3.5 w-3.5" /> Download CSV
                        </a>
                    </Button>
                </>
            }
        >
            <div className="grid gap-3 rounded-xl border border-border bg-card p-4 sm:grid-cols-5">
                <Field id="audit-status" label="Status">
                    <select
                        id="audit-status"
                        className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                        value={filters.status ?? ''}
                        onChange={(e) => change({ status: e.target.value })}
                    >
                        <option value="">Any status</option>
                        {AUDIT_STATUSES.map((s) => (
                            <option key={s} value={s}>
                                {actionStatusLabel(s)}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field id="audit-action" label="Action">
                    <select
                        id="audit-action"
                        className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                        value={filters.action_key ?? ''}
                        onChange={(e) => change({ action_key: e.target.value })}
                    >
                        <option value="">Any action</option>
                        {actions.map((a) => (
                            <option key={a.key} value={a.key}>
                                {a.label}
                            </option>
                        ))}
                        {filters.action_key && !actions.some((a) => a.key === filters.action_key) && (
                            <option value={filters.action_key}>{filters.action_key}</option>
                        )}
                    </select>
                </Field>
                <Field id="audit-from" label="From">
                    <Input id="audit-from" type="date" value={filters.from ?? ''} onChange={(e) => change({ from: e.target.value })} />
                </Field>
                <Field id="audit-to" label="To (including that day)">
                    <Input id="audit-to" type="date" value={filters.to ?? ''} onChange={(e) => change({ to: e.target.value })} />
                </Field>
                <div className="flex items-end">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        disabled={!filtered}
                        onClick={() => change({ status: '', action_key: '', from: '', to: '' })}
                    >
                        Clear filters
                    </Button>
                </div>
            </div>
            {body}
        </AgentPage>
    );
}
