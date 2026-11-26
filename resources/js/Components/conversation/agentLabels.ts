import type { RunSource } from '@/types/agent';

/**
 * Plain-English labels for machine-readable handover reasons. Mirrors
 * App\Domains\Agent\Pipeline\HandoverReasons::LABELS; the run payload's
 * `handover_label` wins when present, this covers `conversations.handover_reason`
 * before the run has loaded.
 */
const HANDOVER_LABELS: Record<string, string> = {
    kill_switch: 'the agent kill switch is on',
    no_instruction: 'no playbook covers this situation',
    always_human: 'this playbook is always handled by a human',
    playbook_off: 'the matching playbook is switched off',
    order_email_mismatch: 'the order email does not match the sender, so order details were withheld',
    missing_evidence: 'evidence the playbook requires is missing',
    risk: 'a risk flag was raised',
    writer: 'the writer asked for a human',
    validation_failed: 'the draft failed validation after one rewrite',
    validator_error: 'the validator could not run',
    triage_error: 'the email could not be classified',
    writer_error: 'the writer failed',
    no_draft: 'the writer produced no reply',
    timeout: 'the run ran out of time',
};

export function handoverLabel(reason: string | null | undefined): string | null {
    if (!reason) return null;
    const [base, ...rest] = reason.split(':');
    const detail = rest.join(':');
    const label = HANDOVER_LABELS[base] ?? base.replace(/_/g, ' ');
    return detail ? `${label} (${detail.replace(/_/g, ' ')})` : label;
}

export function capitalise(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

const EVIDENCE_SOURCE_LABELS: Record<string, string> = {
    shopify: 'Shopify order',
    tracking: 'Tracking scan',
    conversation: 'Helpdesk',
    customer: 'Customer said (unverified)',
    supplied: 'Supplied',
};

/** Short label for where a fact came from. */
export function sourceKindLabel(source: RunSource): string {
    if (source.type === 'knowledge') return source.kind === 'policy' ? 'Policy' : 'Knowledge';
    if (source.type === 'unknown') return 'No source';
    return EVIDENCE_SOURCE_LABELS[source.source ?? ''] ?? capitalise(source.source ?? 'Evidence');
}

/** The fact itself: "Fulfilment status: UNFULFILLED" or a knowledge item title. */
export function sourceText(source: RunSource): string {
    if (source.type === 'knowledge') return source.title || (source.body ?? '').slice(0, 80) || source.id;
    if (source.type === 'unknown') return `"${source.id}" is not in the evidence`;
    return [source.label, source.value].filter(Boolean).join(': ');
}

export function formatCost(usd: number): string {
    if (!Number.isFinite(usd) || usd <= 0) return '$0.00';
    return usd < 0.01 ? `$${usd.toFixed(4)}` : `$${usd.toFixed(2)}`;
}

export function formatTokens(n: number): string {
    return n >= 1000 ? `${(n / 1000).toFixed(1)}k` : String(n);
}

/** Why a reply was a draft and not auto-sent (`run.auto_send.blockers`, docs/agent-autosend.md). */
const AUTO_SEND_BLOCKER_LABELS: Record<string, string> = {
    kill_switch: 'The kill switch is on.',
    agent_mode: 'The agent is not in Live mode.',
    not_live: 'This was not a run on real email.',
    no_context: 'This was not a run on real email.',
    paused: 'Automatic sending is paused.',
    playbook_mode: 'This playbook is not set to send automatically.',
    always_human: 'This playbook is always handled by a person.',
    playbook_hold: 'The playbook changed and its tests are running again; auto-send waits until they pass.',
    rollout: 'This conversation is outside the playbook’s rollout percentage.',
    validator: 'The draft did not pass the checks.',
    rewritten: 'The first draft needed a rewrite. Rewritten drafts are never sent automatically.',
    risk_flags: 'A risk flag was raised.',
    claims_ungrounded: 'A fact in the draft is not backed by order data or knowledge.',
    actions_pending: 'The reply depends on a Shopify action.',
    language: 'The email is in a language not allowed for auto-send.',
    channel: 'Auto-send is not switched on for this channel.',
    sentiment: 'The customer seems upset.',
    human_replied: 'Someone already replied after the customer’s message.',
    customer_blocked: 'The customer is blocked.',
    inbound_automated: 'The customer’s message looks automatic (an auto-reply or newsletter).',
    requested_by_human: 'A person asked for this draft, so it waits for them.',
    daily_cap: 'Today’s auto-send limit is reached.',
    customer_cap: 'Today’s auto-send limit for this customer is reached.',
    consecutive_cap: 'The agent already auto-replied here twice in a row without a person.',
    hourly_breaker: 'Too many auto-sends in the last hour, so auto-sending was paused.',
    context_error: 'The safety checks could not be worked out.',
    gate_error: 'The safety checks failed to run.',
};

export function autoSendBlockerLabel(code: string): string {
    return AUTO_SEND_BLOCKER_LABELS[code] ?? capitalise(code.replace(/_/g, ' ')) + '.';
}

/** Proposed-action statuses on a run (`run.proposed_actions[].status`). */
const PROPOSED_ACTION_STATUS: Record<string, string> = {
    rejected_not_allowed: 'not allowed by the playbook',
    rejected_unknown_action: 'not an action the agent knows',
    rejected_unavailable: 'not available (Shopify not connected or permission missing)',
    rejected_disabled: 'switched off in the action settings',
    rejected_invalid_params: 'the details were not valid',
};

export function proposedActionStatusLabel(status: string): string | null {
    return status === 'proposed' ? null : (PROPOSED_ACTION_STATUS[status] ?? status.replace(/_/g, ' '));
}
