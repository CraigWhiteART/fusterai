// Client for the feedback-loop / proposals / simulation / mining JSON APIs
// (docs/agent-proposals.md, docs/agent-mining.md), plus shared labels and
// small hooks used by the inbox feedback box and the /agent review screens.

import { useCallback, useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import type { AxiosError } from 'axios';
import type { PageProps } from '@/types';
import type { ActionUpdatedEvent } from '@/types/agentActions';
import type {
    CasesPreparedEvent,
    ConflictResolution,
    EvidenceQuality,
    Feedback,
    FeedbackRoutedEvent,
    FeedbackScope,
    FeedbackTarget,
    FrozenCase,
    FrozenCaseList,
    MiningEstimate,
    MiningIndex,
    MiningOptions,
    MiningRunDetail,
    Paginated,
    PreviewReadyEvent,
    Proposal,
    ProposalList,
    ProposalStatus,
    ProposalUpdatedEvent,
    Simulation,
    SimulationEstimate,
    SimulationFilters,
    SimulationProgressEvent,
    SimulationResult,
    StateConflictBody,
    TestCase,
    TestExpectation,
    Verdict,
} from '@/types/agentLoop';

// ── Screen URLs ──────────────────────────────────────────────────────────────
// Every screen has its own page route. Where a page shares its URL with the
// JSON API (/agent/proposals, /agent/simulations, /agent/mining,
// /agent/actions/policies, /agent/actions/audit) the server's `page:`
// middleware (RenderPageUnlessJson) renders the page for Inertia visits and
// plain navigations and lets axios calls (Accept: application/json / XHR)
// and `?format=csv` downloads through to the controller. Old `/agent?view=…`
// links are redirected by the Overview page.

export type AgentScreen =
    | 'proposals'
    | 'simulations'
    | 'mining'
    | 'manager'
    | 'qa'
    | 'reports'
    | 'shadow'
    | 'actions'
    | 'actionPolicies'
    | 'actionAudit';

export const AGENT_SCREEN_PATHS: Record<AgentScreen, string> = {
    proposals: '/agent/proposals',
    simulations: '/agent/simulations',
    mining: '/agent/mining',
    manager: '/agent/manager',
    qa: '/agent/qa',
    reports: '/agent/reports',
    shadow: '/agent/shadow',
    actions: '/agent/actions',
    actionPolicies: '/agent/actions/policies',
    actionAudit: '/agent/actions/audit',
};

/** Old `/agent?view=…` links (before the screens had routes) → screen. */
export const LEGACY_VIEW_SCREENS: Record<string, AgentScreen> = {
    proposals: 'proposals',
    simulations: 'simulations',
    mining: 'mining',
    manager: 'manager',
    qa: 'qa',
    reports: 'reports',
    shadow: 'shadow',
    actions: 'actions',
    'action-policies': 'actionPolicies',
    'action-audit': 'actionAudit',
};

export function agentScreenUrl(screen: AgentScreen, params: Record<string, string | number | null | undefined> = {}): string {
    const base = AGENT_SCREEN_PATHS[screen];
    const query = Object.entries(params)
        .filter(([, v]) => v !== null && v !== undefined && v !== '')
        .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`)
        .join('&');
    if (!query) return base;
    return base.includes('?') ? `${base}&${query}` : `${base}?${query}`;
}

export function proposalUrl(id: number): string {
    return agentScreenUrl('proposals', { id });
}

export function conversationUrl(id: number): string {
    return `/conversations/${id}`;
}

/** Read one query-string value of the current page. */
export function queryParam(name: string): string | null {
    try {
        return new URLSearchParams(window.location.search).get(name);
    } catch {
        return null;
    }
}

/** Replace one query-string value without a visit (keeps the screen shareable). */
export function setQueryParam(name: string, value: string | number | null): void {
    try {
        const url = new URL(window.location.href);
        if (value === null || value === '') url.searchParams.delete(name);
        else url.searchParams.set(name, String(value));
        window.history.replaceState(window.history.state, '', url.toString());
    } catch {
        // history unavailable: the screen still works, the URL just does not follow
    }
}

// ── Errors ───────────────────────────────────────────────────────────────────

export interface ApiError {
    status: number | null;
    message: string;
    reason: string | null;
    errors: Record<string, string[]>;
    body: StateConflictBody | null;
}

const REASON_TEXT: Record<string, string> = {
    not_open: 'This proposal is already closed.',
    stale: 'The live instructions changed since this was written. Rebase it to apply it to the current version.',
    gate_running: 'Tests are still running. Wait for them to finish (or pause them) before approving.',
    unchecked: 'The conflict check has not run yet.',
    blocking_conflicts: 'Resolve the conflicts marked "must resolve" first.',
    not_applied: 'This proposal was never applied, so there is nothing to undo.',
    moved: 'Something changed these instructions again after this was applied. Restore an older version from the Voice or Playbook page instead.',
    rebase_failed: 'Part of this change no longer fits the current instructions.',
};

export function toApiError(error: unknown): ApiError {
    const e = error as AxiosError<StateConflictBody>;
    const status = e?.response?.status ?? null;
    const body = (e?.response?.data && typeof e.response.data === 'object' ? e.response.data : null) as StateConflictBody | null;
    const reason = typeof body?.reason === 'string' ? body.reason : null;
    const errors = body?.errors && typeof body.errors === 'object' ? body.errors : {};
    // Op errors arrive as "changes.3.ops.0: text is required.": the path means nothing to a reader.
    const firstError = Object.values(errors)
        .flat()[0]
        ?.replace(/^changes\.\d+(?:\.ops\.\d+)?:\s*/, '')
        .replace(/^\w/, (c) => c.toUpperCase());
    let message =
        (reason && REASON_TEXT[reason]) ||
        firstError ||
        (typeof body?.message === 'string' && body.message) ||
        (status === 403
            ? 'You do not have permission to do that.'
            : status === 404
              ? 'Not found (it may have been removed).'
              : status === 429
                ? 'Too many requests. Wait a minute and try again.'
                : status && status >= 500
                  ? 'The server had a problem. Try again in a moment.'
                  : 'Could not reach the server. Check your connection and try again.');
    if (reason === 'rebase_failed' && firstError) message = `${REASON_TEXT.rebase_failed} ${firstError}`;
    return { status, message, reason, errors, body };
}

// ── API ──────────────────────────────────────────────────────────────────────

async function get<T>(url: string, params?: Record<string, unknown>): Promise<T> {
    const { data } = await window.axios.get<T>(url, { params });
    return data;
}

async function post<T>(url: string, body?: unknown): Promise<T> {
    const { data } = await window.axios.post<T>(url, body ?? {});
    return data;
}

async function patch<T>(url: string, body?: unknown): Promise<T> {
    const { data } = await window.axios.patch<T>(url, body ?? {});
    return data;
}

function clean(params: object): Record<string, unknown> {
    return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== ''));
}

export const loopApi = {
    // Feedback
    submitFeedback: (
        conversationId: number,
        body: { run_id: number; text: string; selected_text?: string | null; scope_override?: FeedbackScope | null } & FeedbackTarget,
    ) => post<{ feedback: Feedback }>(`/agent/conversations/${conversationId}/feedback`, clean(body)).then((r) => r.feedback),
    feedback: (id: number) => get<{ feedback: Feedback }>(`/agent/feedback/${id}`).then((r) => r.feedback),
    setScope: (id: number, scope: FeedbackScope, target: FeedbackTarget = {}) =>
        post<{ feedback: Feedback }>(`/agent/feedback/${id}/scope`, clean({ scope, ...target })).then((r) => r.feedback),
    answer: (id: number, answer: string) =>
        post<{ feedback: Feedback }>(`/agent/feedback/${id}/answer`, { answer }).then((r) => r.feedback),

    // Proposals
    proposals: (params: { status?: string; origin?: string; open?: boolean; per_page?: number; page?: number } = {}) =>
        get<ProposalList>('/agent/proposals', clean({ ...params, open: params.open ? 1 : undefined })),
    proposal: (id: number) => get<{ proposal: Proposal }>(`/agent/proposals/${id}`).then((r) => r.proposal),
    check: (id: number) => post<{ proposal: Proposal }>(`/agent/proposals/${id}/check`).then((r) => r.proposal),
    resolveConflict: (id: number, body: { conflict_id: string; resolution: ConflictResolution; condition?: string; note?: string }) =>
        post<{ proposal: Proposal }>(`/agent/proposals/${id}/resolve-conflict`, clean(body)).then((r) => r.proposal),
    preview: (id: number) => post<{ proposal: Proposal }>(`/agent/proposals/${id}/preview`).then((r) => r.proposal),
    gate: (id: number) => post<{ simulation: Simulation; proposal: Proposal }>(`/agent/proposals/${id}/gate`),
    /** Leave whole changes, or single ops of a change, out of an open proposal. */
    exclude: (id: number, body: { change_ids?: number[]; ops?: { change_id: number; op_index: number }[]; recheck?: boolean }) =>
        post<{ proposal: Proposal }>(`/agent/proposals/${id}/exclude`, body).then((r) => r.proposal),
    /** Put left-out items back (`excluded[].id`). */
    restoreExclusions: (id: number, ids: string[], recheck = true) =>
        post<{ proposal: Proposal }>(`/agent/proposals/${id}/exclusions/restore`, { ids, recheck }).then((r) => r.proposal),
    /** Reword one item: title / body of new knowledge, or if / then of a playbook rule. */
    editChange: (
        id: number,
        changeId: number,
        body: { op_index?: number; title?: string | null; body?: string; if?: string | null; then?: string; recheck?: boolean },
    ) => post<{ proposal: Proposal }>(`/agent/proposals/${id}/changes/${changeId}`, body).then((r) => r.proposal),
    approve: (id: number, note?: string) =>
        post<{ proposal: Proposal }>(`/agent/proposals/${id}/approve`, clean({ note })).then((r) => r.proposal),
    reject: (id: number, note?: string) =>
        post<{ proposal: Proposal }>(`/agent/proposals/${id}/reject`, clean({ note })).then((r) => r.proposal),
    rebase: (id: number) => post<{ proposal: Proposal }>(`/agent/proposals/${id}/rebase`).then((r) => r.proposal),
    revert: (id: number) => post<{ proposal: Proposal }>(`/agent/proposals/${id}/revert`).then((r) => r.proposal),

    // Test cases
    testCases: (
        params: { playbook_id?: number; status?: string; pinned?: boolean; source?: string; per_page?: number; page?: number } = {},
    ) =>
        get<Paginated<TestCase>>(
            '/agent/test-cases',
            clean({ ...params, pinned: params.pinned === undefined ? undefined : params.pinned ? 1 : 0 }),
        ),
    testCase: (id: number) => get<{ test_case: TestCase }>(`/agent/test-cases/${id}`).then((r) => r.test_case),
    createTestCase: (body: {
        frozen_case_id?: number;
        conversation_id?: number;
        cut_thread_id?: number;
        name: string;
        playbook_id?: number | null;
        pinned?: boolean;
        status?: string;
        expectation?: Partial<TestExpectation>;
    }) => post<{ test_case: TestCase }>('/agent/test-cases', clean(body)).then((r) => r.test_case),
    updateTestCase: (
        id: number,
        body: Partial<{ name: string; playbook_id: number | null; pinned: boolean; status: string; expectation: Partial<TestExpectation> }>,
    ) => patch<{ test_case: TestCase }>(`/agent/test-cases/${id}`, body).then((r) => r.test_case),
    archiveTestCase: (id: number) => window.axios.delete<{ test_case: TestCase }>(`/agent/test-cases/${id}`).then((r) => r.data.test_case),
    runPlaybookTests: (playbookId: number) =>
        post<{ simulation: Simulation }>(`/agent/playbooks/${playbookId}/tests/run`).then((r) => r.simulation),

    // Simulations
    estimateSimulation: (filters: SimulationFilters) =>
        post<{ estimate: SimulationEstimate; frozen_case_ids: number[] }>('/agent/simulations/estimate', clean({ ...filters })),
    createSimulation: (filters: SimulationFilters & { budget_usd?: number | null }) =>
        post<{ simulation: Simulation }>('/agent/simulations', clean({ ...filters })).then((r) => r.simulation),
    simulations: (params: { kind?: string; proposal_id?: number; per_page?: number; page?: number } = {}) =>
        get<Paginated<Simulation>>('/agent/simulations', clean(params)),
    simulation: (id: number) => get<{ simulation: Simulation }>(`/agent/simulations/${id}`).then((r) => r.simulation),
    simulationResults: (id: number, params: { verdict?: Verdict | ''; per_page?: number; page?: number } = {}) =>
        get<Paginated<SimulationResult>>(`/agent/simulations/${id}/results`, clean(params)),
    pauseSimulation: (id: number) => post<{ simulation: Simulation }>(`/agent/simulations/${id}/pause`).then((r) => r.simulation),
    resumeSimulation: (id: number) => post<{ simulation: Simulation }>(`/agent/simulations/${id}/resume`).then((r) => r.simulation),
    cancelSimulation: (id: number) => post<{ simulation: Simulation }>(`/agent/simulations/${id}/cancel`).then((r) => r.simulation),
    overrideResult: (simulationId: number, resultId: number, verdict: 'pass' | 'fail' | null, note?: string) =>
        patch<{ result: SimulationResult }>(`/agent/simulations/${simulationId}/results/${resultId}`, { verdict, note: note || null }).then(
            (r) => r.result,
        ),

    // Frozen cases
    frozenCases: (
        params: {
            evidence_quality?: EvidenceQuality;
            source?: string;
            playbook?: string;
            unprepared?: boolean;
            per_page?: number;
            page?: number;
        } = {},
    ) => get<FrozenCaseList>('/agent/frozen-cases', clean({ ...params, unprepared: params.unprepared ? 1 : undefined })),
    frozenCase: (id: number) => get<{ frozen_case: FrozenCase }>(`/agent/frozen-cases/${id}`).then((r) => r.frozen_case),
    buildFrozenCases: (body: {
        date_from?: string;
        date_to?: string;
        imported_only?: boolean;
        limit?: number;
        per_conversation?: number;
    }) => post<{ queued: boolean }>('/agent/frozen-cases/build', clean(body)),
    prepareFrozenCases: (ids?: number[]) =>
        post<{ queued: boolean; cases?: number; per_minute?: number }>('/agent/frozen-cases/prepare', ids ? { frozen_case_ids: ids } : {}),

    // Mining
    miningIndex: () => get<MiningIndex>('/agent/mining'),
    miningEstimate: (options: MiningOptions) =>
        post<{ estimate: MiningEstimate }>('/agent/mining/estimate', clean({ ...options })).then((r) => r.estimate),
    startMining: (options: MiningOptions) => post<{ run: MiningRunDetail }>('/agent/mining', clean({ ...options })).then((r) => r.run),
    miningRun: (id: number) => get<{ run: MiningRunDetail }>(`/agent/mining/${id}`).then((r) => r.run),
    miningAction: (id: number, action: 'pause' | 'resume' | 'cancel', budgetUsd?: number | null) =>
        post<{ run: MiningRunDetail }>(`/agent/mining/${id}/${action}`, clean({ budget_usd: budgetUsd })).then((r) => r.run),
};

// ── Labels ───────────────────────────────────────────────────────────────────

export const SCOPE_LABELS: Record<FeedbackScope, string> = {
    one_off: 'This reply only',
    voice: 'Brand voice',
    playbook: 'This playbook',
    knowledge: 'A knowledge fact',
    example: 'An example reply',
};

export const SCOPE_HELP: Record<FeedbackScope, string> = {
    one_off: 'Re-write just this draft. Nothing is saved for next time.',
    voice: 'How the agent writes every reply (wording, tone, greetings, sign-offs).',
    playbook: 'What the agent does in this kind of situation.',
    knowledge: 'A fact or policy the agent quotes (shipping times, warranty, returns…).',
    example: 'A past reply the agent copies from.',
};

export const SCOPE_ORDER: FeedbackScope[] = ['one_off', 'voice', 'playbook', 'knowledge', 'example'];

export const PROPOSAL_STATUS_LABELS: Record<string, string> = {
    draft: 'Waiting for review',
    testing: 'Running tests',
    ready: 'Ready to approve',
    approved: 'Approved',
    applied: 'Live',
    rejected: 'Rejected',
    stale: 'Out of date',
    reverted: 'Undone',
};

export const OPEN_PROPOSAL_STATUSES: ProposalStatus[] = ['draft', 'testing', 'ready', 'stale'];

export function isOpenProposal(status: string | null | undefined): boolean {
    return !!status && (OPEN_PROPOSAL_STATUSES as string[]).includes(status);
}

export function proposalStatusVariant(status: string | null | undefined): 'default' | 'secondary' | 'success' | 'destructive' | 'outline' {
    switch (status) {
        case 'applied':
        case 'ready':
            return 'success';
        case 'rejected':
            return 'destructive';
        case 'stale':
        case 'reverted':
            return 'outline';
        case 'testing':
            return 'default';
        default:
            return 'secondary';
    }
}

export const ORIGIN_LABELS: Record<string, string> = {
    feedback: 'Your feedback',
    manager: 'Manager chat',
    qa: 'Quality check',
    mining: 'Learned from history',
};

export const TARGET_TYPE_LABELS: Record<string, string> = {
    voice: 'Brand voice',
    playbook: 'Playbook',
    knowledge: 'Knowledge',
    example: 'Example reply',
    test_case: 'Test case',
};

export const VERDICT_LABELS: Record<Verdict, string> = {
    pass: 'Pass',
    fail: 'Fail',
    regressed: 'Got worse',
    improved: 'Got better',
    changed: 'Changed (noise)',
    error: 'Error',
};

export const VERDICT_HELP: Record<Verdict, string> = {
    pass: 'The reply met the expectations and the judge would send it.',
    fail: 'The reply missed an expectation or the judge would not send it.',
    regressed: 'Passed before this change and fails with it.',
    improved: 'Failed before this change and passes with it.',
    changed: 'The result flipped once but a re-run agreed with before: probably random variation.',
    error: 'The check could not be scored (for example the judge call failed).',
};

export function verdictVariant(verdict: string | null | undefined): 'default' | 'secondary' | 'success' | 'destructive' | 'outline' {
    switch (verdict) {
        case 'pass':
        case 'improved':
            return 'success';
        case 'fail':
        case 'regressed':
            return 'destructive';
        case 'changed':
            return 'outline';
        default:
            return 'secondary';
    }
}

export const EVIDENCE_LABELS: Record<EvidenceQuality, string> = {
    recorded: 'Recorded',
    reconstructed: 'Reconstructed',
    none: 'No order data',
};

export const EVIDENCE_HELP: Record<EvidenceQuality, string> = {
    recorded: 'We have the exact order and tracking details the person saw when they replied, so facts are scored strictly.',
    reconstructed:
        'Order details were rebuilt from Shopify as they probably looked at the time. They may be slightly off, so wrong facts are not held against the AI.',
    none: 'No order or tracking details exist for this old ticket, so the AI is not marked down for facts it could not know. Tone and policy are still scored.',
};

export const CONFLICT_TYPE_LABELS: Record<string, string> = {
    contradiction: 'Contradicts an existing instruction',
    duplicate: 'Already exists',
    subsumes: 'Overlaps an existing instruction',
    do_dont: 'Same phrase is both a "do" and a "don\'t"',
    action_never: 'Offers an action the store never allows',
    action_not_allowed: 'Offers an action this playbook is not allowed to take',
    check_failed: 'The automatic check could not run',
};

export const RESOLUTION_LABELS: Record<string, string> = {
    keep_old: 'Keep the old one (drop this change)',
    replace_old: 'Replace the old one',
    scope_new: 'Only apply the new one when…',
    keep_both: 'Keep both',
    acknowledge: 'OK, carry on',
    retire: 'Stop using this example',
    keep: 'Keep the example',
};

export const SIMULATION_STATUS_LABELS: Record<string, string> = {
    pending: 'Queued',
    running: 'Running',
    paused: 'Paused',
    completed: 'Finished',
    cancelled: 'Cancelled',
    budget_exhausted: 'Stopped: budget used up',
    failed: 'Failed',
};

export function isSimulationActive(status: string | null | undefined): boolean {
    return status === 'pending' || status === 'running';
}

export function usd(value: number | null | undefined): string {
    if (value === null || value === undefined || Number.isNaN(value)) return '—';
    if (value > 0 && value < 0.01) return `$${value.toFixed(4)}`;
    return `$${value.toFixed(2)}`;
}

export function microToUsd(micro: number | null | undefined): number | null {
    return typeof micro === 'number' ? micro / 1_000_000 : null;
}

export function pct(value: number | null | undefined): string {
    return value === null || value === undefined || Number.isNaN(value) ? '—' : `${Math.round(value * 100)}%`;
}

export function shortDate(value: string | null | undefined): string {
    if (!value) return '';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? '' : d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

/** PHP encodes an empty map as []: read it as {}. */
export function asRecord<T>(value: Record<string, T> | unknown[] | null | undefined): Record<string, T> {
    return value && !Array.isArray(value) && typeof value === 'object' ? value : {};
}

export function asArray<T>(value: T[] | null | undefined | unknown): T[] {
    return Array.isArray(value) ? (value as T[]) : [];
}

export function plural(n: number, one: string, many = `${one}s`): string {
    return `${n} ${n === 1 ? one : many}`;
}

// ── Hooks ────────────────────────────────────────────────────────────────────

export interface WorkspaceEventHandlers {
    feedbackRouted?: (e: FeedbackRoutedEvent) => void;
    previewReady?: (e: PreviewReadyEvent) => void;
    proposalUpdated?: (e: ProposalUpdatedEvent) => void;
    simulationProgress?: (e: SimulationProgressEvent) => void;
    simulationFinished?: (e: SimulationProgressEvent) => void;
    casesPrepared?: (e: CasesPreparedEvent) => void;
    /** Shopify action executions (docs/agent-actions.md). */
    actionUpdated?: (e: ActionUpdatedEvent) => void;
}

const EVENT_NAMES: Record<keyof WorkspaceEventHandlers, string> = {
    feedbackRouted: '.agent.feedback.routed',
    previewReady: '.agent.preview.ready',
    proposalUpdated: '.agent.proposal.updated',
    simulationProgress: '.agent.simulation.progress',
    simulationFinished: '.agent.simulation.finished',
    casesPrepared: '.agent.cases.prepared',
    actionUpdated: '.agent.action.updated',
};

/**
 * Listen for the agent-loop events on `private-workspace.{id}`. Uses
 * stopListening with the exact callback on unmount (never `leave`): other
 * components (AppLayout, the inbox) listen on the same channel.
 * Returns whether a realtime connection is available at all.
 */
export function useWorkspaceEvents(handlers: WorkspaceEventHandlers): boolean {
    const { auth } = usePage<PageProps>().props;
    const workspaceId = auth?.user?.workspace_id;
    const ref = useRef(handlers);
    ref.current = handlers;
    const available = typeof window !== 'undefined' && !!window.Echo && !!workspaceId;

    useEffect(() => {
        if (!window.Echo || !workspaceId) return;
        let channel: ReturnType<typeof window.Echo.private> | null = null;
        try {
            channel = window.Echo.private(`workspace.${workspaceId}`);
        } catch {
            return;
        }
        const bound = (Object.keys(EVENT_NAMES) as (keyof WorkspaceEventHandlers)[]).map((key) => {
            const cb = (e: unknown) => {
                const fn = ref.current[key] as ((payload: unknown) => void) | undefined;
                if (fn && e && typeof e === 'object') fn(e);
            };
            channel?.listen(EVENT_NAMES[key], cb);
            return [EVENT_NAMES[key], cb] as const;
        });
        return () => {
            bound.forEach(([name, cb]) => channel?.stopListening(name, cb));
        };
    }, [workspaceId]);

    return available;
}

/** Run `fn` every `ms` while `active` (a fallback when realtime events are missed). */
export function usePolling(fn: () => void, ms: number, active: boolean): void {
    const ref = useRef(fn);
    ref.current = fn;
    useEffect(() => {
        if (!active) return;
        const timer = window.setInterval(() => ref.current(), ms);
        return () => window.clearInterval(timer);
    }, [ms, active]);
}

/** A busy flag + error for one async action at a time. */
export function useAction() {
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const alive = useRef(true);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);

    const run = useCallback(async <T>(key: string, fn: () => Promise<T>): Promise<T | null> => {
        setBusy(key);
        setError(null);
        try {
            return await fn();
        } catch (e) {
            if (alive.current) setError(toApiError(e));
            return null;
        } finally {
            if (alive.current) setBusy(null);
        }
    }, []);

    return { busy, error, setError, run };
}

// Open-proposal count for the nav badge and the Overview: cached for a
// minute across Inertia visits (the module outlives page components).
let openCountCache: { value: number; at: number } | null = null;
let openCountInflight: Promise<number | null> | null = null;
const openCountListeners = new Set<(n: number | null) => void>();

export function refreshOpenProposalCount(force = false): Promise<number | null> {
    if (!force && openCountCache && Date.now() - openCountCache.at < 60_000) return Promise.resolve(openCountCache.value);
    if (openCountInflight) return openCountInflight;
    openCountInflight = loopApi
        .proposals({ open: true, per_page: 1 })
        .then((r) => {
            const value = typeof r?.meta?.total === 'number' ? r.meta.total : asArray(r?.data).length;
            openCountCache = { value, at: Date.now() };
            openCountListeners.forEach((l) => l(value));
            return value;
        })
        .catch(() => null)
        .finally(() => {
            openCountInflight = null;
        });
    return openCountInflight;
}

/** Number of proposals waiting for review (null while unknown or for non-admins). */
export function useOpenProposalCount(enabled: boolean): number | null {
    const [count, setCount] = useState<number | null>(enabled ? (openCountCache?.value ?? null) : null);
    useEffect(() => {
        if (!enabled) return;
        openCountListeners.add(setCount);
        void refreshOpenProposalCount().then((n) => n !== null && setCount(n));
        return () => {
            openCountListeners.delete(setCount);
        };
    }, [enabled]);
    useWorkspaceEvents(enabled ? { proposalUpdated: () => void refreshOpenProposalCount(true) } : {});
    return enabled ? count : null;
}
