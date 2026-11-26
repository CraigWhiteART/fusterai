// Types for the QA review JSON API (docs/agent-qa.md, QaController).

import type { PageMeta } from '@/types/agentLoop';

export type QaAuthorType = 'ai' | 'ai_edited' | 'human';

export type QaFailureClass =
    | 'none'
    | 'missing_instruction'
    | 'missing_knowledge'
    | 'missing_tool'
    | 'needed_human'
    | 'agent_error'
    | 'human_error';

export type QaStatus = 'pending' | 'triaged' | 'reviewed' | 'skipped' | 'failed';

export type QaTrigger = 'closed' | 'backfill' | 'unhappy_return' | 'manual';

export type QaHumanVerdict = 'dismissed' | 'incorrect';

export type QaEvidenceSource = 'run_ledger' | 'recorded_snapshot' | 'none';

export type QaGapStatus = 'open' | 'drafting' | 'proposed' | 'failed' | 'closed';

export type QaScoreKey = 'resolved' | 'policy_consistency' | 'factual_grounding' | 'tone_match' | 'clarity';

export interface QaPlaybookRef {
    id: number;
    slug: string;
    name: string | null;
}

/** First-pass ("quick check") result. Forced reviews skip it, so every field is optional. */
export interface QaTriage {
    score?: number | null;
    flagged?: boolean;
    concerns?: string[];
    playbook?: string | null;
    reason?: string | null;
    model?: string | null;
    cost_micro_usd?: number | null;
    unhappy_return?: { run_id?: number | null; sentiment?: string | null; customer_thread_id?: number | null } | null;
}

export type QaScores = Partial<Record<QaScoreKey, number | null>>;

export interface QaReview {
    id: number;
    conversation_id: number;
    subject: string | null;
    reviewed_thread_id: number | null;
    customer_thread_id: number | null;
    author_type: QaAuthorType | string;
    author_user_id: number | null;
    run_id: number | null;
    playbook: QaPlaybookRef | null;
    playbook_id: number | null;
    status: QaStatus | string;
    skip_reason: string | null;
    trigger: QaTrigger | string | null;
    /** full = full review, first = quick check only, null = neither yet. */
    pass: 'full' | 'first' | null;
    sampled: boolean;
    forced: boolean;
    triage_score: number | null;
    /** PHP may send [] for an empty map. */
    triage: QaTriage | unknown[] | null;
    scores: QaScores | unknown[] | null;
    overall: number | null;
    failure_class: QaFailureClass | string | null;
    summary: string | null;
    gap: string | null;
    gap_topic: string | null;
    suggested_fix: string | null;
    gap_id: number | null;
    proposal_id: number | null;
    evidence_source: QaEvidenceSource | string | null;
    reviewer_model: string | null;
    reviewer_prompt_version: string | null;
    cost_micro_usd: number | null;
    cost_usd: number | null;
    human_verdict: QaHumanVerdict | null;
    human_note: string | null;
    human_by: number | null;
    human_at: string | null;
    error: string | null;
    replied_at: string | null;
    reviewed_at: string | null;
    created_at: string | null;
}

export interface QaGapGroupRef {
    id: number;
    status: QaGapStatus | string;
    count: number;
    label: string | null;
    proposal_id: number | null;
    /** Reviews needed before the group drafts a proposal (agent_qa.gaps.proposal_threshold). */
    threshold?: number;
}

export interface QaReviewDetail extends QaReview {
    reviewed_reply?: string | null;
    customer_message?: string | null;
    gap_group?: QaGapGroupRef | null;
}

export interface QaReviewList {
    data: QaReview[];
    meta?: PageMeta;
    /** status → count, dismissed excluded. PHP sends [] when there is none. */
    counts?: Record<string, number> | unknown[];
}

export interface QaReviewFilters {
    failure_class?: string;
    author_type?: string;
    playbook_id?: number | null;
    conversation_id?: number | null;
    status?: string;
    min_overall?: number | null;
    max_overall?: number | null;
    from?: string;
    to?: string;
    trigger?: string;
    include_dismissed?: boolean;
    per_page?: number;
    page?: number;
}

export interface QaGap {
    id: number;
    failure_class: QaFailureClass | string;
    status: QaGapStatus | string;
    label: string | null;
    topic: string | null;
    playbook: QaPlaybookRef | null;
    count: number;
    conversation_ids: number[];
    proposal_id: number | null;
    error: string | null;
    cost_usd: number | null;
    last_seen_at: string | null;
    threshold?: number;
}

export interface QaGapList {
    data: QaGap[];
    threshold: number;
}

export interface QaCapabilityRequest {
    id: number;
    label: string | null;
    topic: string | null;
    playbook: QaPlaybookRef | null;
    count: number;
    conversation_ids: number[];
    suggested_fixes: string[];
    last_seen_at: string | null;
    first_seen_at: string | null;
}
