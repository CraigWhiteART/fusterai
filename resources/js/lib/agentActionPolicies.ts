// Client and plain-English labels for the Shopify action catalogue, the
// per-action permission policies, the approval settings and the audit log
// (docs/agent-actions.md). Execution labels / statuses live in
// lib/agentActions.ts (lead-owned); this file only covers permissions.
//
// Nothing here is optimistic: every save is followed by a re-fetch.

import type { ActionExecutionList } from '@/types/agentActions';
import type {
    ActionSettingsData,
    ActionSettingsResponse,
    AuditFilters,
    Catalogue,
    DiscountTemplate,
    EffectivePolicy,
    Policy,
    PoliciesResponse,
    PolicyLimits,
    PolicyMode,
    UnattendedGates,
    UpdateActionSettingsBody,
    UpdatePolicyBody,
} from '@/types/agentActionPolicies';
import { asArray, asRecord, type ApiError } from '@/lib/agentLoop';

// ── API ──────────────────────────────────────────────────────────────────────

function clean(params: object): Record<string, string | number> {
    return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== '')) as Record<
        string,
        string | number
    >;
}

/** A plain `to` date covers that whole day (the server does that). */
function auditParams(filters: AuditFilters): Record<string, string | number> {
    return clean({ ...filters });
}

export const policiesApi = {
    catalogue: () => window.axios.get<Catalogue>('/agent/actions/catalogue').then((r) => r.data),
    policies: () => window.axios.get<PoliciesResponse>('/agent/actions/policies').then((r) => r.data),
    settings: () => window.axios.get<ActionSettingsResponse>('/agent/actions/settings').then((r) => r.data),
    updateSettings: (body: UpdateActionSettingsBody) =>
        window.axios.patch<{ settings: ActionSettingsData }>('/agent/actions/settings', body).then((r) => r.data.settings),
    /** 422 for `always` on an approval-only action, sender match off on a money action, or invalid limits (`limits.<key>`). */
    updatePolicy: (actionKey: string, body: UpdatePolicyBody) =>
        window.axios
            .put<{ policy: Policy; effective: EffectivePolicy }>(`/agent/actions/policies/${encodeURIComponent(actionKey)}`, body)
            .then((r) => r.data),
    /** Removes a row. A deleted shop-wide row means "Never". */
    deletePolicy: (id: number) => window.axios.delete<{ deleted: boolean }>(`/agent/actions/policies/${id}`).then((r) => r.data),
    audit: (filters: AuditFilters) =>
        window.axios.get<ActionExecutionList>('/agent/actions/audit', { params: auditParams(filters) }).then((r) => r.data),
};

/** Plain download link for the audit CSV (use in an <a download>, not an Inertia Link). */
export function auditCsvUrl(filters: AuditFilters): string {
    const params = auditParams({ ...filters, page: undefined, per_page: undefined });
    const query = new URLSearchParams({ format: 'csv' });
    Object.entries(params).forEach(([k, v]) => query.set(k, String(v)));
    return `/agent/actions/audit?${query.toString()}`;
}

/**
 * Agent mode, kill switch, pause and the daily unattended-action cap, as
 * returned by GET /agent/actions/settings (`gates`). Display only, never
 * trusted for anything: the server checks every gate again before an action runs.
 */
export function unattendedGates(response: ActionSettingsResponse | null | undefined): UnattendedGates {
    const g = asRecord<unknown>(response?.gates as Record<string, unknown> | undefined);
    return {
        mode: typeof g.mode === 'string' ? g.mode : null,
        kill_switch: typeof g.kill_switch === 'boolean' ? g.kill_switch : null,
        paused_until: typeof g.paused_until === 'string' ? g.paused_until : null,
        actions_per_day: typeof g.actions_per_day === 'number' ? g.actions_per_day : null,
    };
}

// ── Errors ───────────────────────────────────────────────────────────────────

/** 422 `errors` as field => first message (keys like `limits.max_amount`). */
export function fieldErrors(error: ApiError | null | undefined): Record<string, string> {
    if (!error) return {};
    const out: Record<string, string> = {};
    Object.entries(asRecord<string[] | string>(error.errors)).forEach(([key, messages]) => {
        const first = Array.isArray(messages) ? messages[0] : messages;
        if (typeof first === 'string') out[key] = first;
    });
    return out;
}

// ── Modes ────────────────────────────────────────────────────────────────────

export const MODE_ORDER: PolicyMode[] = ['never', 'approval', 'always'];

export const MODE_LABELS: Record<PolicyMode, string> = {
    never: 'Never',
    approval: 'Needs approval',
    always: 'Runs on its own',
};

export const MODE_HELP: Record<PolicyMode, string> = {
    never: 'The agent may not do this. If it tries, the request is cancelled and a person handles the email.',
    approval: 'The agent can ask for it. Nothing happens in Shopify until a person approves it.',
    always: 'Within the limits below, it happens in Shopify without anyone checking first. Anything outside the limits still waits for a person.',
};

export function modeLabel(mode: string | null | undefined): string {
    return (mode && MODE_LABELS[mode as PolicyMode]) || mode || MODE_LABELS.never;
}

export function asMode(mode: string | null | undefined): PolicyMode {
    return mode === 'approval' || mode === 'always' ? mode : 'never';
}

export const EFFECTIVE_SOURCE_LABELS: Record<string, string> = {
    workspace: 'from the shop-wide setting',
    'workspace+playbook': 'shop-wide setting, tightened by a playbook',
    playbook_override: 'from a playbook that replaces the shop-wide setting',
};

export const FORCED_LABELS: Record<string, string> = {
    approval_only_action: 'This action can never run on its own, so it is treated as “Needs approval”.',
    sender_match_mandatory: 'The sender’s email must match the order email for this action, whatever the setting says.',
    unknown_action: 'Unknown action: treated as “Never”.',
};

// ── Roles ────────────────────────────────────────────────────────────────────

export const ROLE_OPTION_LABELS: Record<string, string> = {
    agent: 'Anyone on the team (including your colleague)',
    manager: 'Managers and admins',
    admin: 'Admins only (the owner)',
    super_admin: 'Only the account owner (super admin)',
};

// ── Limits ───────────────────────────────────────────────────────────────────

export type LimitKind = 'money' | 'count' | 'percent' | 'days' | 'states' | 'countries' | 'bool';

export interface LimitField {
    key: string;
    kind: LimitKind;
    label: string;
    help: string;
}

/** Actions where an amount means something (the rest move no money). */
export const AMOUNT_ACTIONS = ['refund', 'cancel_order', 'discount_code', 'replacement'];

export const LIMIT_FIELDS: LimitField[] = [
    {
        key: 'max_amount',
        kind: 'money',
        label: 'Most per action',
        help: 'Largest amount one action may be, in your shop’s currency.',
    },
    {
        key: 'per_customer_30d_amount',
        kind: 'money',
        label: 'Most per customer in 30 days (amount)',
        help: 'Total for the same customer email over the last 30 days.',
    },
    {
        key: 'per_customer_30d_count',
        kind: 'count',
        label: 'Most per customer in 30 days (times)',
        help: 'How many times the same customer email may get this in 30 days.',
    },
    { key: 'per_day_amount', kind: 'money', label: 'Most per day (amount)', help: 'Total for the whole shop since midnight.' },
    { key: 'per_day_count', kind: 'count', label: 'Most per day (times)', help: 'How many times for the whole shop since midnight.' },
    { key: 'max_percentage', kind: 'percent', label: 'Biggest discount (%)', help: 'For percentage discount codes.' },
    {
        key: 'max_order_age_days',
        kind: 'days',
        label: 'Only orders younger than (days)',
        help: 'Older orders always wait for a person.',
    },
    {
        key: 'allowed_fulfilment_states',
        kind: 'states',
        label: 'Only orders in these shipping states',
        help: 'Leave all unticked for no limit.',
    },
    {
        key: 'allowed_countries',
        kind: 'countries',
        label: 'Only orders shipping to these countries',
        help: 'Two-letter country codes separated by commas, e.g. AU, NZ. Empty = no limit.',
    },
    {
        key: 'require_sender_email_match',
        kind: 'bool',
        label: 'The sender’s email must match the order email',
        help: 'Stops someone asking for changes to an order that is not theirs.',
    },
];

/** Which limit fields make sense for an action (the server accepts all of them). */
export function limitFieldsFor(actionKey: string, limitKeys: string[]): LimitField[] {
    const keys = limitKeys.length ? limitKeys : LIMIT_FIELDS.map((f) => f.key);
    return LIMIT_FIELDS.filter((f) => {
        if (!keys.includes(f.key)) return false;
        if (f.kind === 'money') return AMOUNT_ACTIONS.includes(actionKey);
        if (f.kind === 'percent') return actionKey === 'discount_code';
        return true;
    });
}

/** Shopify `displayFulfillmentStatus` values the API accepts, in plain words. */
export const FULFILMENT_STATE_LABELS: Record<string, string> = {
    UNFULFILLED: 'Not shipped yet',
    ON_HOLD: 'On hold',
    OPEN: 'Open',
    PENDING_FULFILLMENT: 'Waiting to be packed',
    SCHEDULED: 'Scheduled',
    IN_PROGRESS: 'Being packed',
    PARTIALLY_FULFILLED: 'Partly shipped',
    FULFILLED: 'Shipped',
    RESTOCKED: 'Restocked',
    REQUEST_DECLINED: 'Warehouse declined',
    FULFILLMENT_NOT_REQUIRED: 'Nothing to ship',
};

/** Form state for one limits editor (strings while typing). */
export interface LimitDraft {
    max_amount: string;
    per_customer_30d_amount: string;
    per_customer_30d_count: string;
    per_day_amount: string;
    per_day_count: string;
    max_percentage: string;
    max_order_age_days: string;
    allowed_fulfilment_states: string[];
    allowed_countries: string;
    require_sender_email_match: boolean;
}

type TextLimitKey = Exclude<keyof LimitDraft, 'allowed_fulfilment_states' | 'require_sender_email_match' | 'allowed_countries'>;

const TEXT_KEYS: TextLimitKey[] = [
    'max_amount',
    'per_customer_30d_amount',
    'per_customer_30d_count',
    'per_day_amount',
    'per_day_count',
    'max_percentage',
    'max_order_age_days',
];

function str(value: unknown): string {
    return value === null || value === undefined ? '' : String(value);
}

export function limitsOf(value: PolicyLimits | unknown[] | null | undefined): PolicyLimits {
    return asRecord<unknown>(value as Record<string, unknown> | unknown[] | null | undefined) as PolicyLimits;
}

export function toDraft(value: PolicyLimits | unknown[] | null | undefined, money: boolean): LimitDraft {
    const limits = limitsOf(value);
    const draft = {
        allowed_fulfilment_states: asArray<string>(limits.allowed_fulfilment_states).map((s) => String(s).toUpperCase()),
        allowed_countries: asArray<string>(limits.allowed_countries).join(', '),
        // Default on; always on for money / address actions.
        require_sender_email_match: money ? true : limits.require_sender_email_match !== false,
    } as LimitDraft;
    TEXT_KEYS.forEach((key) => {
        draft[key] = str(limits[key]);
    });
    return draft;
}

export function parseCountries(text: string): string[] {
    return Array.from(
        new Set(
            text
                .split(/[\s,;]+/)
                .map((c) => c.trim().toUpperCase())
                .filter(Boolean),
        ),
    );
}

/** Only the limits that apply to this action; empty fields are left out (= no limit). */
export function fromDraft(draft: LimitDraft, fields: LimitField[], money: boolean): PolicyLimits {
    const keys = new Set(fields.map((f) => f.key));
    const out: PolicyLimits = {};
    TEXT_KEYS.forEach((key) => {
        const v = draft[key].trim();
        if (keys.has(key) && v !== '') out[key] = v as never;
    });
    if (keys.has('allowed_fulfilment_states') && draft.allowed_fulfilment_states.length) {
        out.allowed_fulfilment_states = draft.allowed_fulfilment_states;
    }
    const countries = parseCountries(draft.allowed_countries);
    if (keys.has('allowed_countries') && countries.length) out.allowed_countries = countries;
    out.require_sender_email_match = money ? true : draft.require_sender_email_match;
    return out;
}

/** Client-side checks before saving (the server validates again). Keys match the API (`limits.<key>`). */
export function draftErrors(draft: LimitDraft, fields: LimitField[], required: string[]): Record<string, string> {
    const errors: Record<string, string> = {};
    fields.forEach((f) => {
        const key = `limits.${f.key}`;
        if (f.kind === 'countries') {
            const bad = parseCountries(draft.allowed_countries).filter((c) => !/^[A-Z]{2}$/.test(c));
            if (bad.length) errors[key] = `Use two-letter country codes (not ${bad.join(', ')}).`;
            return;
        }
        if (f.kind === 'states' || f.kind === 'bool') return;
        const v = draft[f.key as TextLimitKey].trim();
        if (v === '') {
            if (required.includes(f.key)) errors[key] = 'Required before this can run on its own.';
            return;
        }
        const n = Number(v);
        if (!Number.isFinite(n) || n < 0) errors[key] = 'Enter a number of 0 or more.';
        else if ((f.kind === 'count' || f.kind === 'days') && !Number.isInteger(n)) errors[key] = 'Enter a whole number.';
        else if (f.kind === 'percent' && n > 100) errors[key] = 'At most 100.';
    });
    return errors;
}

/**
 * Limits that must be set before "Runs on its own" can be saved. The server
 * would accept the policy without them but then send every request to a
 * person (`no_max_amount_limit` / `no_max_percentage_limit`), so ask up front.
 */
export function requiredForAlways(actionKey: string, templates: DiscountTemplate[]): string[] {
    if (actionKey === 'refund' || actionKey === 'cancel_order') return ['max_amount'];
    if (actionKey === 'discount_code') {
        const types = new Set(templates.map((t) => t.type));
        if (types.size === 0) return ['max_amount', 'max_percentage'];
        return [...(types.has('fixed') ? ['max_amount'] : []), ...(types.has('percentage') ? ['max_percentage'] : [])];
    }
    return [];
}

function amountText(value: unknown): string {
    const n = Number(value);
    return `${Number.isFinite(n) ? n.toFixed(2) : String(value)} (shop currency)`;
}

/** Plain-English sentences for a set of limits, e.g. "At most 20.00 (shop currency) per refund". */
export function describeLimits(value: PolicyLimits | unknown[] | null | undefined, actionKey: string): string[] {
    const l = limitsOf(value);
    const lines: string[] = [];
    const has = (k: string) => l[k] !== undefined && l[k] !== null && l[k] !== '';
    if (has('max_amount')) lines.push(`At most ${amountText(l.max_amount)} each time.`);
    if (has('max_percentage')) lines.push(`Discounts of at most ${l.max_percentage}%.`);
    if (has('per_customer_30d_amount')) lines.push(`At most ${amountText(l.per_customer_30d_amount)} per customer in 30 days.`);
    if (has('per_customer_30d_count'))
        lines.push(`At most ${l.per_customer_30d_count} time${Number(l.per_customer_30d_count) === 1 ? '' : 's'} per customer in 30 days.`);
    if (has('per_day_amount')) lines.push(`At most ${amountText(l.per_day_amount)} per day for the whole shop.`);
    if (has('per_day_count'))
        lines.push(`At most ${l.per_day_count} time${Number(l.per_day_count) === 1 ? '' : 's'} per day for the whole shop.`);
    if (has('max_order_age_days')) lines.push(`Only orders placed in the last ${l.max_order_age_days} days.`);
    const states = asArray<string>(l.allowed_fulfilment_states);
    if (states.length) lines.push(`Only orders that are: ${states.map((s) => FULFILMENT_STATE_LABELS[s] ?? s).join(', ')}.`);
    const countries = asArray<string>(l.allowed_countries);
    if (countries.length) lines.push(`Only orders shipping to ${countries.join(', ')}.`);
    if (l.require_sender_email_match !== false) lines.push('Only when the sender’s email matches the order email.');
    else lines.push('Even when the sender’s email does not match the order email.');
    if (lines.length === 1 && AMOUNT_ACTIONS.includes(actionKey)) lines.unshift('No amount limits.');
    return lines;
}

// ── Scopes ───────────────────────────────────────────────────────────────────

/** "write_orders, and write_merchant_managed_fulfillment_orders or write_third_party_fulfillment_orders" */
export function scopeGroupsText(groups: string[][] | null | undefined): string {
    const list = asArray<string[]>(groups)
        .map((g) => asArray<string>(g))
        .filter((g) => g.length)
        .map((g) => g.join(' or '));
    if (list.length <= 1) return list[0] ?? '';
    return `${list.slice(0, -1).join(', ')} and ${list[list.length - 1]}`;
}

// ── Audit ────────────────────────────────────────────────────────────────────

/** Statuses the audit filter offers (labels from lib/agentActions ACTION_STATUS_LABELS). */
export const AUDIT_STATUSES = [
    'pending_approval',
    'approved',
    'executing',
    'succeeded',
    'failed',
    'rejected',
    'expired',
    'unknown',
    'cancelled',
    'proposed',
];

export function routeLabel(route: string | null | undefined, approvedBy: string | null | undefined): string {
    if (route === 'unattended') return 'Ran on its own';
    if (route === 'approval') return approvedBy ? 'Approved by a person' : 'Needs a person';
    return '—';
}
