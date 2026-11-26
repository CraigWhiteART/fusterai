// Client, labels and hooks for Shopify action executions (docs/agent-actions.md):
// the approvals queue, the conversation action cards and the nav badge.
// Money actions are never updated optimistically: every mutation is
// followed by a re-fetch of the execution.

import { useEffect, useState } from 'react';
import { asArray, useWorkspaceEvents } from '@/lib/agentLoop';
import type { ActionExecutionDetail, ActionExecutionList, ActionStatus, ConversationActions, ReplyGateState } from '@/types/agentActions';

// ── API ──────────────────────────────────────────────────────────────────────

function clean(params: object): Record<string, unknown> {
    return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== ''));
}

export const actionsApi = {
    executions: (
        params: { status?: string; action_key?: string; conversation_id?: number; route?: string; per_page?: number; page?: number } = {},
    ) => window.axios.get<ActionExecutionList>('/agent/actions/executions', { params: clean(params) }).then((r) => r.data),
    execution: (id: number) =>
        window.axios.get<{ execution: ActionExecutionDetail }>(`/agent/actions/executions/${id}`).then((r) => r.data.execution),
    /** 202 queued; 409 already decided / kill switch; 422 invalid params (`errors`) or unavailable; 403 role. */
    approve: (id: number, params?: Record<string, unknown> | null) =>
        window.axios
            .post<{
                message: string;
                execution: ActionExecutionDetail;
            }>(`/agent/actions/executions/${id}/approve`, params ? { params } : {})
            .then((r) => r.data),
    reject: (id: number, reason: string) =>
        window.axios
            .post<{ message: string; execution: ActionExecutionDetail }>(`/agent/actions/executions/${id}/reject`, { reason })
            .then((r) => r.data),
    refresh: (id: number) =>
        window.axios.post<{ execution: ActionExecutionDetail }>(`/agent/actions/executions/${id}/refresh`).then((r) => r.data.execution),
    forConversation: (conversationId: number) =>
        window.axios.get<ConversationActions>(`/agent/actions/conversations/${conversationId}`).then((r) => r.data),
    runGate: (runId: number) =>
        window.axios
            .get<{ run_id: number; reply: ReplyGateState; blocks_sending: boolean }>(`/agent/actions/runs/${runId}/gate`)
            .then((r) => r.data),
};

// ── Labels ───────────────────────────────────────────────────────────────────

export const ACTION_STATUS_LABELS: Record<ActionStatus, string> = {
    proposed: 'Proposed',
    pending_approval: 'Waiting for approval',
    approved: 'Approved, starting',
    executing: 'Running in Shopify',
    succeeded: 'Done',
    failed: 'Failed',
    rejected: 'Rejected',
    expired: 'Expired',
    unknown: 'Being checked',
    cancelled: 'Cancelled',
};

export function actionStatusLabel(status: string | null | undefined): string {
    return (status && ACTION_STATUS_LABELS[status as ActionStatus]) || status || 'Unknown';
}

export function actionStatusVariant(status: string | null | undefined): 'default' | 'secondary' | 'success' | 'destructive' | 'outline' {
    switch (status) {
        case 'succeeded':
            return 'success';
        case 'failed':
        case 'unknown':
            return 'destructive';
        case 'pending_approval':
        case 'approved':
        case 'executing':
            return 'default';
        case 'rejected':
        case 'expired':
        case 'cancelled':
            return 'outline';
        default:
            return 'secondary';
    }
}

export const ROLE_LABELS: Record<string, string> = {
    agent: 'any team member',
    manager: 'a manager or admin',
    admin: 'an admin',
    super_admin: 'the account owner',
};

export function roleLabel(role: string | null | undefined): string {
    return (role && ROLE_LABELS[role]) || role || 'an admin';
}

/** "AUD 20.00", or null when the execution carries no amount. */
export function money(amount: string | number | null | undefined, currency: string | null | undefined): string | null {
    if (amount === null || amount === undefined || amount === '') return null;
    const n = typeof amount === 'number' ? amount : Number(amount);
    const text = Number.isFinite(n) ? n.toFixed(2) : String(amount);
    return currency ? `${currency} ${text}` : text;
}

/** Plain name for a precondition check code ("not_cancelled" → "Not cancelled"). */
export function checkLabel(code: string): string {
    const text = code.replace(/[_.]+/g, ' ').trim();
    return text ? text.charAt(0).toUpperCase() + text.slice(1) : code;
}

export const OPEN_ACTION_STATUSES = ['pending_approval', 'approved', 'executing', 'unknown'];

export function isOpenAction(status: string | null | undefined): boolean {
    return !!status && OPEN_ACTION_STATUSES.includes(status);
}

// ── Pending-approvals count (nav badge) ──────────────────────────────────────
// Cached for a minute across Inertia visits, refreshed on `agent.action.updated`.
// Null while unknown or when the user may not see the queue (403).

let pendingCache: { value: number | null; at: number } | null = null;
let pendingInflight: Promise<number | null> | null = null;
const pendingListeners = new Set<(n: number | null) => void>();

function publishPending(value: number | null) {
    pendingCache = { value, at: Date.now() };
    pendingListeners.forEach((l) => l(value));
}

export function refreshPendingActionCount(force = false): Promise<number | null> {
    if (!force && pendingCache && Date.now() - pendingCache.at < 60_000) return Promise.resolve(pendingCache.value);
    if (pendingInflight) return pendingInflight;
    pendingInflight = actionsApi
        .executions({ status: 'pending_approval', per_page: 1 })
        .then((r) => {
            const value =
                typeof r?.counts?.pending_approval === 'number'
                    ? r.counts.pending_approval
                    : typeof r?.meta?.total === 'number'
                      ? r.meta.total
                      : asArray(r?.data).length;
            publishPending(value);
            return value;
        })
        .catch(() => {
            publishPending(null);
            return null;
        })
        .finally(() => {
            pendingInflight = null;
        });
    return pendingInflight;
}

/** Actions waiting for approval that this user can see (null while unknown / not allowed). */
export function usePendingActionCount(enabled: boolean): number | null {
    const [count, setCount] = useState<number | null>(enabled ? (pendingCache?.value ?? null) : null);
    useEffect(() => {
        if (!enabled) return;
        pendingListeners.add(setCount);
        void refreshPendingActionCount().then((n) => setCount(n));
        return () => {
            pendingListeners.delete(setCount);
        };
    }, [enabled]);
    // The event's pending_count is workspace-wide; re-read the visible count instead.
    useWorkspaceEvents(enabled ? { actionUpdated: () => void refreshPendingActionCount(true) } : {});
    return enabled ? count : null;
}
