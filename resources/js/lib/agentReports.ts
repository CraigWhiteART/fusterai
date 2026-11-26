// Client + labels for the AI performance report and the shadow report
// (docs/agent-qa.md "Report", docs/agent-autosend.md "Shadow report").

import type {
    PerformanceReportPayload,
    QaAggregatesPayload,
    ReportGroup,
    ShadowPlaybookRow,
    ShadowReportPayload,
} from '@/types/agentReports';

// ── API ──────────────────────────────────────────────────────────────────────

export interface ReportQuery {
    from: string;
    to: string;
    group: ReportGroup;
    playbook_id: number | null;
}

function clean(params: object): Record<string, string | number> {
    return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== '')) as Record<
        string,
        string | number
    >;
}

export const PERFORMANCE_CSV_URL = '/agent/reports/performance.csv';

export const reportsApi = {
    performance: (q: ReportQuery) =>
        window.axios.get<PerformanceReportPayload>('/agent/reports/performance', { params: clean(q) }).then((r) => r.data),
    qaAggregates: (q: Pick<ReportQuery, 'from' | 'to' | 'playbook_id'>) =>
        window.axios.get<QaAggregatesPayload>('/agent/qa/aggregates', { params: clean(q) }).then((r) => r.data),
    shadowReport: (days: number, worst = 10) =>
        window.axios.get<ShadowReportPayload>('/agent/autosend/shadow-report', { params: { days, worst } }).then((r) => r.data),
};

/** The CSV download URL for the same query as the JSON report. */
export function performanceCsvUrl(q: ReportQuery): string {
    const query = new URLSearchParams(Object.entries(clean(q)).map(([k, v]) => [k, String(v)])).toString();
    return query ? `${PERFORMANCE_CSV_URL}?${query}` : PERFORMANCE_CSV_URL;
}

// ── Numbers and dates ────────────────────────────────────────────────────────

export function num(value: unknown): number {
    return typeof value === 'number' && Number.isFinite(value) ? value : 0;
}

export function numOrNull(value: unknown): number | null {
    return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

/** 1,284 */
export function count(value: unknown): string {
    return num(value).toLocaleString();
}

/** "4.3 / 5" */
export function score5(value: number | null | undefined): string {
    return typeof value === 'number' && Number.isFinite(value) ? `${value.toFixed(1)} / 5` : '—';
}

/** 45 seconds, 3 minutes, 1 hour 20 minutes, 2 days 4 hours. */
export function humanDuration(seconds: number | null | undefined): string {
    if (typeof seconds !== 'number' || !Number.isFinite(seconds) || seconds < 0) return '—';
    const s = Math.round(seconds);
    const unit = (n: number, word: string) => `${n} ${word}${n === 1 ? '' : 's'}`;
    if (s < 60) return unit(s, 'second');
    const minutes = Math.round(s / 60);
    if (minutes < 60) return unit(minutes, 'minute');
    const hours = Math.floor(minutes / 60);
    const restMinutes = minutes % 60;
    if (hours < 24) return restMinutes ? `${unit(hours, 'hour')} ${unit(restMinutes, 'minute')}` : unit(hours, 'hour');
    const days = Math.floor(hours / 24);
    const restHours = hours % 24;
    return restHours ? `${unit(days, 'day')} ${unit(restHours, 'hour')}` : unit(days, 'day');
}

/** YYYY-MM-DD in the browser's time zone. */
export function isoDate(d: Date): string {
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/** The last `days` days through today (the server's default range is the same for 30). */
export function presetRange(days: number): { from: string; to: string } {
    const to = new Date();
    const from = new Date(to);
    from.setDate(from.getDate() - (days - 1));
    return { from: isoDate(from), to: isoDate(to) };
}

export function isIsoDate(value: string | null | undefined): value is string {
    return !!value && /^\d{4}-\d{2}-\d{2}$/.test(value) && !Number.isNaN(new Date(`${value}T00:00:00`).getTime());
}

/** "3 Nov" from "2026-11-03" (local date, no time-zone shift). */
export function dayLabel(value: string | null | undefined, withYear = false): string {
    if (!value) return '';
    const d = new Date(`${value.slice(0, 10)}T00:00:00`);
    if (Number.isNaN(d.getTime())) return value;
    return d.toLocaleDateString(
        undefined,
        withYear ? { day: 'numeric', month: 'short', year: 'numeric' } : { day: 'numeric', month: 'short' },
    );
}

export function periodLabel(value: string | null | undefined, group: ReportGroup): string {
    if (!value) return '';
    return group === 'week' ? `Week of ${dayLabel(value)}` : dayLabel(value);
}

// ── Labels ───────────────────────────────────────────────────────────────────

export const ROLE_LABELS: Record<string, string> = {
    writer: 'Writing drafts',
    triage: 'Reading and sorting',
    validator: 'Checking drafts',
    simulation_judge: 'Judging tests and QA',
    manager: 'Manager chat and proposals',
    history_extraction: 'Learning from history',
    unattributed: 'Other',
};

export const PURPOSE_LABELS: Record<string, string> = {
    live: 'Drafts on real email',
    shadow: 'Shadow practice',
    preview: 'Previews',
    simulation: 'Simulations',
    test: 'Playbook tests',
    qa: 'Quality checks',
    qa_proposals: 'Fixes suggested by quality checks',
    manager_chat: 'Manager chat',
    feedback_routing: 'Sorting your feedback',
    conflict_check: 'Checking proposals for clashes',
    simulation_judge: 'Judging simulations',
    mining: 'Learning from history',
};

export const AUTHOR_LABELS: Record<string, { label: string; help: string }> = {
    ai: { label: 'AI reply, sent as written', help: 'The agent wrote it and nobody changed it.' },
    ai_edited: { label: 'AI draft, edited by a person', help: 'The agent drafted it and someone changed it before sending.' },
    human: { label: 'Written by a person', help: 'Someone on your team wrote it without an AI draft.' },
};

export const AUTHOR_ORDER = ['ai', 'ai_edited', 'human'] as const;

/** Plain names for the keys in `definitions`. */
export const DEFINITION_LABELS: Record<string, string> = {
    conversations_received: 'Conversations received',
    drafts_produced: 'Drafts written',
    conversations_with_draft: 'Conversations with a draft',
    conversations_sent_as_is: 'Sent as written',
    automation_rate: 'Sent without changes (automation rate)',
    auto_sent: 'Sent automatically',
    auto_sent_rate: 'Share sent automatically',
    handovers: 'Handed to a person',
    feedback: 'What people did with drafts',
    edit_rate: 'Edited before sending',
    avg_percent_changed: 'How much was changed',
    gmail_reply_rate: 'Answered in Gmail instead',
    qa: 'Reply quality',
    cost: 'AI cost',
    resolved_conversations: 'Resolved conversations',
    cost_per_resolved_usd: 'Cost per resolved conversation',
    time_to_first_draft: 'Time to first draft',
    auto_send_decisions: 'Chosen for automatic sending',
    actions: 'Shopify actions',
};

export function labelFor(map: Record<string, string>, key: string): string {
    if (map[key]) return map[key];
    const s = key.replace(/[_.]/g, ' ').trim();
    return s ? s[0].toUpperCase() + s.slice(1) : key;
}

export const PLAYBOOK_MODE_LABELS: Record<string, string> = {
    off: 'Off',
    draft: 'Drafts only',
    auto: 'Auto',
};

// ── Chart colours ────────────────────────────────────────────────────────────
// Categorical slots 1 and 2 of the dataviz reference palette, stepped per
// theme (the app's --chart-* tokens are one blue ramp, identical in both
// themes, so they cannot tell two series apart). Validated with the dataviz
// validator: light against the white card (#ffffff), dark against the dark
// card (oklch 0.205 ≈ #171717): all checks pass, CVD ΔE ≥ 24.
export const SERIES_COLORS = {
    blue: { light: '#2a78d6', dark: '#3987e5' },
    orange: { light: '#eb6834', dark: '#d95926' },
} as const;

// ── Shadow readiness ─────────────────────────────────────────────────────────

/** A playbook needs this many comparisons before we say anything about it. */
export const READY_MIN_COMPARED = 20;
/** …and this agreement rate to be called "a good candidate". */
export const READY_MIN_AGREEMENT = 0.9;
/** Below this it is "not ready yet"; between the two it is "getting close". */
export const CLOSE_MIN_AGREEMENT = 0.75;
/** The would-have-auto-sent agreement only counts once it has this many comparisons. */
export const READY_MIN_AUTO_COMPARED = 10;

export type Readiness = 'good' | 'close' | 'not_ready' | 'too_few' | 'no_playbook';

export function readinessOf(row: ShadowPlaybookRow): { level: Readiness; text: string } {
    const compared = num(row.compared);
    const rate = numOrNull(row.agreement_rate);
    const autoCompared = num(row.would_auto_send_compared);
    const autoRate = numOrNull(row.would_auto_send_agreement_rate);
    const percent = (v: number) => `${Math.round(v * 100)}%`;

    if (!row.playbook) {
        return {
            level: 'no_playbook',
            text: 'No playbook matched these emails, so they always go to a person. A new playbook could cover them.',
        };
    }
    if (compared < READY_MIN_COMPARED || rate === null) {
        return {
            level: 'too_few',
            text:
                compared === 0
                    ? 'Not compared with your team yet.'
                    : `Too few comparisons yet (${compared} of the ${READY_MIN_COMPARED} we look for). Keep Shadow mode on.`,
        };
    }
    const base = `Agrees with your team ${percent(rate)} of the time on ${compared} replies`;
    const autoWeak = autoCompared >= READY_MIN_AUTO_COMPARED && autoRate !== null && autoRate < READY_MIN_AGREEMENT;
    if (rate >= READY_MIN_AGREEMENT && !autoWeak) return { level: 'good', text: `${base}. A good candidate.` };
    if (rate >= READY_MIN_AGREEMENT && autoWeak && autoRate !== null) {
        return {
            level: 'close',
            text: `${base}, but only ${percent(autoRate)} on the ${autoCompared} it would have sent by itself. Look at those first.`,
        };
    }
    if (rate >= CLOSE_MIN_AGREEMENT) return { level: 'close', text: `${base}. Getting close: read its disagreements below.` };
    return { level: 'not_ready', text: `${base}. Not ready yet: fix what its disagreements show first.` };
}

export const READINESS_LABELS: Record<Readiness, string> = {
    good: 'Good candidate',
    close: 'Getting close',
    not_ready: 'Not ready',
    too_few: 'Too few comparisons',
    no_playbook: 'No playbook',
};
