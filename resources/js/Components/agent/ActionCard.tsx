import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import {
    AlertTriangleIcon,
    CheckCircle2Icon,
    CircleHelpIcon,
    InfoIcon,
    OctagonAlertIcon,
    PencilIcon,
    RefreshCwIcon,
    ShieldCheckIcon,
    XCircleIcon,
} from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { ErrorLine, Spinner } from '@/Components/agent/LoopParts';
import { actionStatusLabel, actionStatusVariant, actionsApi, checkLabel, money, roleLabel } from '@/lib/agentActions';
import { asArray, asRecord, conversationUrl, shortDate, toApiError, type ApiError } from '@/lib/agentLoop';
import { cn } from '@/lib/utils';
import type { ActionExecutionDetail, ActionExecutionSummary, ActionWarning } from '@/types/agentActions';

// ── Warnings ─────────────────────────────────────────────────────────────────

const LEVEL_ORDER: Record<string, number> = { danger: 0, warning: 1, info: 2 };

function sortedWarnings(warnings: ActionWarning[]): ActionWarning[] {
    return [...warnings].filter((w) => w && w.text).sort((a, b) => (LEVEL_ORDER[a.level] ?? 3) - (LEVEL_ORDER[b.level] ?? 3));
}

/** Danger warnings are a solid red block that cannot be mistaken for a hint. */
export function ActionWarnings({ warnings }: { warnings: ActionWarning[] }) {
    const list = sortedWarnings(asArray<ActionWarning>(warnings));
    if (list.length === 0) return null;
    return (
        <ul className="space-y-1.5">
            {list.map((w, i) =>
                w.level === 'danger' ? (
                    <li
                        key={i}
                        role="alert"
                        className="flex items-start gap-2 rounded-lg border-2 border-destructive bg-destructive/10 px-3 py-2 text-sm text-foreground"
                    >
                        <OctagonAlertIcon className="mt-0.5 h-4 w-4 shrink-0 text-destructive" />
                        <span>
                            <span className="block text-xs font-bold uppercase tracking-wide text-destructive">Stop and check first</span>
                            {w.text}
                        </span>
                    </li>
                ) : (
                    <li
                        key={i}
                        className={cn(
                            'flex items-start gap-2 rounded-md border px-2.5 py-1.5 text-xs',
                            w.level === 'warning' ? 'border-warning/30 bg-warning/10' : 'border-border bg-muted/40',
                        )}
                    >
                        {w.level === 'warning' ? (
                            <AlertTriangleIcon className="mt-0.5 h-3.5 w-3.5 shrink-0 text-warning" />
                        ) : (
                            <InfoIcon className="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                        )}
                        <span>{w.text}</span>
                    </li>
                ),
            )}
        </ul>
    );
}

export function ActionStatusBadge({ status }: { status: string | null | undefined }) {
    return <Badge variant={actionStatusVariant(status)}>{actionStatusLabel(status)}</Badge>;
}

// ── Params editor ────────────────────────────────────────────────────────────

const PARAM_LABELS: Record<string, string> = {
    note: 'Note',
    add: 'Tags to add',
    remove: 'Tags to remove',
    first_name: 'First name',
    last_name: 'Last name',
    company: 'Company',
    address1: 'Address line 1',
    address2: 'Address line 2',
    city: 'City',
    province_code: 'State / province code',
    zip: 'Postcode',
    country_code: 'Country code (2 letters)',
    phone: 'Phone',
    reason: 'Reason',
    restock: 'Put the items back in stock',
    refund: 'Refund the payment',
    notify_customer: 'Email the customer from Shopify',
    line_items: 'Items',
    amount: 'Amount',
    shipping: 'Refund shipping',
    shipping_amount: 'Shipping amount',
    template: 'Discount template',
    value: 'Discount value',
    customer_specific: 'Only this customer can use it',
    code: 'Discount code (made by the system)',
    operation: 'Hold or release',
};

function enumOptions(actionKey: string, field: string): string[] | null {
    if (field === 'shipping') return ['none', 'full', 'amount'];
    if (field === 'operation') return ['hold', 'release'];
    if (actionKey === 'cancel_order' && field === 'reason') return ['CUSTOMER', 'INVENTORY', 'OTHER'];
    if (actionKey === 'fulfilment_hold' && field === 'reason') {
        return [
            'OTHER',
            'INCORRECT_ADDRESS',
            'AWAITING_RETURN_ITEMS',
            'HIGH_RISK_OF_FRAUD',
            'INVENTORY_OUT_OF_STOCK',
            'UNKNOWN_DELIVERY_DATE',
        ];
    }
    return null;
}

type Draft = Record<string, string | boolean>;

/** Editable copy of the params: scalars as strings/booleans, lists and objects as JSON text. */
function toDraft(params: Record<string, unknown>): Draft {
    const out: Draft = {};
    for (const [key, value] of Object.entries(params)) {
        if (typeof value === 'boolean') out[key] = value;
        else if (value === null || value === undefined) out[key] = '';
        else if (typeof value === 'object') out[key] = JSON.stringify(value, null, 2);
        else out[key] = String(value);
    }
    return out;
}

/** Back to params with the original types; returns the keys whose JSON does not parse. */
function fromDraft(draft: Draft, original: Record<string, unknown>): { params: Record<string, unknown>; bad: string[] } {
    const params: Record<string, unknown> = {};
    const bad: string[] = [];
    for (const [key, value] of Object.entries(draft)) {
        const before = original[key];
        if (typeof value === 'boolean') {
            params[key] = value;
        } else if (before !== null && typeof before === 'object') {
            try {
                params[key] = value.trim() === '' ? (Array.isArray(before) ? [] : {}) : JSON.parse(value);
            } catch {
                bad.push(key);
            }
        } else if (typeof before === 'number') {
            params[key] = value.trim() === '' ? null : Number(value);
        } else {
            params[key] = value === '' && (before === null || before === undefined) ? null : value;
        }
    }
    return { params, bad };
}

function fieldErrors(errors: Record<string, string[] | string> | unknown, field: string): string[] {
    const map = asRecord<string[] | string>(errors as Record<string, string[] | string>);
    return Object.entries(map)
        .filter(([key]) => key === field || key === `params.${field}` || key.startsWith(`${field}.`) || key.startsWith(`params.${field}.`))
        .flatMap(([, v]) => (Array.isArray(v) ? v : [String(v)]));
}

function ParamsEditor({
    actionKey,
    draft,
    original,
    onChange,
    errors,
}: {
    actionKey: string;
    draft: Draft;
    original: Record<string, unknown>;
    onChange: (next: Draft) => void;
    errors: Record<string, string[] | string> | unknown;
}) {
    const keys = Object.keys(draft);
    if (keys.length === 0) return <p className="text-xs text-muted-foreground">This action has no details to change.</p>;
    return (
        <div className="grid gap-2 sm:grid-cols-2">
            {keys.map((key) => {
                const value = draft[key];
                const label = PARAM_LABELS[key] ?? checkLabel(key);
                const errs = fieldErrors(errors, key);
                const id = `param-${actionKey}-${key}`;
                const locked = key === 'code';
                const options = enumOptions(actionKey, key);
                const isJson = original[key] !== null && typeof original[key] === 'object';
                return (
                    <div key={key} className={cn('space-y-1', (isJson || key === 'note' || key === 'reason') && 'sm:col-span-2')}>
                        {typeof value === 'boolean' ? (
                            <label className="flex items-center gap-2 text-xs">
                                <input
                                    type="checkbox"
                                    checked={value}
                                    onChange={(e) => onChange({ ...draft, [key]: e.target.checked })}
                                    className="h-3.5 w-3.5"
                                />
                                {label}
                            </label>
                        ) : (
                            <>
                                <label htmlFor={id} className="text-xs font-medium">
                                    {label}
                                </label>
                                {options ? (
                                    <select
                                        id={id}
                                        value={value}
                                        onChange={(e) => onChange({ ...draft, [key]: e.target.value })}
                                        className="h-8 w-full rounded-md border border-input bg-background px-2 text-xs"
                                    >
                                        {!options.includes(value) && <option value={value}>{value || '—'}</option>}
                                        {options.map((o) => (
                                            <option key={o} value={o}>
                                                {o}
                                            </option>
                                        ))}
                                    </select>
                                ) : isJson || key === 'note' || key === 'reason' ? (
                                    <Textarea
                                        id={id}
                                        rows={isJson ? 4 : 2}
                                        value={value}
                                        onChange={(e) => onChange({ ...draft, [key]: e.target.value })}
                                        className={cn('text-xs', isJson && 'font-mono')}
                                    />
                                ) : (
                                    <Input
                                        id={id}
                                        value={value}
                                        disabled={locked}
                                        inputMode={typeof original[key] === 'number' || key.includes('amount') ? 'decimal' : undefined}
                                        onChange={(e) => onChange({ ...draft, [key]: e.target.value })}
                                        className="h-8 text-xs"
                                    />
                                )}
                            </>
                        )}
                        {errs.map((e, i) => (
                            <p key={i} className="text-xs text-destructive">
                                {e}
                            </p>
                        ))}
                    </div>
                );
            })}
        </div>
    );
}

// ── State lines ──────────────────────────────────────────────────────────────

function StateLine({ e, detail }: { e: ActionExecutionSummary; detail: ActionExecutionDetail | null }) {
    const by = e.approved_by?.name;
    switch (e.status) {
        case 'approved':
            return (
                <p className="flex items-center gap-1.5 text-xs text-primary">
                    <Spinner /> Approved{by ? ` by ${by}` : ''}
                    {e.route === 'unattended' ? ' (within your limits, no person needed)' : ''}. Starting in Shopify…
                </p>
            );
        case 'executing':
            return (
                <p className="flex items-center gap-1.5 text-xs text-primary">
                    <Spinner /> Running in Shopify now. This usually takes a few seconds.
                </p>
            );
        case 'succeeded':
            return (
                <div className="space-y-1 text-xs">
                    <p className="flex items-center gap-1.5 text-success">
                        <CheckCircle2Icon className="h-3.5 w-3.5" /> Done in Shopify
                        {e.completed_at ? ` · ${shortDate(e.completed_at)}` : ''}
                        {by ? ` · approved by ${by}` : e.route === 'unattended' ? ' · ran on its own within your limits' : ''}
                    </p>
                    {typeof detail?.undo?.how === 'string' && detail.undo.how && (
                        <p className="text-muted-foreground">To undo: {detail.undo.how}</p>
                    )}
                    {!e.reversible && <p className="text-muted-foreground">This can’t be undone from here.</p>}
                </div>
            );
        case 'failed':
            return (
                <div className="space-y-0.5 rounded-md border border-destructive/30 bg-destructive/5 px-2.5 py-1.5 text-xs">
                    <p className="flex items-center gap-1.5 font-medium text-destructive">
                        <XCircleIcon className="h-3.5 w-3.5" /> It did not happen.
                    </p>
                    {e.error && <p>{e.error}</p>}
                    <p className="text-muted-foreground">
                        The conversation was handed to a person and the reply will be rewritten. Check the order in Shopify before doing
                        anything by hand.
                    </p>
                </div>
            );
        case 'unknown':
            return (
                <div className="space-y-0.5 rounded-md border border-warning/40 bg-warning/10 px-2.5 py-1.5 text-xs">
                    <p className="flex items-center gap-1.5 font-medium">
                        <CircleHelpIcon className="h-3.5 w-3.5 text-warning" /> We can’t tell yet whether Shopify did this.
                    </p>
                    <p>
                        Shopify did not answer clearly, so we are checking the order again automatically
                        {detail ? ` (check ${detail.reconcile_attempts || 0} of 5)` : ''}.{' '}
                        <span className="font-medium">Do not do it again by hand</span> until this says Done or Failed. If it stays like
                        this, look at the order in Shopify first.
                    </p>
                    {e.error && <p className="text-muted-foreground">{e.error}</p>}
                </div>
            );
        case 'rejected':
            return (
                <p className="text-xs text-muted-foreground">
                    Rejected{e.rejected_by ? ` by ${e.rejected_by.name}` : ''}
                    {e.rejected_at ? ` · ${shortDate(e.rejected_at)}` : ''}
                    {e.rejection_reason ? `: “${e.rejection_reason}”` : ''}. Nothing was changed in Shopify; the reply is being rewritten.
                </p>
            );
        case 'expired':
            return (
                <p className="text-xs text-muted-foreground">
                    Nobody approved this in time{e.expires_at ? ` (it expired ${shortDate(e.expires_at)})` : ''}. Nothing was changed in
                    Shopify.
                </p>
            );
        case 'cancelled':
            return (
                <p className="text-xs text-muted-foreground">
                    Cancelled{e.error ? `: ${e.error}` : ''}. Nothing was changed in Shopify.
                    {e.dry_run ? ' (This was a practice run; practice runs never touch Shopify.)' : ''}
                </p>
            );
        default:
            return null;
    }
}

// ── The card ─────────────────────────────────────────────────────────────────

export interface ActionCardProps {
    execution: ActionExecutionSummary;
    /** Called after every mutation so the parent re-fetches its list (never optimistic). */
    onChanged?: () => void;
    /** Show the conversation subject + link (approvals queue). */
    showConversation?: boolean;
    /** Start expanded (pending cards in the queue). */
    defaultOpen?: boolean;
}

/**
 * One proposed Shopify action: what it does, the evidence the agent cited,
 * the fresh precondition checks, warnings (danger first), why it needs a
 * person, who may approve, and Approve (with editable details and a
 * confirmation that restates the amount for money actions) / Reject / Re-check.
 */
export default function ActionCard({ execution, onChanged, showConversation = false, defaultOpen }: ActionCardProps) {
    const pending = execution.status === 'pending_approval';
    const [open, setOpen] = useState(defaultOpen ?? pending);
    const [detail, setDetail] = useState<ActionExecutionDetail | null>(null);
    const [loadError, setLoadError] = useState<ApiError | null>(null);
    const [busy, setBusy] = useState<null | 'approve' | 'reject' | 'refresh'>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [message, setMessage] = useState<string | null>(null);
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState<Draft>({});
    const [jsonErrors, setJsonErrors] = useState<string[]>([]);
    const [rejecting, setRejecting] = useState(false);
    const [rejectReason, setRejectReason] = useState('');
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [dangerAck, setDangerAck] = useState(false);
    const alive = useRef(true);

    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);

    const load = useCallback(async () => {
        try {
            const next = await actionsApi.execution(execution.id);
            if (!alive.current) return;
            setDetail(next);
            setLoadError(null);
        } catch (e) {
            if (alive.current) setLoadError(toApiError(e));
        }
    }, [execution.id]);

    // (Re)load the detail when the card is open and the execution changed on the server.
    useEffect(() => {
        if (open) void load();
    }, [open, load, execution.updated_at, execution.status]);

    // Whichever copy the server wrote last (the detail is re-read after every mutation).
    const shown: ActionExecutionSummary =
        detail && detail.id === execution.id && (detail.updated_at ?? '') >= (execution.updated_at ?? '') ? detail : execution;
    const originalParams = asRecord<unknown>(detail?.params as Record<string, unknown> | null | undefined);
    const paramErrors = asRecord<string[] | string>(detail?.param_errors as Record<string, string[] | string> | null | undefined);
    const hasParamErrors = Object.keys(paramErrors).length > 0;
    const amount = money(shown.amount, shown.currency);
    const isPending = shown.status === 'pending_approval';
    const canAct = isPending && shown.can_approve;
    const danger = shown.has_danger_warning || asArray<ActionWarning>(shown.warnings).some((w) => w?.level === 'danger');
    const needsConfirm = shown.money || danger || !shown.reversible;

    function startEditing() {
        setDraft(toDraft(originalParams));
        setJsonErrors([]);
        setEditing(true);
    }

    async function after(fn: () => Promise<{ message?: string } | unknown>, key: 'approve' | 'reject' | 'refresh') {
        setBusy(key);
        setError(null);
        setMessage(null);
        try {
            const result = (await fn()) as { message?: string } | undefined;
            if (alive.current && result && typeof result === 'object' && typeof result.message === 'string') setMessage(result.message);
            if (key !== 'refresh') {
                setEditing(false);
                setRejecting(false);
                setRejectReason('');
            }
            setConfirmOpen(false);
            return true;
        } catch (e) {
            if (alive.current) setError(toApiError(e));
            return false;
        } finally {
            // Always re-read: the server is the only source of truth for money actions.
            await load();
            onChanged?.();
            if (alive.current) setBusy(null);
        }
    }

    function editedParams(): Record<string, unknown> | null | false {
        if (!editing) return null;
        const { params, bad } = fromDraft(draft, originalParams);
        setJsonErrors(bad);
        return bad.length ? false : params;
    }

    function approveClicked() {
        const params = editedParams();
        if (params === false) return;
        if (hasParamErrors && !editing) {
            startEditing();
            return;
        }
        if (needsConfirm) {
            setDangerAck(false);
            setConfirmOpen(true);
            return;
        }
        void after(() => actionsApi.approve(shown.id, params), 'approve');
    }

    function approveConfirmed() {
        const params = editedParams();
        if (params === false) {
            setConfirmOpen(false);
            return;
        }
        void after(() => actionsApi.approve(shown.id, params), 'approve');
    }

    const reasons = asArray<{ code: string; label: string }>(shown.approval_reasons).filter((r) => r && r.label);
    const evidence = asArray<{ id: string; source: string; label: string; value: string }>(detail?.cited_evidence);
    const snapshot = detail?.precondition_snapshot ?? null;
    const checks = asArray<{ check: string; ok: boolean; detail?: string | null }>(snapshot?.checks);
    const serverErrors = error?.errors ?? {};

    return (
        <div
            className={cn(
                'rounded-lg border bg-card text-sm',
                danger && isPending ? 'border-destructive/60' : isPending ? 'border-primary/40' : 'border-border',
            )}
        >
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-expanded={open}
                className="flex w-full flex-wrap items-center gap-x-2 gap-y-1 px-3 py-2 text-left"
            >
                {danger && isPending && <OctagonAlertIcon className="h-4 w-4 shrink-0 text-destructive" aria-label="Warning" />}
                <span className="font-medium">{shown.label}</span>
                {shown.order_number && <span className="text-xs text-muted-foreground">#{shown.order_number.replace(/^#/, '')}</span>}
                {amount && <span className="text-xs font-semibold tabular-nums">{amount}</span>}
                <span className="ml-auto flex items-center gap-1.5">
                    {shown.money && <Badge variant="outline">Money</Badge>}
                    <ActionStatusBadge status={shown.status} />
                </span>
            </button>

            {open && (
                <div className="space-y-3 border-t border-border px-3 py-3">
                    <ActionWarnings warnings={asArray<ActionWarning>(shown.warnings)} />

                    {shown.description && <p className="text-sm">{shown.description}</p>}

                    {showConversation && shown.conversation_id && (
                        <p className="text-xs text-muted-foreground">
                            Conversation:{' '}
                            <Link href={conversationUrl(shown.conversation_id)} className="text-primary hover:underline">
                                {shown.conversation_subject || `#${shown.conversation_id}`}
                            </Link>
                            {shown.customer_email ? ` · ${shown.customer_email}` : ''}
                        </p>
                    )}

                    <StateLine e={shown} detail={detail} />

                    {detail?.reason && (
                        <div className="space-y-0.5">
                            <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                                Why the agent suggests it
                            </p>
                            <p className="text-xs">{detail.reason}</p>
                        </div>
                    )}

                    {evidence.length > 0 && (
                        <div className="space-y-0.5">
                            <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">What it is based on</p>
                            <ul className="space-y-0.5 text-xs">
                                {evidence.map((ev, i) => (
                                    <li key={`${ev.id}-${i}`}>
                                        <span className="text-muted-foreground">{ev.label || ev.id}:</span> {ev.value}
                                        {ev.source && <span className="text-muted-foreground"> ({ev.source})</span>}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {(checks.length > 0 || snapshot?.state_error) && (
                        <div className="space-y-0.5">
                            <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                                Checks on the order{snapshot?.at ? ` (${shortDate(snapshot.at)})` : ''}
                            </p>
                            {snapshot?.state_error && (
                                <p className="text-xs text-destructive">Could not read the order: {snapshot.state_error}</p>
                            )}
                            <ul className="space-y-0.5 text-xs">
                                {checks.map((c, i) => (
                                    <li key={`${c.check}-${i}`} className="flex items-start gap-1.5">
                                        {c.ok ? (
                                            <CheckCircle2Icon className="mt-0.5 h-3 w-3 shrink-0 text-success" />
                                        ) : (
                                            <XCircleIcon className="mt-0.5 h-3 w-3 shrink-0 text-destructive" />
                                        )}
                                        <span>
                                            {checkLabel(c.check)}
                                            {c.detail ? <span className="text-muted-foreground"> · {c.detail}</span> : null}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {isPending && reasons.length > 0 && (
                        <div className="space-y-0.5">
                            <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                                Why a person has to approve it
                            </p>
                            <ul className="list-disc space-y-0.5 pl-4 text-xs">
                                {reasons.map((r) => (
                                    <li key={r.code}>{r.label}</li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {isPending && (
                        <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <ShieldCheckIcon className="h-3.5 w-3.5" />
                            {shown.can_approve ? 'You can approve this.' : `Only ${roleLabel(shown.required_role)} can approve this.`}
                            {shown.expires_at ? ` Expires ${shortDate(shown.expires_at)}.` : ''}
                        </p>
                    )}

                    {detail?.params_edited && (
                        <p className="text-xs text-muted-foreground">The details were changed by a person before approval.</p>
                    )}

                    {canAct && hasParamErrors && !editing && (
                        <p className="text-xs text-destructive">The agent’s proposed details are not valid. Fix them before approving.</p>
                    )}

                    {canAct && editing && (
                        <div className="space-y-2 rounded-md border border-border bg-muted/30 p-2.5">
                            <p className="text-xs text-muted-foreground">
                                Change what will be sent to Shopify. Everything is checked again on the server before it runs.
                            </p>
                            <ParamsEditor
                                actionKey={shown.action_key}
                                draft={draft}
                                original={originalParams}
                                onChange={setDraft}
                                errors={Object.keys(serverErrors).length ? serverErrors : paramErrors}
                            />
                            {jsonErrors.length > 0 && (
                                <p className="text-xs text-destructive">
                                    {jsonErrors.map((k) => PARAM_LABELS[k] ?? k).join(', ')}: not valid. Keep the same format as before.
                                </p>
                            )}
                            {detail?.params_hint && (
                                <p className="font-mono text-[10px] text-muted-foreground">Format: {detail.params_hint}</p>
                            )}
                        </div>
                    )}

                    {loadError && <ErrorLine error={loadError} />}
                    {error && <ErrorLine error={error} />}
                    {message && !error && <p className="text-xs text-muted-foreground">{message}</p>}

                    {canAct && !rejecting && (
                        <div className="flex flex-wrap items-center gap-2">
                            <Button type="button" size="sm" disabled={busy !== null || !detail} onClick={approveClicked}>
                                {busy === 'approve' ? 'Approving…' : editing ? 'Approve with these details' : 'Approve…'}
                            </Button>
                            {!editing && detail && Object.keys(originalParams).length > 0 && (
                                <Button type="button" size="sm" variant="outline" disabled={busy !== null} onClick={startEditing}>
                                    <PencilIcon className="mr-1 h-3 w-3" /> Change details
                                </Button>
                            )}
                            {editing && (
                                <Button type="button" size="sm" variant="ghost" disabled={busy !== null} onClick={() => setEditing(false)}>
                                    Undo changes
                                </Button>
                            )}
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="text-destructive"
                                disabled={busy !== null}
                                onClick={() => setRejecting(true)}
                            >
                                Reject
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                disabled={busy !== null}
                                onClick={() => void after(() => actionsApi.refresh(shown.id), 'refresh')}
                                title="Read the order from Shopify again and re-run the checks (changes nothing)"
                            >
                                <RefreshCwIcon className={cn('mr-1 h-3 w-3', busy === 'refresh' && 'animate-spin')} /> Re-check order
                            </Button>
                        </div>
                    )}

                    {canAct && rejecting && (
                        <div className="space-y-2">
                            <label className="text-xs font-medium" htmlFor={`reject-${shown.id}`}>
                                Why not? (the agent learns from this and rewrites the reply without the action)
                            </label>
                            <Textarea
                                id={`reject-${shown.id}`}
                                rows={2}
                                value={rejectReason}
                                onChange={(e) => setRejectReason(e.target.value)}
                                placeholder="e.g. We only refund after the item is returned."
                            />
                            <div className="flex items-center gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="destructive"
                                    disabled={busy !== null || rejectReason.trim().length < 2}
                                    onClick={() => void after(() => actionsApi.reject(shown.id, rejectReason.trim()), 'reject')}
                                >
                                    {busy === 'reject' ? 'Rejecting…' : 'Reject'}
                                </Button>
                                <Button type="button" size="sm" variant="ghost" onClick={() => setRejecting(false)}>
                                    Never mind
                                </Button>
                            </div>
                        </div>
                    )}
                </div>
            )}

            <Dialog open={confirmOpen} onOpenChange={(v) => busy === null && setConfirmOpen(v)}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {shown.money && amount ? `Approve ${shown.label.toLowerCase()} of ${amount}?` : `Approve: ${shown.label}?`}
                        </DialogTitle>
                        <DialogDescription>This runs in Shopify straight away.</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3 text-sm">
                        <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 rounded-md border border-border bg-muted/30 p-3 text-xs">
                            {shown.order_number && (
                                <>
                                    <dt className="text-muted-foreground">Order</dt>
                                    <dd>#{shown.order_number.replace(/^#/, '')}</dd>
                                </>
                            )}
                            {amount && (
                                <>
                                    <dt className="text-muted-foreground">Amount</dt>
                                    <dd className="text-base font-semibold tabular-nums">{amount}</dd>
                                </>
                            )}
                            {shown.customer_email && (
                                <>
                                    <dt className="text-muted-foreground">Customer</dt>
                                    <dd>{shown.customer_email}</dd>
                                </>
                            )}
                        </dl>
                        {shown.description && <p className="text-xs">{shown.description}</p>}
                        {editing && (
                            <p className="text-xs text-muted-foreground">You changed the details; the server re-checks them first.</p>
                        )}
                        {!shown.reversible && <p className="text-xs font-medium text-destructive">This can’t be undone.</p>}
                        {danger && (
                            <>
                                <ActionWarnings warnings={asArray<ActionWarning>(shown.warnings).filter((w) => w?.level === 'danger')} />
                                <label className="flex items-start gap-2 text-xs">
                                    <input
                                        type="checkbox"
                                        className="mt-0.5 h-3.5 w-3.5"
                                        checked={dangerAck}
                                        onChange={(e) => setDangerAck(e.target.checked)}
                                    />
                                    I have checked the warning above and still want to go ahead.
                                </label>
                            </>
                        )}
                        {error && <ErrorLine error={error} />}
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" disabled={busy !== null} onClick={() => setConfirmOpen(false)}>
                            Cancel
                        </Button>
                        <Button type="button" disabled={busy !== null || (danger && !dangerAck)} onClick={approveConfirmed}>
                            {busy === 'approve' ? 'Approving…' : shown.money && amount ? `Yes, approve ${amount}` : 'Yes, approve'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
