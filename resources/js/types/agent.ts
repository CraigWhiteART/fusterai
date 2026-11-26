// Types for the /agent screens and the run JSON (docs/agent-api.md).

export type AgentMode = 'off' | 'shadow' | 'draft' | 'live';
export type PlaybookMode = 'off' | 'draft' | 'auto';
export type KnowledgeStatus = 'active' | 'needs_scope' | 'draft' | 'retired';
export type KnowledgeKind = 'fact' | 'policy' | 'kb_chunk' | 'snippet';

export interface ListItem {
    id?: string;
    text: string;
}

export interface PlaybookRule {
    id?: string;
    if: string;
    then: string;
}

export interface VoiceVersion {
    id: number;
    version: number;
    personality: string;
    do_phrases: ListItem[];
    dont_phrases: ListItem[];
    greetings: ListItem[];
    sign_offs: ListItem[];
    max_words: number | null;
    length_guidance: string | null;
    is_starter: boolean;
    change_note: string | null;
    created_by: string | null;
    restored_from_id: number | null;
    created_at: string | null;
    is_live?: boolean;
}

export interface PlaybookSummary {
    id: number;
    slug: string;
    name: string;
    mode: PlaybookMode;
    rollout_percent: number;
    always_human: boolean;
    priority: number;
    live_version_id: number | null;
    live_version: number | null;
    archived_at: string | null;
    when_text?: string | null;
    rule_count?: number;
    /** Phase 8: `retesting` while an approved change is re-tested (auto-send waits). */
    auto_hold?: string | null;
    auto_enabled_at?: string | null;
    demoted_at?: string | null;
    demotion_reason?: string | null;
}

export interface PlaybookVersion {
    id: number;
    version: number;
    when_text: string;
    rules: PlaybookRule[];
    steps: ListItem[];
    allowed_actions: string[];
    required_evidence: string[];
    escalation: string | null;
    change_note: string | null;
    created_by: string | null;
    restored_from_id: number | null;
    created_at: string | null;
    is_live?: boolean;
}

export interface KnowledgeScope {
    playbooks: string[];
    products: string[];
    countries: string[];
    global: boolean;
}

export interface KnowledgeItem {
    id: number;
    kind: KnowledgeKind;
    title: string | null;
    body: string;
    scope: KnowledgeScope;
    valid_from: string | null;
    valid_until: string | null;
    status: KnowledgeStatus;
    supersedes_id: number | null;
    source_document_id: number | null;
    chunk_index: number | null;
    source_type: string | null;
    has_embedding: boolean;
    created_at: string | null;
    updated_at: string | null;
}

export interface AgentSettings {
    mode: AgentMode;
    kill_switch: boolean;
    live_voice_version_id: number | null;
    caps: {
        auto_send_per_day: number;
        auto_send_per_customer_per_day: number;
        auto_send_consecutive_per_conversation: number;
        actions_per_day: number;
    };
    auto_send_delay_seconds: number;
    link_allowlist: string[];
    auto_send: AutoSendOptions;
    paused_until: string | null;
    paused_reason: string | null;
}

// ── GET /agent/conversations/{id}/run ────────────────────────────────────────

export interface RunSource {
    id: string;
    type: 'evidence' | 'knowledge' | 'unknown';
    source?: string;
    label?: string;
    value?: string;
    kind?: string | null;
    title?: string | null;
    body?: string;
    knowledge_item_id?: number | null;
}

export interface RunClaim {
    text: string;
    sources: RunSource[];
}

export interface RunValidatorIssue {
    check: string;
    value: string;
    problem: string;
}

export interface AgentRunPayload {
    id: number;
    purpose: 'live' | 'shadow' | 'preview' | 'simulation' | 'test';
    status: 'running' | 'completed' | 'failed';
    decision: 'draft' | 'auto_send' | 'handover' | null;
    auto_send?: RunAutoSend | null;
    handover_reason: string | null;
    handover_label: string | null;
    draft: string | null;
    internal_note: string | null;
    ai_suggestion_id: number | null;
    customer_thread_id: number | null;
    playbook: { id: number; slug: string; name: string; version_id: number | null; version: number | null } | null;
    triage: {
        language: string;
        sentiment: string;
        risk_flags: string[];
        playbook: string | null;
        confidence: number | null;
        reason: string;
        error: string | null;
        heuristics: string[];
    } | null;
    rules_used: { id: string; if: string | null; then: string | null }[];
    claims: RunClaim[];
    /**
     * status: proposed | rejected_not_allowed | rejected_unknown_action | rejected_unavailable |
     * rejected_disabled | rejected_invalid_params (docs/agent-actions.md). Proposed entries of a
     * live run carry the execution they became.
     */
    proposed_actions: {
        action: string;
        order_number: string | null;
        reason: string;
        details: string | null;
        status: string;
        params?: Record<string, unknown> | unknown[] | null;
        execution_id?: number | null;
        execution_status?: string | null;
        errors?: Record<string, string[] | string> | unknown[] | null;
        unavailable?: string | null;
    }[];
    validator: {
        passed: boolean | null;
        error: boolean;
        rewrites: number;
        deterministic: RunValidatorIssue[][];
        llm: { verdict: 'pass' | 'fail'; issues: { quote: string; problem: string }[] } | { error: string } | null;
        rejected_draft: string | null;
    } | null;
    evidence: {
        facts: { id: string; source: string; key: string; label: string; value: string }[];
        notes: string[];
        guidance: string[];
        meta: Record<string, unknown>;
    };
    knowledge: { id: string; kind: string; title: string | null; body: string; scope: KnowledgeScope }[];
    tokens: { in: number; out: number; cache_read: number; cache_write: number; reasoning: number };
    cost_micro_usd: number;
    cost_usd: number;
    latency_ms: number | null;
    models: Record<string, string>;
    config_hash: string;
    error: string | null;
    feedback: { id: number; kind: string; percent_changed: number | null; thread_id: number | null; created_at: string | null }[];
    created_at: string | null;
    completed_at: string | null;
}

export interface AgentRunResponse {
    agent_mode: AgentMode;
    run: AgentRunPayload | null;
}

// ── Auto-send (Phase 8, docs/agent-autosend.md) ─────────────────────────────

export interface AutoSendOptions {
    allowed_languages: string[];
    channels: string[];
    disclosure_line: string;
    status_after_send: 'pending' | 'open';
}

export interface RunAutoSend {
    blockers?: string[];
    would_auto_send?: boolean;
    eligible_if_auto?: boolean;
    status?: 'decided' | 'scheduled' | 'sending' | 'sent' | 'cancelled' | 'schedule_failed' | null;
    thread_id?: number;
    scheduled_for?: string;
    sent_at?: string;
    cancel_reason?: string;
    cancelled_by?: number | string | null;
    delay_seconds?: number;
    restored_draft?: boolean;
    events?: { event: string; at: string; [key: string]: unknown }[];
}

/** threads.meta.auto_send on an AI auto-reply thread (origin `ai`). */
export interface ThreadAutoSendMeta {
    status: 'scheduled' | 'sending' | 'sent' | 'cancelled';
    run_id?: number;
    playbook_id?: number | null;
    scheduled_for?: string;
    sent_at?: string;
    cancel_reason?: string;
}

export interface AutoSendStatus {
    auto_playbooks: { id: number; slug: string; name: string; rollout_percent: number; auto_hold: string | null }[];
    sent_today: number;
    last_hour: number;
    pending: number;
    max_per_hour: number;
    pause_hours: number;
}

export interface AutoPrecondition {
    key: string;
    label: string;
    ok: boolean;
    detail: string;
    blocking: boolean;
}

export interface AutoBreaker {
    label: string;
    value: number | null;
    threshold: number;
    sample: number;
    min_sample: number;
    tripped: boolean;
    explanation: string;
    available?: boolean;
}

export interface PlaybookAutoSend {
    preconditions: {
        ok: boolean;
        items: AutoPrecondition[];
        latest_test_run: {
            id: number;
            status: string;
            on_live_version: boolean;
            pass_rate: number | null;
            passed: number | null;
            cases: number | null;
            finished_at: string | null;
        } | null;
        min_test_cases: number;
        min_pass_rate: number;
    };
    breakers: { window_hours: number; auto_sent: number; breakers: Record<string, AutoBreaker> };
    history: {
        id: number;
        event: string | null;
        description: string;
        properties: Record<string, unknown>;
        causer_id: number | null;
        created_at: string | null;
    }[];
    rollout_preview: { sample: number; buckets: number[] };
    auto_hold: string | null;
    demoted_at: string | null;
    demotion_reason: string | null;
}
// ── end: auto-send ──────────────────────────────────────────────────────────
