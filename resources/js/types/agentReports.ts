// Payloads of the AI performance report (docs/agent-qa.md "Report"), the QA
// aggregates it is read beside (docs/agent-qa.md `GET /agent/qa/aggregates`)
// and the shadow report (docs/agent-autosend.md "Shadow report").
// PHP encodes empty maps as [], so every map is typed as `Record | unknown[]`
// and read through asRecord().

export type ReportGroup = 'day' | 'week';

export type QaAuthorType = 'ai' | 'ai_edited' | 'human';

export type MaybeMap<T> = Record<string, T> | unknown[];

export interface HandoverReasonCount {
    reason: string;
    label: string;
    count: number;
}

export interface QaAuthorRow {
    triaged: number;
    triage_avg: number | null;
    reviewed: number;
    review_avg: number | null;
}

export interface PerformanceRow {
    period_start: string | null;
    playbook_id?: number | null;
    playbook_slug?: string | null;
    playbook_name?: string | null;
    /** null in playbook rows and when filtered by playbook */
    conversations_received: number | null;
    drafts_produced: number;
    conversations_with_draft: number;
    conversations_sent_as_is: number;
    automation_rate: number | null;
    auto_sent: number;
    auto_sent_rate: number | null;
    handovers: number;
    handover_reasons: HandoverReasonCount[];
    feedback: { sent_as_is: number; sent_edited: number; gmail_reply: number; rejected: number };
    edit_rate: number | null;
    avg_percent_changed: number | null;
    gmail_reply_rate: number | null;
    qa: {
        by_author_type: MaybeMap<QaAuthorRow>;
        failure_classes: MaybeMap<number>;
    };
    cost: {
        total_micro_usd: number;
        total_usd: number;
        by_role: MaybeMap<number>;
        by_purpose: MaybeMap<number>;
    };
    resolved_conversations: number | null;
    cost_per_resolved_usd: number | null;
    live_cost_per_resolved_usd: number | null;
    time_to_first_draft: { count: number; avg_seconds: number | null; median_seconds: number | null };
    /** Live runs the gate decided to auto-send (also counted when the send was later cancelled). */
    auto_send_decisions?: number;
    /** Live Shopify action executions proposed in the period (current status). */
    actions?: PerformanceActions;
}

export interface PerformanceActions {
    proposed: number;
    approved: number;
    unattended: number;
    succeeded: number;
    failed: number;
    rejected: number;
    /** Sum of succeeded refunds / refunding cancellations, shop currency. */
    refunded_amount: number;
}

export interface PerformanceReportPayload {
    from: string;
    to: string;
    group: ReportGroup;
    playbook_id: number | null;
    periods: PerformanceRow[];
    totals: PerformanceRow;
    playbooks: PerformanceRow[];
    definitions: MaybeMap<string>;
}

export interface QaAggregateAuthorRow {
    author_type: QaAuthorType | string;
    reviews: number;
    triaged: number;
    triage_avg: number | null;
    reviewed: number;
    review_avg: number | null;
    sampled: number;
    sample_avg: number | null;
}

/** Only the parts of GET /agent/qa/aggregates the report reads. */
export interface QaAggregatesPayload {
    from: string;
    to: string;
    by_author_type: QaAggregateAuthorRow[];
    excluded_by_human?: number;
}

// ── Shadow report ────────────────────────────────────────────────────────────

export interface ShadowPlaybookRef {
    id: number;
    slug: string;
    name: string;
    mode: 'off' | 'draft' | 'auto' | string;
    rollout_percent: number | null;
}

export interface ShadowPlaybookRow {
    playbook: ShadowPlaybookRef | null;
    runs: number;
    drafts: number;
    handovers: number;
    would_auto_send: number;
    eligible_if_auto: number;
    compared: number;
    agreed: number;
    agreement_rate: number | null;
    avg_score: number | null;
    would_auto_send_compared: number;
    would_auto_send_agreed: number;
    would_auto_send_agreement_rate: number | null;
}

export interface ShadowJudge {
    outcome_match: number | null;
    policy_consistency: number | null;
    factual_grounding: number | null;
    tone_match: number | null;
    would_send: boolean | null;
    reason: string | null;
}

export interface ShadowDisagreement {
    comparison_id: number;
    run_id: number;
    conversation_id: number | null;
    playbook_id: number | null;
    would_auto_send: boolean;
    score: number | null;
    judge: ShadowJudge | unknown[] | null;
    ai_decision: string | null;
    ai_handover_reason: string | null;
    ai_reply: string | null;
    human_reply: string | null;
    created_at: string | null;
}

export interface ShadowReportPayload {
    window_days: number;
    since: string;
    totals: {
        shadow_runs: number;
        would_auto_send: number;
        eligible_if_auto: number;
        compared: number;
        agreed: number;
        agreement_rate: number | null;
        would_auto_send_compared?: number;
        would_auto_send_agreed?: number;
        would_auto_send_agreement_rate?: number | null;
        skipped: number;
        failed: number;
        cost_micro_usd: number;
        cost_usd: number;
    };
    playbooks: ShadowPlaybookRow[];
    worst_disagreements: ShadowDisagreement[];
    sampling: { sample_rate: number; daily_cap: number };
}
