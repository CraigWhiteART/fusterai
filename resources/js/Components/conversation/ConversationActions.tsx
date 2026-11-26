import { useCallback, useEffect, useRef, useState } from 'react';
import { ChevronDownIcon, ChevronRightIcon, ShoppingBagIcon } from 'lucide-react';
import ActionCard from '@/Components/agent/ActionCard';
import { actionsApi, isOpenAction, money } from '@/lib/agentActions';
import { asArray, usePolling } from '@/lib/agentLoop';
import type { ActionExecutionSummary, ConversationActions as ConversationActionsData } from '@/types/agentActions';

/** What the draft panel needs to know about actions the reply depends on. */
export interface ActionGateInfo {
    runId: number;
    state: 'ready' | 'waiting' | 'needs_rewrite' | string;
    blocksSending: boolean;
    /** Short labels of the actions the reply waits for / that did not happen. */
    waitingFor: string[];
    failed: string[];
}

function shortLabel(e: ActionExecutionSummary): string {
    const amount = money(e.amount, e.currency);
    return amount ? `${e.label} (${amount})` : e.label;
}

/**
 * Shopify action executions of one conversation (GET /agent/actions/conversations/{id}),
 * re-fetched on `agent.action.updated` (call `reload`), after a new run, and by
 * polling while something is running or being checked.
 */
export function useConversationActions(conversationId: number, runId: number | null | undefined) {
    const [data, setData] = useState<ConversationActionsData | null>(null);
    const alive = useRef(true);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);

    const reload = useCallback(async () => {
        try {
            const next = await actionsApi.forConversation(conversationId);
            if (alive.current) setData(next);
        } catch {
            // 403/404/network: no action cards; the draft panel behaves as before.
            if (alive.current) setData(null);
        }
    }, [conversationId]);

    useEffect(() => {
        void reload();
    }, [reload, runId]);

    const executions = asArray<ActionExecutionSummary>(data?.executions);
    const busy = executions.some((e) => ['approved', 'executing', 'unknown'].includes(e.status));
    usePolling(() => void reload(), busy ? 5000 : 20000, busy || executions.some((e) => e.status === 'pending_approval'));

    const latest = data?.latest_run ?? null;
    const byId = new Map(executions.map((e) => [e.id, e]));
    const gate: ActionGateInfo | null = latest
        ? {
              runId: latest.id,
              state: latest.reply?.state ?? 'ready',
              blocksSending: !!latest.blocks_sending,
              waitingFor: asArray<number>(latest.reply?.blocking).map((id) => (byId.get(id) ? shortLabel(byId.get(id)!) : `action #${id}`)),
              failed: asArray<number>(latest.reply?.failed).map((id) => (byId.get(id) ? shortLabel(byId.get(id)!) : `action #${id}`)),
          }
        : null;

    return { executions, latestRunId: latest?.id ?? null, gate, reload };
}

/** Action cards above the AI draft: this run's actions and anything still open; older ones folded away. */
export default function ConversationActions({
    executions,
    latestRunId,
    onChanged,
}: {
    executions: ActionExecutionSummary[];
    latestRunId: number | null;
    onChanged: () => void;
}) {
    const [showEarlier, setShowEarlier] = useState(false);
    if (executions.length === 0) return null;

    const current = executions.filter((e) => isOpenAction(e.status) || (latestRunId !== null && e.run_id === latestRunId));
    const earlier = executions.filter((e) => !current.includes(e));
    const pending = executions.filter((e) => e.status === 'pending_approval').length;

    return (
        <div className="max-h-[45vh] space-y-2 overflow-y-auto px-4 pt-3">
            <p className="flex items-center gap-1.5 text-xs font-semibold text-foreground">
                <ShoppingBagIcon className="h-3.5 w-3.5 text-muted-foreground" />
                Shopify actions
                {pending > 0 && (
                    <span className="rounded-full bg-primary px-1.5 text-[10px] font-semibold leading-4 text-primary-foreground tabular-nums">
                        {pending} waiting for approval
                    </span>
                )}
            </p>
            {current.map((e) => (
                <ActionCard key={e.id} execution={e} onChanged={onChanged} />
            ))}
            {earlier.length > 0 && (
                <div>
                    <button
                        type="button"
                        onClick={() => setShowEarlier((v) => !v)}
                        className="flex items-center gap-1 text-[11px] text-muted-foreground hover:text-foreground"
                    >
                        {showEarlier ? <ChevronDownIcon className="h-3 w-3" /> : <ChevronRightIcon className="h-3 w-3" />}
                        Earlier actions ({earlier.length})
                    </button>
                    {showEarlier && (
                        <div className="mt-2 space-y-2">
                            {earlier.map((e) => (
                                <ActionCard key={e.id} execution={e} onChanged={onChanged} defaultOpen={false} />
                            ))}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
