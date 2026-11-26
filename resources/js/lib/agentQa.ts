// Client, labels and small helpers for the QA review JSON API (docs/agent-qa.md).
// Used by the QA screen (Pages/Agent/Qa) and reusable from the conversation
// view (a QA badge and a "Review this conversation now" button).

import { asArray, asRecord, toApiError, type ApiError } from '@/lib/agentLoop';
import type {
    QaCapabilityRequest,
    QaGapList,
    QaHumanVerdict,
    QaPlaybookRef,
    QaReview,
    QaReviewDetail,
    QaReviewFilters,
    QaReviewList,
    QaScoreKey,
    QaScores,
    QaTriage,
} from '@/types/agentQa';

// ── API ──────────────────────────────────────────────────────────────────────

function clean(params: object): Record<string, unknown> {
    return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== ''));
}

export const qaApi = {
    reviews: async (filters: QaReviewFilters = {}): Promise<QaReviewList> => {
        const { data } = await window.axios.get<QaReviewList>('/agent/qa/reviews', {
            params: clean({ ...filters, include_dismissed: filters.include_dismissed ? 1 : undefined }),
        });
        return data;
    },
    review: async (id: number): Promise<QaReviewDetail> => {
        const { data } = await window.axios.get<{ review: QaReviewDetail }>(`/agent/qa/reviews/${id}`);
        return data.review;
    },
    /** null clears the verdict. Answers the full review (same shape as review()). */
    setVerdict: async (id: number, verdict: QaHumanVerdict | null, note?: string | null): Promise<QaReviewDetail> => {
        const { data } = await window.axios.post<{ review: QaReviewDetail }>(`/agent/qa/reviews/${id}/verdict`, {
            verdict,
            note: note?.trim() ? note.trim() : null,
        });
        return data.review;
    },
    /** 202 → the queued review (status pending). Throws on 409 / 422 / 429: read the reason with toQaApiError. */
    reviewConversation: async (conversationId: number): Promise<QaReview> => {
        const { data } = await window.axios.post<{ review: QaReview }>(`/agent/qa/conversations/${conversationId}/review`);
        return data.review;
    },
    gaps: async (): Promise<QaGapList> => {
        const { data } = await window.axios.get<QaGapList>('/agent/qa/gaps');
        return { data: asArray(data?.data), threshold: typeof data?.threshold === 'number' ? data.threshold : 3 };
    },
    /** Every playbook of the workspace for the filter (GET /agent/playbooks/options). */
    playbooks: async (): Promise<QaPlaybookRef[]> => {
        const { data } = await window.axios.get<{ data: QaPlaybookRef[] }>('/agent/playbooks/options', { params: { include_archived: 1 } });
        return asArray<QaPlaybookRef>(data?.data).map((p) => ({ id: p.id, slug: p.slug, name: p.name }));
    },
    capabilityRequests: async (): Promise<QaCapabilityRequest[]> => {
        const { data } = await window.axios.get<{ data: QaCapabilityRequest[] }>('/agent/qa/capability-requests');
        return asArray<QaCapabilityRequest>(data?.data);
    },
};

/** Plain-English reasons for the QA endpoints' 409 / 422 answers. */
export const QA_REASON_TEXT: Record<string, string> = {
    disabled: 'Quality checks are switched off for this store.',
    agent_off: 'The AI agent is switched off, so quality checks do not run. Turn the agent on in the AI agent settings first.',
    ai_not_configured: 'No AI key is set up yet, so quality checks cannot run. Add one in the AI settings first.',
    no_staff_reply: 'There is no reply from your team in this conversation yet, so there is nothing to check.',
};

/** toApiError with the QA reasons translated. */
export function toQaApiError(error: unknown): ApiError {
    return withQaReason(toApiError(error));
}

/** Translate the QA reasons of an ApiError that already went through toApiError (e.g. useAction's error). */
export function withQaReason(e: ApiError): ApiError {
    if (e.reason && QA_REASON_TEXT[e.reason]) return { ...e, message: QA_REASON_TEXT[e.reason] };
    if (e.status === 403) return { ...e, message: 'Only admins can see and run quality checks.' };
    if (e.status === 404) return { ...e, message: 'That conversation or review was not found (it may have been deleted).' };
    return e;
}

export type QaReviewRequestResult = { ok: true; review: QaReview; message: string } | { ok: false; error: ApiError; message: string };

/**
 * "Review this conversation now": never throws. On success the review is
 * queued (status pending) and takes a minute or two to finish.
 */
export async function requestConversationReview(conversationId: number): Promise<QaReviewRequestResult> {
    try {
        const review = await qaApi.reviewConversation(conversationId);
        return {
            ok: true,
            review,
            message: 'Quality check started. The result usually appears within a couple of minutes.',
        };
    } catch (err) {
        const error = toQaApiError(err);
        return { ok: false, error, message: error.message };
    }
}

// ── Labels ───────────────────────────────────────────────────────────────────

export const QA_FAILURE_CLASS_LABELS: Record<string, string> = {
    none: 'No problem found',
    missing_instruction: 'Missing playbook rule',
    missing_knowledge: 'Missing knowledge',
    missing_tool: 'Missing tool',
    needed_human: 'Needed a person',
    agent_error: 'AI mistake',
    human_error: 'Team member mistake',
};

/** One line explaining each failure class. */
export const QA_FAILURE_CLASS_HELP: Record<string, string> = {
    none: 'Nothing important was missing. Any problems were small.',
    missing_instruction: 'No playbook rule covered this.',
    missing_knowledge: 'The agent did not know a fact or policy it needed (for example a warranty term or a delivery time).',
    missing_tool: "The agent would need a tool it doesn't have.",
    needed_human: "This needed a person's judgement. The AI should not handle it alone.",
    agent_error: 'The AI had what it needed but still got something wrong.',
    human_error: 'A person on the team made a mistake in the reply.',
};

export const QA_FAILURE_CLASS_ORDER = [
    'none',
    'missing_instruction',
    'missing_knowledge',
    'missing_tool',
    'needed_human',
    'agent_error',
    'human_error',
] as const;

export function qaFailureClassLabel(value: string | null | undefined): string {
    return (value && QA_FAILURE_CLASS_LABELS[value]) || (value ? value.replace(/_/g, ' ') : 'Not classified');
}

export function qaFailureClassVariant(value: string | null | undefined): 'success' | 'destructive' | 'secondary' | 'outline' {
    if (value === 'none') return 'success';
    if (value === 'agent_error' || value === 'human_error') return 'destructive';
    if (!value) return 'outline';
    return 'secondary';
}

export const QA_AUTHOR_TYPE_LABELS: Record<string, string> = {
    ai: 'Sent by the AI',
    ai_edited: 'AI draft edited by a person',
    human: 'Written by a person',
};

export const QA_STATUS_LABELS: Record<string, string> = {
    pending: 'Waiting to be checked',
    triaged: 'Quick check only',
    reviewed: 'Full review',
    skipped: 'Skipped',
    failed: "Couldn't be checked",
};

export const QA_SKIP_REASON_LABELS: Record<string, string> = {
    auto_closed_without_reply: 'The customer wrote again and nobody answered before it closed.',
    not_support: 'The message was a newsletter, notification or automatic email, not a customer question.',
    not_sampled: 'Not picked: only a share of closed conversations are checked.',
    daily_cap: "Today's limit of quality checks was already reached.",
    reopened: 'The conversation was reopened before the check ran.',
    spam: 'Spam.',
    no_staff_reply: 'Nobody on the team replied.',
};

export const QA_SCORE_LABELS: Record<QaScoreKey, string> = {
    resolved: 'Resolved the problem',
    policy_consistency: 'Followed policy',
    factual_grounding: 'Facts backed by evidence',
    tone_match: 'Tone matches the brand',
    clarity: 'Clear and easy to read',
};

export const QA_SCORE_ORDER: QaScoreKey[] = ['resolved', 'policy_consistency', 'factual_grounding', 'tone_match', 'clarity'];

export const QA_EVIDENCE_SOURCE_LABELS: Record<string, string> = {
    run_ledger: 'What the agent actually saw',
    recorded_snapshot: 'Order details saved at the time',
    none: 'No order details',
};

export const QA_EVIDENCE_SOURCE_HELP: Record<string, string> = {
    run_ledger: 'The order and tracking details the agent looked up when it wrote its draft. Facts were checked against these.',
    recorded_snapshot:
        'Order and tracking details saved for this conversation around the time of the reply. Facts were checked against these.',
    none: 'Nothing about the order was recorded, so facts in the reply could not be checked.',
};

export const QA_GAP_STATUS_LABELS: Record<string, string> = {
    open: 'Collecting examples',
    drafting: 'Writing a proposal…',
    proposed: 'Proposal drafted',
    failed: "Couldn't draft a proposal",
    closed: 'Closed',
};

export const QA_GAP_STATUS_HELP: Record<string, string> = {
    open: 'Waiting for more conversations with the same problem.',
    drafting: 'Enough conversations had this problem. The AI is writing a suggested fix for you to review.',
    proposed: 'A suggested fix is waiting in Proposals. New cases are still counted here.',
    failed: 'The AI tried to write a fix but could not come up with a usable change.',
    closed: 'Its proposal was approved, rejected or undone. New cases start a fresh group.',
};

export const QA_VERDICT_LABELS: Record<string, string> = {
    incorrect: 'The review is wrong',
    dismissed: 'Not worth counting',
};

export const QA_VERDICT_HELP: Record<string, string> = {
    incorrect: 'The scores or the problem it found are mistaken.',
    dismissed: 'The review may be fair, but this conversation is a one-off (a test, spam, an unusual case) and should not count.',
};

// ── Helpers ──────────────────────────────────────────────────────────────────

export function qaTriage(review: Pick<QaReview, 'triage'>): QaTriage {
    return asRecord<unknown>(review.triage as Record<string, unknown> | unknown[] | null) as QaTriage;
}

export function qaScores(review: Pick<QaReview, 'scores'>): QaScores {
    return asRecord<number | null>(review.scores as Record<string, number | null> | unknown[] | null) as QaScores;
}

/** The score to show for a review: the full review's overall, else the quick-check score. */
export function qaHeadlineScore(review: Pick<QaReview, 'overall' | 'triage_score' | 'triage'>): {
    value: number | null;
    quick: boolean;
} {
    if (typeof review.overall === 'number') return { value: review.overall, quick: false };
    const triage = qaTriage(review);
    const score = typeof review.triage_score === 'number' ? review.triage_score : typeof triage.score === 'number' ? triage.score : null;
    return { value: score, quick: score !== null };
}

export function formatScore(value: number | null | undefined): string {
    if (typeof value !== 'number' || Number.isNaN(value)) return '—';
    return Number.isInteger(value) ? String(value) : value.toFixed(1);
}

/** Text tone for a 1-5 score. */
export function scoreTone(value: number | null | undefined): 'good' | 'ok' | 'bad' | 'none' {
    if (typeof value !== 'number') return 'none';
    if (value >= 4) return 'good';
    if (value >= 3) return 'ok';
    return 'bad';
}

/** One line on why this reply was checked the way it was. */
export function qaSelectionText(review: Pick<QaReview, 'trigger' | 'sampled' | 'forced' | 'pass' | 'triage' | 'status'>): string {
    const triage = qaTriage(review);
    if (review.trigger === 'unhappy_return') {
        const mood = triage.unhappy_return?.sentiment;
        return `The customer came back${mood ? ` ${mood}` : ' unhappy'} after this reply, so it got a full check.`;
    }
    if (review.trigger === 'manual') return 'Someone on the team asked for a full check of this reply.';
    if (review.forced) return 'A full check was requested for this reply.';
    const origin = review.trigger === 'backfill' ? 'Checked in a catch-up of older conversations' : 'Checked after the conversation closed';
    if (review.pass === 'full') {
        return review.sampled
            ? `${origin}. It passed the quick check but was picked at random for a full check, so the averages are not only based on problem replies.`
            : `${origin}. The quick check scored it low, so it got a full check.`;
    }
    if (review.pass === 'first') return `${origin}. It passed the quick check, so no full check was needed.`;
    return `${origin}.`;
}

export function qaReviewTitle(review: Pick<QaReview, 'subject' | 'conversation_id'>): string {
    return review.subject?.trim() || `Conversation #${review.conversation_id}`;
}

/** "3 of 3 conversations — a proposal is drafted at 3" */
export function gapProgressText(count: number, threshold: number, status: string | null | undefined): string {
    const base = `${count} of ${threshold} conversation${threshold === 1 ? '' : 's'}`;
    if (status === 'proposed' || status === 'closed')
        return `${count} conversation${count === 1 ? '' : 's'} so far — a proposal was drafted at ${threshold}`;
    if (status === 'drafting') return `${base} — a proposal is being drafted now`;
    if (count >= threshold) return `${count} conversation${count === 1 ? '' : 's'} — a proposal is drafted at ${threshold}`;
    return `${base} — a proposal is drafted at ${threshold}`;
}
