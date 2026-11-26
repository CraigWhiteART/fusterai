// Types for the feedback loop, proposals, test cases, simulations, frozen
// cases (docs/agent-proposals.md "HTTP API") and history mining
// (docs/agent-mining.md "API"). These APIs were tested with fake models only:
// every field a screen reads is treated as possibly missing, so most are
// optional or nullable and the screens fall back to sensible defaults.

import type { AgentRunPayload } from './agent';

// ── Shared ───────────────────────────────────────────────────────────────────

export interface PageMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface Paginated<T> {
    data: T[];
    meta?: PageMeta;
}

// ── Feedback ─────────────────────────────────────────────────────────────────

export type FeedbackStatus = 'pending' | 'question' | 'one_off' | 'proposal' | 'suggestions_only' | 'failed';
/** What the feedback is about: one reply, or which kind of instruction it changes. */
export type FeedbackScope = 'one_off' | 'voice' | 'playbook' | 'knowledge' | 'example';

export interface FeedbackTarget {
    playbook_id?: number | null;
    knowledge_id?: number | null;
    example_id?: number | null;
}

/** An op the code refused (permissions, limits, modes, rollout): shown as advice only. */
export interface ProposalSuggestion {
    target_type?: string | null;
    target_id?: number | null;
    op?: Record<string, unknown> | string | null;
    reason?: string | null;
    source?: string | null;
}

export interface Feedback {
    id: number;
    conversation_id: number | null;
    run_id: number | null;
    user_id: number | null;
    kind: string;
    text: string | null;
    selected_text: string | null;
    status: FeedbackStatus;
    scope: FeedbackScope | 'unclear' | null;
    scope_overridden: boolean;
    reason: string | null;
    question: string | null;
    answer: string | null;
    one_off_instruction: string | null;
    target: FeedbackTarget | null;
    suggestions?: ProposalSuggestion[];
    error: string | null;
    scopes?: FeedbackScope[];
    proposal: Proposal | null;
    preview: Preview | null;
    created_at: string | null;
}

// ── Proposals ────────────────────────────────────────────────────────────────

export type ProposalOrigin = 'feedback' | 'manager' | 'qa' | 'mining';
export type ProposalStatus = 'draft' | 'testing' | 'ready' | 'approved' | 'rejected' | 'applied' | 'stale' | 'reverted';
export type ChangeTargetType = 'voice' | 'playbook' | 'knowledge' | 'example' | 'test_case';

export interface DiffEntry {
    op: string;
    field?: string | null;
    item_id?: string | null;
    label?: string | null;
    /** null for additions. */
    before?: string | null;
    /** null for removals. */
    after?: string | null;
    // Present on the flattened proposal.diff / preview.instruction_diff:
    change_id?: number;
    target_type?: ChangeTargetType | string;
    target_id?: number | null;
    target_label?: string;
}

export interface ProposalChange {
    id: number;
    position: number;
    target_type: ChangeTargetType | string;
    target_id: number | null;
    target_label?: string;
    base_version_id: number | null;
    ops?: Record<string, unknown>[];
    diff?: DiffEntry[];
    before?: Record<string, unknown> | null;
    after?: Record<string, unknown> | null;
}

export type ConflictType = 'contradiction' | 'duplicate' | 'subsumes' | 'do_dont' | 'action_never' | 'action_not_allowed' | 'check_failed';

export type ConflictResolution = 'keep_old' | 'replace_old' | 'scope_new' | 'keep_both' | 'acknowledge' | 'retire' | 'keep';

export interface ConflictSide {
    target_type?: string | null;
    target_id?: number | null;
    playbook_slug?: string | null;
    field?: string | null;
    item_id?: string | null;
    op?: string | null;
    text?: string | null;
    label?: string | null;
}

export interface ConflictItem {
    id: string;
    type: ConflictType | string;
    severity: 'blocking' | 'warning' | string;
    change_index?: number | null;
    new?: ConflictSide | null;
    existing?: ConflictSide | null;
    existing_precedence?: number | null;
    new_precedence?: number | null;
    explanation?: string | null;
    options?: ConflictResolution[];
    resolution?: ConflictResolution | string | null;
    resolved_by?: number | null;
    resolved_at?: string | null;
    note?: string | null;
}

export interface ExampleFlag {
    id: string;
    example_id: number;
    reply_excerpt?: string | null;
    reason?: string | null;
    phrase?: string | null;
    options?: ConflictResolution[];
    resolution?: ConflictResolution | string | null;
    resolved_by?: number | null;
    resolved_at?: string | null;
}

export interface Conflicts {
    checked_at?: string | null;
    llm?: { status?: 'ok' | 'skipped' | 'failed' | string; error?: string | null; pairs?: number; cost_micro_usd?: number } | null;
    items?: ConflictItem[];
    example_flags?: ExampleFlag[];
    precedence?: string[] | null;
    summary?: { blocking?: number; warnings?: number; examples_flagged?: number } | null;
}

export type PreviewStatus = 'pending' | 'running' | 'ready' | 'failed' | 'skipped';

export interface Preview {
    status: PreviewStatus;
    error?: string | null;
    baseline_run_id?: number | null;
    run_id?: number | null;
    old_draft?: string | null;
    old_decision?: string | null;
    new_draft?: string | null;
    new_decision?: string | null;
    new_handover_reason?: string | null;
    new_handover_label?: string | null;
    instruction_diff?: DiffEntry[];
    new_run?: AgentRunPayload | null;
    at?: string | null;
}

export interface ProposalApplied {
    voice?: { from_version_id: number | null; to_version_id: number | null } | null;
    playbooks?: { playbook_id: number; from_version_id: number | null; to_version_id: number | null }[];
    playbooks_created?: number[];
    knowledge_created?: number[];
    knowledge_superseded?: { old_id: number; new_id: number }[];
    knowledge_retired?: number[];
    examples_retired?: number[];
    examples_created?: number[];
    test_cases_created?: number[];
    restored?: Record<string, unknown>;
}

/** Free-form provenance; these are the conventional keys (mining adds more). */
export interface ProposalEvidence {
    conversation_ids?: number[];
    thread_ids?: number[];
    run_id?: number;
    quotes?: (string | { text?: string; staff?: string })[];
    comment?: string;
    selected_text?: string;
    rules?: { op?: string; rule_id?: string | null; then?: string; conversation_ids?: number[] }[];
    knowledge?: { title?: string; last_seen?: string | null; conversation_ids?: number[] }[];
    inconsistencies?: MiningInconsistency[];
    suggested_actions?: unknown[];
    test_case_conversation_ids?: number[];
    staff?: { label?: string; replies?: number }[];
    staff_differences?: { topic?: string; note?: string }[];
    sample_size?: number;
    mining?: { key?: string; run_id?: number; content_hash?: string };
    [key: string]: unknown;
}

export interface Proposal {
    id: number;
    origin: ProposalOrigin | string;
    status: ProposalStatus;
    title: string | null;
    rationale: string | null;
    scope: FeedbackScope | null;
    evidence: ProposalEvidence | unknown[] | null;
    conversation_id: number | null;
    run_id: number | null;
    feedback_id: number | null;
    base_config_hash?: string | null;
    changes: ProposalChange[];
    diff: DiffEntry[];
    suggestions?: ProposalSuggestion[];
    conflicts: Conflicts | null;
    blocking_conflicts: number;
    can_approve: boolean;
    gate: Simulation | null;
    /** Full Preview on show / preview; a short {status, run_id, ...} in lists. */
    preview: Preview | null;
    applied: ProposalApplied | null;
    review_note: string | null;
    created_by: number | null;
    reviewed_by: number | null;
    reviewed_at: string | null;
    applied_at: string | null;
    reverted_at: string | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface ProposalList extends Paginated<Proposal> {
    /** status → count. PHP sends [] when there is none. */
    counts?: Record<string, number> | unknown[];
}

/** Body of a 409 / 422 from the proposal endpoints. */
export interface StateConflictBody {
    message?: string;
    reason?:
        | 'not_open'
        | 'stale'
        | 'gate_running'
        | 'unchecked'
        | 'blocking_conflicts'
        | 'not_applied'
        | 'moved'
        | 'rebase_failed'
        | string;
    context?: Record<string, unknown> | null;
    errors?: Record<string, string[]>;
    proposal?: Proposal | null;
}

// ── Test cases and frozen cases ──────────────────────────────────────────────

export type TestCaseStatus = 'draft' | 'active' | 'archived';
export type CaseSource = 'feedback' | 'mining' | 'manual' | 'qa';
export type EvidenceQuality = 'recorded' | 'reconstructed' | 'none';

export interface TestExpectation {
    must_include: string[];
    must_not_include: string[];
    expected_outcome: 'draft' | 'handover' | null;
    expected_handover_reason: string | null;
    expected_actions: string[];
    notes: string | null;
}

export interface TranscriptTurn {
    role: 'customer' | 'staff' | string;
    text: string;
    at?: string | null;
    author?: string | null;
    origin?: string | null;
    thread_id?: number | null;
    has_images?: boolean;
    auto_submitted?: boolean;
}

export interface FrozenCase {
    id: number;
    conversation_id: number | null;
    cut_thread_id: number | null;
    human_thread_id: number | null;
    subject: string | null;
    customer_name: string | null;
    cut_at: string | null;
    customer_message: string | null;
    turns: number;
    transcript: TranscriptTurn[] | null;
    evidence_quality: EvidenceQuality;
    evidence_prepared_at: string | null;
    evidence_error: string | null;
    human_reply: string | null;
    human_reply_by: string | null;
    human_reply_at: string | null;
    source: CaseSource | string;
    playbook_slug: string | null;
    created_at: string | null;
    evidence?: Record<string, unknown> | null;
}

export interface FrozenCaseList extends Paginated<FrozenCase> {
    counts?: Record<string, number> | unknown[];
    unprepared?: number;
}

export interface TestCase {
    id: number;
    name: string;
    playbook_id: number | null;
    playbook: { id: number; slug: string; name: string } | null;
    frozen_case_id: number;
    frozen_case: FrozenCase | null;
    expectation: TestExpectation;
    pinned: boolean;
    source: CaseSource | string;
    status: TestCaseStatus;
    proposal_id: number | null;
    feedback_event_id: number | null;
    created_at: string | null;
}

// ── Simulations ──────────────────────────────────────────────────────────────

export type SimulationKind = 'batch' | 'gate' | 'playbook_tests';
export type SimulationStatus = 'pending' | 'running' | 'paused' | 'completed' | 'cancelled' | 'budget_exhausted' | 'failed';
export type Verdict = 'pass' | 'fail' | 'regressed' | 'improved' | 'changed' | 'error';

export interface SimulationTotals {
    cases: number;
    done: number;
    pass: number;
    fail: number;
    regressed: number;
    improved: number;
    changed: number;
    error: number;
    cached: number;
}

export interface Simulation {
    id: number;
    kind: SimulationKind | string;
    status: SimulationStatus;
    proposal_id: number | null;
    playbook_id: number | null;
    candidate_config_hash?: string | null;
    baseline_config_hash?: string | null;
    compares_baseline: boolean;
    selection?: Record<string, unknown> | null;
    totals: Partial<SimulationTotals> | null;
    progress: number;
    cost_micro_usd: number;
    cost_usd: number;
    estimated_cost_micro_usd?: number | null;
    estimated_cost_usd?: number | null;
    budget_micro_usd?: number | null;
    budget_usd?: number | null;
    error: string | null;
    started_at: string | null;
    finished_at: string | null;
    created_at: string | null;
}

export interface JudgeScores {
    status?: 'ok' | 'failed' | string;
    model?: string;
    outcome_match?: number | null;
    policy_consistency?: number | null;
    factual_grounding?: number | null;
    tone_match?: number | null;
    would_send?: boolean | null;
    reason?: string | null;
    error?: string | null;
    cost_micro_usd?: number;
}

export interface DeterministicScores {
    checked?: boolean;
    passed?: boolean;
    failures?: { check: string; detail?: string | null }[];
}

export interface SimulationResult {
    id: number;
    simulation_id: number;
    frozen_case_id: number;
    test_case_id: number | null;
    test_case_name: string | null;
    conversation_id: number | null;
    subject: string | null;
    evidence_quality: EvidenceQuality;
    customer_message: string | null;
    human_reply: string | null;
    agent_run_id: number | null;
    baseline_run_id: number | null;
    rerun_run_id: number | null;
    ai_reply: string | null;
    ai_decision: string | null;
    ai_handover_reason: string | null;
    baseline_reply: string | null;
    baseline_decision: string | null;
    scores?: {
        judge?: JudgeScores | null;
        deterministic?: DeterministicScores | null;
        facts_scored?: boolean | null;
        rerun?: { passed?: boolean; judge?: JudgeScores | null; deterministic?: DeterministicScores | null } | null;
    } | null;
    baseline_scores?: { judge?: JudgeScores | null; deterministic?: DeterministicScores | null } | null;
    passed: boolean | null;
    baseline_passed: boolean | null;
    verdict: Verdict | null;
    effective_verdict: Verdict | null;
    judge_notes: string | null;
    cached: boolean;
    human_override: 'pass' | 'fail' | null;
    human_override_note: string | null;
    human_override_by: number | null;
    cost_micro_usd: number | null;
    error: string | null;
    created_at: string | null;
}

export interface SimulationFilters {
    playbook?: string;
    date_from?: string;
    date_to?: string;
    only_pinned?: boolean;
    min_evidence?: EvidenceQuality;
    sample?: 'latest' | 'random' | 'stratified';
    limit?: number;
}

export interface SimulationEstimate {
    cases: number;
    cached_cases?: number;
    per_case_micro_usd?: number;
    estimated_micro_usd?: number;
    estimated_usd: number;
    basis?: string | Record<string, unknown> | null;
}

// ── History mining ───────────────────────────────────────────────────────────

export type MiningStatus = 'queued' | 'running' | 'paused' | 'completed' | 'failed' | 'cancelled';
export type MiningStage = 'select' | 'extract' | 'cluster' | 'synthesise' | 'voice' | 'emit' | 'done';

export interface MiningOptions {
    since?: string | null;
    until?: string | null;
    months?: number | null;
    max_conversations?: number | null;
    budget_usd?: number | null;
    sample_strategy?: string | null;
}

export interface MiningRunSummary {
    id: number;
    status: MiningStatus | string;
    stage: MiningStage | string;
    pause_reason: 'user' | 'budget' | null;
    message: string | null;
    options: MiningOptions | null;
    selected: number;
    cost_usd: number;
    budget_usd: number | null;
    created_by: string | null;
    created_at: string | null;
    started_at: string | null;
    finished_at: string | null;
}

export interface MiningStageUsage {
    calls: number;
    cache_hits?: number;
    tokens_in: number;
    tokens_out: number;
    cost_usd: number;
    cost_micro_usd?: number;
    model?: string;
}

export interface MiningProposalRef {
    action: 'created' | 'updated' | 'unchanged' | 'unchanged_reviewed' | 'skipped_in_review' | 'skipped' | 'failed' | string | null;
    proposal_id: number | null;
    status: ProposalStatus | string | null;
    reason: string | null;
    errors: string[] | unknown[];
}

export interface MiningCoverage {
    key: string;
    name: string;
    description: string | null;
    playbook_id: number | null;
    playbook_slug: string | null;
    is_new: boolean;
    conversation_count: number;
    share: number;
    status: 'pending' | 'synthesised' | 'failed' | 'too_small' | 'long_tail' | string;
    labels: Record<string, number> | null;
    error: string | null;
    synthesis: {
        rules: number;
        steps: number;
        knowledge: number;
        inconsistencies: number;
        test_cases: number;
        suggested_actions: string[];
    } | null;
    proposal: MiningProposalRef | null;
}

export interface MiningInconsistency {
    topic?: string;
    summary?: string;
    most_recent?: string | null;
    recommendation?: string | null;
    situation?: string | null;
    situation_name?: string | null;
    versions?: { statement?: string; evidence?: number[]; last_seen?: string | null }[];
}

export interface MiningEstimate {
    options?: MiningOptions;
    eligible: number;
    selected: number;
    selected_by_month?: Record<string, number>;
    already_extracted?: number;
    to_extract?: number;
    situations_estimated?: number;
    embeddings?: boolean;
    stages?: Record<string, MiningStageUsage>;
    total?: MiningStageUsage;
    budget_usd?: number | null;
    within_budget?: boolean;
    assumptions?: Record<string, number>;
}

export interface MiningRunDetail extends MiningRunSummary {
    stages?: string[];
    stage_index?: number;
    last_error?: string | null;
    last_error_at?: string | null;
    error_count?: number;
    heartbeat_at?: string | null;
    cost_micro_usd?: number;
    remaining_budget_usd?: number | null;
    tokens?: { in: number; out: number };
    usage?: Record<string, MiningStageUsage>;
    progress?: Partial<{
        eligible: number;
        selected: number;
        pending: number;
        done: number;
        failed: number;
        extracted_new: number;
        reused: number;
        macro_copies: number;
        situations: number;
        situations_pending: number;
        situations_synthesised: number;
        situations_failed: number;
        proposals_emitted: number;
    }>;
    selected_by_month?: Record<string, number>;
    dropped?: Record<string, number>;
    counters?: Record<string, unknown>;
    clustering?: { method?: string; labels?: number; new_labels?: number } | unknown[];
    coverage?: MiningCoverage[];
    inconsistencies?: MiningInconsistency[];
    voice?: {
        sample_size?: number;
        staff?: { label: string; replies: number }[];
        staff_differences?: { topic: string; note: string }[];
        example_phrases?: { text: string; staff?: string | null }[];
        proposal?: MiningProposalRef | null;
    } | null;
    proposals?: Record<string, MiningProposalRef> | unknown[];
    estimate?: MiningEstimate | null;
}

export interface MiningIndex {
    runs: MiningRunSummary[];
    active_run_id: number | null;
    defaults: { months: number; max_conversations: number; budget_usd: number; sample_strategy: string };
    limits: { max_conversations: number; budget_usd: number; months: number };
    strategies: string[];
}

// ── Workspace channel events (private-workspace.{id}) ────────────────────────

export interface FeedbackRoutedEvent {
    feedback_id: number;
    conversation_id: number | null;
    run_id: number | null;
    status: FeedbackStatus;
    scope: string | null;
    proposal_id: number | null;
    question: string | null;
}

export interface PreviewReadyEvent {
    feedback_id: number | null;
    conversation_id: number | null;
    proposal_id: number | null;
    status: PreviewStatus;
    run_id: number | null;
}

export interface ProposalUpdatedEvent {
    proposal_id: number;
    status: ProposalStatus;
    origin: string;
    conversation_id: number | null;
    feedback_id: number | null;
}

export interface SimulationProgressEvent {
    simulation_id: number;
    kind: string;
    status: SimulationStatus;
    proposal_id: number | null;
    playbook_id: number | null;
    totals: Partial<SimulationTotals> | null;
    cost_micro_usd: number;
    result_id?: number | null;
}

export interface CasesPreparedEvent {
    finished?: boolean;
    reconstructed?: number;
    none?: number;
    recorded?: number;
    conversations?: number;
    cases?: number;
}
