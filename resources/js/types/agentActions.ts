// Shopify action executions (docs/agent-actions.md, ExecutionPresenter.php).
// Every field is read defensively: PHP may send [] for an empty map and
// older rows can miss newer keys.

export type ActionStatus =
    | 'proposed'
    | 'pending_approval'
    | 'approved'
    | 'executing'
    | 'succeeded'
    | 'failed'
    | 'rejected'
    | 'expired'
    | 'unknown'
    | 'cancelled';

export type ActionRoute = 'approval' | 'unattended' | null;

export type WarningLevel = 'danger' | 'warning' | 'info';

export interface ActionWarning {
    level: WarningLevel | string;
    text: string;
    [key: string]: unknown;
}

export interface ApprovalReason {
    code: string;
    label: string;
}

export interface UserRef {
    id: number;
    name: string;
}

export interface ActionExecutionSummary {
    id: number;
    action_key: string;
    label: string;
    status: ActionStatus | string;
    route: ActionRoute | string;
    description: string | null;
    order_number: string | null;
    amount: string | null;
    currency: string | null;
    customer_email: string | null;
    conversation_id: number | null;
    conversation_subject: string | null;
    run_id: number | null;
    reply_blocking: boolean;
    money: boolean;
    reversible: boolean;
    risk: string | null;
    approval_reasons: ApprovalReason[];
    warnings: ActionWarning[];
    has_danger_warning: boolean;
    required_role: string | null;
    can_approve: boolean;
    expires_at: string | null;
    approved_by: UserRef | null;
    approved_at: string | null;
    rejected_by: UserRef | null;
    rejected_at: string | null;
    rejection_reason: string | null;
    error: string | null;
    dry_run: boolean;
    executed_at: string | null;
    completed_at: string | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface CitedEvidence {
    id: string;
    source: string;
    label: string;
    value: string;
}

export interface PreconditionCheck {
    check: string;
    ok: boolean;
    detail?: string | null;
}

export interface PreconditionSnapshot {
    at?: string | null;
    order?: Record<string, unknown> | null;
    state_error?: string | null;
    passed?: boolean | null;
    checks?: PreconditionCheck[];
    warnings?: ActionWarning[];
    amount_cents?: number | null;
    currency?: string | null;
    computed?: Record<string, unknown> | null;
    limits?: Record<string, unknown> | null;
    unavailable?: string | null;
}

export interface ResolvedPolicy {
    action_key: string;
    mode: 'always' | 'approval' | 'never' | string;
    limits: Record<string, unknown> | unknown[];
    source: string;
    forced: unknown[];
    workspace_policy_id: number | null;
    playbook_policy_id: number | null;
    playbook_id: number | null;
}

export interface ActionExecutionDetail extends ActionExecutionSummary {
    params: Record<string, unknown> | unknown[] | null;
    param_errors: Record<string, string[] | string> | unknown[] | null;
    params_edited: { before?: unknown; after?: unknown; by?: unknown; at?: string | null } | null;
    params_hint: string | null;
    reason: string | null;
    cited_evidence: CitedEvidence[];
    policy_snapshot: ResolvedPolicy | null;
    precondition_snapshot: PreconditionSnapshot | null;
    request: unknown;
    response: unknown;
    verify_result: Record<string, unknown> | null;
    undo: { how?: string; [key: string]: unknown } | null;
    reconcile_attempts: number;
    rewrite_requested: boolean;
    idempotency_key: string | null;
}

export interface ActionExecutionList {
    data: ActionExecutionSummary[];
    meta: { current_page: number; last_page: number; total: number; per_page: number };
    counts?: { pending_approval: number; unknown: number };
}

export interface ReplyGateState {
    state: 'ready' | 'waiting' | 'needs_rewrite' | string;
    blocking: number[];
    failed: number[];
    executions: { id: number; action_key: string; status: string; reply_blocking: boolean }[];
}

export interface ConversationActions {
    executions: ActionExecutionSummary[];
    latest_run: { id: number; reply: ReplyGateState; blocks_sending: boolean } | null;
}

/** `agent.action.updated` on private-workspace.{id} and private-conversation.{id}. */
export interface ActionUpdatedEvent {
    execution_id: number;
    conversation_id: number | null;
    run_id: number | null;
    action_key: string;
    status: string;
    pending_count: number;
}
