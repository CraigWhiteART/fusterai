import { useCallback, useEffect, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { InboxIcon, ShieldOffIcon } from 'lucide-react';
import ActionCard from '@/Components/agent/ActionCard';
import { ErrorLine, Spinner } from '@/Components/agent/LoopParts';
import { Button } from '@/Components/ui/button';
import { actionsApi, refreshPendingActionCount } from '@/lib/agentActions';
import {
    AGENT_SCREEN_PATHS,
    asArray,
    plural,
    queryParam,
    setQueryParam,
    toApiError,
    usePolling,
    useWorkspaceEvents,
    type ApiError,
} from '@/lib/agentLoop';
import { isAdminRole } from '@/lib/roles';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import type { ActionExecutionList, ActionExecutionSummary } from '@/types/agentActions';
import { AgentPage, EmptyState } from '../AgentParts';

const TABS = [
    { key: 'pending_approval', label: 'Waiting for approval', count: 'pending_approval' as const },
    { key: 'unknown', label: 'Being checked', count: 'unknown' as const },
    { key: 'open', label: 'In progress' },
    { key: 'all', label: 'All' },
] as const;

type TabKey = (typeof TABS)[number]['key'];

const EMPTY_TEXT: Record<TabKey, string> = {
    pending_approval: 'Nothing is waiting for approval.',
    unknown: 'Nothing is waiting to be confirmed with Shopify.',
    open: 'Nothing is in progress.',
    all: 'The agent has not proposed any Shopify actions yet.',
};

function initialTab(): TabKey {
    const value = queryParam('status');
    return (TABS.find((t) => t.key === value)?.key ?? 'pending_approval') as TabKey;
}

/** Approvals queue: Shopify actions the agent proposed, soonest expiry first. */
export default function ActionsIndex() {
    const { auth } = usePage<PageProps>().props;
    const isAdmin = isAdminRole(auth?.user?.role);
    const [tab, setTab] = useState<TabKey>(initialTab);
    const [page, setPage] = useState(() => Number(queryParam('page')) || 1);
    const [list, setList] = useState<ActionExecutionList | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [loading, setLoading] = useState(true);

    const load = useCallback(async () => {
        try {
            const next = await actionsApi.executions({ status: tab, page, per_page: 25 });
            setList(next);
            setError(null);
        } catch (e) {
            setError(toApiError(e));
        } finally {
            setLoading(false);
        }
    }, [tab, page]);

    useEffect(() => {
        setLoading(true);
        void load();
    }, [load]);

    const refreshAll = useCallback(() => {
        void load();
        void refreshPendingActionCount(true);
    }, [load]);

    const realtime = useWorkspaceEvents({ actionUpdated: refreshAll });
    usePolling(refreshAll, realtime ? 30_000 : 10_000, true);

    function selectTab(next: TabKey) {
        setTab(next);
        setPage(1);
        setQueryParam('status', next === 'pending_approval' ? null : next);
        setQueryParam('page', null);
    }

    function goTo(next: number) {
        setPage(next);
        setQueryParam('page', next > 1 ? next : null);
    }

    const forbidden = error?.status === 403;
    const rows = asArray<ActionExecutionSummary>(list?.data);
    const meta = list?.meta;

    return (
        <AgentPage
            title="Approvals"
            headTitle="Action approvals"
            current={AGENT_SCREEN_PATHS.actions}
            width="max-w-4xl"
            description="Shopify actions the agent wants to take (refunds, cancellations, address changes, notes…). Nothing happens in Shopify until someone with the right role approves it."
            actions={
                isAdmin ? (
                    <>
                        <Button asChild variant="outline" size="sm">
                            <Link href={AGENT_SCREEN_PATHS.actionPolicies}>What it may do</Link>
                        </Button>
                        <Button asChild variant="outline" size="sm">
                            <Link href={AGENT_SCREEN_PATHS.actionAudit}>History</Link>
                        </Button>
                    </>
                ) : undefined
            }
        >
            {forbidden ? (
                <EmptyState icon={<ShieldOffIcon className="h-5 w-5 text-muted-foreground" />} title="Your role can’t approve actions">
                    Ask an admin if you should be able to approve the agent’s Shopify actions.
                </EmptyState>
            ) : (
                <>
                    <div className="flex flex-wrap gap-1 border-b border-border" role="tablist">
                        {TABS.map((t) => {
                            const count = 'count' in t ? list?.counts?.[t.count] : undefined;
                            return (
                                <button
                                    key={t.key}
                                    type="button"
                                    role="tab"
                                    aria-selected={tab === t.key}
                                    onClick={() => selectTab(t.key)}
                                    className={cn(
                                        '-mb-px border-b-2 px-3 py-2 text-sm transition-colors',
                                        tab === t.key
                                            ? 'border-primary font-medium text-foreground'
                                            : 'border-transparent text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {t.label}
                                    {count ? (
                                        <span
                                            className={cn(
                                                'ml-1.5 rounded-full px-1.5 text-[10px] font-semibold tabular-nums',
                                                t.key === 'unknown' ? 'bg-destructive/10 text-destructive' : 'bg-primary/10 text-primary',
                                            )}
                                        >
                                            {count}
                                        </span>
                                    ) : null}
                                </button>
                            );
                        })}
                    </div>

                    {tab === 'unknown' && rows.length > 0 && (
                        <p className="text-sm text-muted-foreground">
                            Shopify did not answer clearly for these, so we keep checking the order automatically. Don’t redo them by hand
                            until they show Done or Failed.
                        </p>
                    )}

                    <ErrorLine error={error} />

                    {loading && !list ? (
                        <p className="flex items-center gap-2 text-sm text-muted-foreground">
                            <Spinner /> Loading…
                        </p>
                    ) : rows.length === 0 ? (
                        <EmptyState icon={<InboxIcon className="h-5 w-5 text-muted-foreground" />} title={EMPTY_TEXT[tab]}>
                            {tab === 'pending_approval' &&
                                'When the agent suggests a refund, cancellation or other order change that needs a person, it appears here and in the conversation.'}
                        </EmptyState>
                    ) : (
                        <div className="space-y-3">
                            {meta && (
                                <p className="text-xs text-muted-foreground">
                                    {plural(meta.total, 'action')}
                                    {tab === 'pending_approval' ? ', soonest to expire first' : ''}
                                </p>
                            )}
                            {rows.map((e) => (
                                <ActionCard
                                    key={e.id}
                                    execution={e}
                                    showConversation
                                    defaultOpen={e.status === 'pending_approval' || e.status === 'unknown'}
                                    onChanged={refreshAll}
                                />
                            ))}
                            {meta && meta.last_page > 1 && (
                                <div className="flex items-center justify-between pt-2 text-sm">
                                    <Button type="button" variant="outline" size="sm" disabled={page <= 1} onClick={() => goTo(page - 1)}>
                                        Previous
                                    </Button>
                                    <span className="text-xs text-muted-foreground">
                                        Page {meta.current_page} of {meta.last_page}
                                    </span>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        disabled={page >= meta.last_page}
                                        onClick={() => goTo(page + 1)}
                                    >
                                        Next
                                    </Button>
                                </div>
                            )}
                        </div>
                    )}
                </>
            )}
        </AgentPage>
    );
}
