import { useState, type FormEvent, type ReactNode } from 'react';
import { PlusIcon, Trash2Icon } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Switch } from '@/Components/ui/switch';
import { ErrorLine } from '@/Components/agent/LoopParts';
import { asArray, useAction } from '@/lib/agentLoop';
import { ROLE_OPTION_LABELS, fieldErrors, policiesApi } from '@/lib/agentActionPolicies';
import type { ActionHardLimits, ActionSettingsData, DiscountTemplate } from '@/types/agentActionPolicies';
import { FieldError, Section, formatDate } from '../../AgentParts';

/** Only used when GET /agent/actions/settings did not send `hard_limits` (the server enforces them either way). */
const FALLBACK_HARD_LIMITS: ActionHardLimits = { max_refund_ceiling: 5000, max_discount_percentage: 50, max_discount_expiry_days: 90 };

function NumberField({
    id,
    label,
    help,
    value,
    onChange,
    error,
    step = '1',
    min = 0,
    max,
}: {
    id: string;
    label: string;
    help?: ReactNode;
    value: string;
    onChange: (v: string) => void;
    error?: string;
    step?: string;
    min?: number;
    max?: number;
}) {
    return (
        <div className="space-y-1">
            <label htmlFor={id} className="text-xs font-medium">
                {label}
            </label>
            <Input
                id={id}
                type="number"
                inputMode="decimal"
                min={min}
                max={max}
                step={step}
                value={value}
                aria-invalid={!!error}
                onChange={(e) => onChange(e.target.value)}
            />
            {help && <p className="text-xs text-muted-foreground">{help}</p>}
            <FieldError message={error} />
        </div>
    );
}

function RoleSelect({
    id,
    label,
    help,
    roles,
    value,
    onChange,
    error,
}: {
    id: string;
    label: string;
    help: ReactNode;
    roles: string[];
    value: string;
    onChange: (v: string) => void;
    error?: string;
}) {
    const options = Array.from(new Set([...roles, value].filter(Boolean)));
    return (
        <div className="space-y-1">
            <label htmlFor={id} className="text-xs font-medium">
                {label}
            </label>
            <select
                id={id}
                className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                value={value}
                onChange={(e) => onChange(e.target.value)}
            >
                {options.map((role) => (
                    <option key={role} value={role}>
                        {ROLE_OPTION_LABELS[role] ?? role}
                    </option>
                ))}
            </select>
            <p className="text-xs text-muted-foreground">{help}</p>
            <FieldError message={error} />
        </div>
    );
}

/** Who approves, how long requests wait, the refund ceiling, the warehouse window and the audit note. */
export function ApprovalSettingsPanel({
    settings,
    roles,
    hardLimits,
    onSaved,
}: {
    settings: ActionSettingsData;
    roles: string[];
    hardLimits?: ActionHardLimits;
    onSaved: () => void;
}) {
    const MAX_REFUND_CEILING = (hardLimits ?? FALLBACK_HARD_LIMITS).max_refund_ceiling;
    const initial = {
        approver_role_money: settings.approver_role_money,
        approver_role_other: settings.approver_role_other,
        approval_expiry_hours: String(settings.approval_expiry_hours ?? ''),
        refund_hard_ceiling: String(settings.refund_hard_ceiling ?? ''),
        unattended_order_age_minutes: String(settings.unattended_order_age_minutes ?? ''),
        audit_note: !!settings.audit_note,
    };
    const [form, setForm] = useState(initial);
    const { busy, error, run } = useAction();
    const dirty = JSON.stringify(form) !== JSON.stringify(initial);

    const client: Record<string, string> = {};
    const hours = Number(form.approval_expiry_hours);
    if (!Number.isInteger(hours) || hours < 1 || hours > 720) client.approval_expiry_hours = 'Between 1 and 720 hours.';
    const ceiling = Number(form.refund_hard_ceiling);
    if (form.refund_hard_ceiling.trim() === '' || !Number.isFinite(ceiling) || ceiling < 0 || ceiling > MAX_REFUND_CEILING) {
        client.refund_hard_ceiling = `Between 0 and ${MAX_REFUND_CEILING}.`;
    }
    const minutes = Number(form.unattended_order_age_minutes);
    if (!Number.isInteger(minutes) || minutes < 0 || minutes > 10080) client.unattended_order_age_minutes = 'Between 0 and 10080 minutes.';
    const errors = { ...fieldErrors(error), ...client };

    async function save(e: FormEvent) {
        e.preventDefault();
        const ok = await run('save', () =>
            policiesApi.updateSettings({
                approver_role_money: form.approver_role_money,
                approver_role_other: form.approver_role_other,
                approval_expiry_hours: hours,
                refund_hard_ceiling: form.refund_hard_ceiling.trim(),
                unattended_order_age_minutes: minutes,
                audit_note: form.audit_note,
            }),
        );
        if (ok) onSaved();
    }

    return (
        <Section
            title="Who approves, and other safety settings"
            description={
                settings.updated_at
                    ? `Last changed ${formatDate(settings.updated_at)}.`
                    : 'These are the starting values; nobody has changed them yet.'
            }
        >
            <form onSubmit={save} className="space-y-5">
                <div className="grid gap-4 sm:grid-cols-2">
                    <RoleSelect
                        id="approver_role_money"
                        label="Who may approve refunds, cancellations, address changes, discounts and replacements"
                        help="With two people on the team, the owner carries the financial risk, so by default only admins (the owner) approve these. Choose “Anyone on the team” to let your colleague approve refunds too."
                        roles={roles}
                        value={form.approver_role_money}
                        onChange={(v) => setForm({ ...form, approver_role_money: v })}
                        error={errors.approver_role_money}
                    />
                    <RoleSelect
                        id="approver_role_other"
                        label="Who may approve order notes, tags and shipping holds"
                        help="Low-risk changes that only staff see. By default anyone on the team can approve them."
                        roles={roles}
                        value={form.approver_role_other}
                        onChange={(v) => setForm({ ...form, approver_role_other: v })}
                        error={errors.approver_role_other}
                    />
                    <NumberField
                        id="approval_expiry_hours"
                        label="Requests expire after (hours)"
                        help="A request nobody approves in time expires and the reply is rewritten without it."
                        value={form.approval_expiry_hours}
                        onChange={(v) => setForm({ ...form, approval_expiry_hours: v })}
                        error={errors.approval_expiry_hours}
                        min={1}
                        max={720}
                    />
                    <NumberField
                        id="refund_hard_ceiling"
                        label="Refunds above this always need a person (shop currency)"
                        help={`Applies to refunds and to cancellations that refund, whatever the action limits say. At most ${MAX_REFUND_CEILING}.`}
                        value={form.refund_hard_ceiling}
                        onChange={(v) => setForm({ ...form, refund_hard_ceiling: v })}
                        error={errors.refund_hard_ceiling}
                        step="0.01"
                        max={MAX_REFUND_CEILING}
                    />
                </div>

                <div className="space-y-2 rounded-lg border border-border p-3">
                    <NumberField
                        id="unattended_order_age_minutes"
                        label="Warehouse window: address changes and cancellations only run on their own for orders younger than (minutes)"
                        value={form.unattended_order_age_minutes}
                        onChange={(v) => setForm({ ...form, unattended_order_age_minutes: v })}
                        error={errors.unattended_order_age_minutes}
                        max={10080}
                    />
                    <div className="space-y-1 text-xs text-muted-foreground">
                        <p>
                            <span className="font-medium text-foreground">Why this exists (the 4PX problem).</span> Once an order has gone
                            to the 4PX warehouse, changing its address or cancelling it in Shopify may never reach the warehouse, so the
                            parcel can still go out to the old address or ship after it was cancelled.
                        </p>
                        <p>
                            So the agent only changes an address or cancels on its own when the order has not shipped, nothing has been sent
                            to a warehouse, fusterai can see the order’s fulfilment details, and the order is younger than this many
                            minutes. 4PX can collect orders through its own app without Shopify showing it, so a short window is the main
                            protection. In every other case a person decides, and the approval card warns that the warehouse may already
                            have the order.
                        </p>
                        <p>Still to decide: your real rule (for example “never change an address after X; email 4PX first”).</p>
                    </div>
                </div>

                <div className="flex items-start gap-3">
                    <Switch id="audit_note" checked={form.audit_note} onCheckedChange={(v) => setForm({ ...form, audit_note: v })} />
                    <div>
                        <label htmlFor="audit_note" className="text-xs font-medium">
                            Add a line to the Shopify order note after each action
                        </label>
                        <p className="text-xs text-muted-foreground">
                            Says what fusterai did and who approved it, so anyone looking at the order in Shopify can see it.
                        </p>
                        <FieldError message={errors.audit_note} />
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Button type="submit" disabled={!dirty || busy !== null || Object.keys(client).length > 0}>
                        {busy === 'save' ? 'Saving…' : 'Save approval settings'}
                    </Button>
                    {dirty && (
                        <Button type="button" variant="ghost" onClick={() => setForm(initial)} disabled={busy !== null}>
                            Undo changes
                        </Button>
                    )}
                    {dirty && !busy && <span className="text-xs text-muted-foreground">Not saved yet</span>}
                </div>
                <ErrorLine error={error} />
            </form>
        </Section>
    );
}

interface TemplateDraft {
    key: string;
    label: string;
    type: string;
    value: string;
    max_value: string;
    expires_days: string;
    minimum_subtotal: string;
}

function toTemplateDraft(t: DiscountTemplate): TemplateDraft {
    const s = (v: unknown) => (v === null || v === undefined ? '' : String(v));
    return {
        key: s(t.key),
        label: s(t.label),
        type: t.type === 'fixed' ? 'fixed' : 'percentage',
        value: s(t.value),
        max_value: s(t.max_value),
        expires_days: s(t.expires_days),
        minimum_subtotal: s(t.minimum_subtotal),
    };
}

function templateErrors(list: TemplateDraft[], limits: ActionHardLimits): Record<string, string> {
    const MAX_DISCOUNT_PERCENT = limits.max_discount_percentage;
    const MAX_EXPIRY_DAYS = limits.max_discount_expiry_days;
    const errors: Record<string, string> = {};
    const seen = new Set<string>();
    list.forEach((t, i) => {
        const p = `discount_templates.${i}`;
        if (!/^[a-z0-9_]{1,40}$/.test(t.key)) errors[`${p}.key`] = 'Lower-case letters, numbers and _ only (e.g. sorry10).';
        else if (seen.has(t.key)) errors[`${p}.key`] = 'Each template needs its own key.';
        seen.add(t.key);
        if (!t.label.trim()) errors[`${p}.label`] = 'Give it a name.';
        const value = Number(t.value);
        if (t.value.trim() === '' || !Number.isFinite(value) || value <= 0) errors[`${p}.value`] = 'More than 0.';
        const max = t.max_value.trim() === '' ? value : Number(t.max_value);
        if (t.max_value.trim() !== '' && (!Number.isFinite(max) || max <= 0)) errors[`${p}.max_value`] = 'More than 0, or leave empty.';
        else if (Number.isFinite(value) && max < value) errors[`${p}.max_value`] = 'Cannot be below the normal value.';
        else if (t.type === 'percentage' && max > MAX_DISCOUNT_PERCENT)
            errors[t.max_value.trim() === '' ? `${p}.value` : `${p}.max_value`] =
                `Percentage discounts are capped at ${MAX_DISCOUNT_PERCENT}%.`;
        const days = Number(t.expires_days);
        if (!Number.isInteger(days) || days < 1 || days > MAX_EXPIRY_DAYS) errors[`${p}.expires_days`] = `1 to ${MAX_EXPIRY_DAYS} days.`;
        if (t.minimum_subtotal.trim() !== '' && !(Number(t.minimum_subtotal) >= 0))
            errors[`${p}.minimum_subtotal`] = '0 or more, or leave empty.';
    });
    return errors;
}

function toPayload(t: TemplateDraft): DiscountTemplate {
    return {
        key: t.key.trim(),
        label: t.label.trim(),
        type: t.type,
        value: Number(t.value),
        max_value: t.max_value.trim() === '' ? null : Number(t.max_value),
        expires_days: Number(t.expires_days),
        minimum_subtotal: t.minimum_subtotal.trim() === '' ? null : Number(t.minimum_subtotal),
    };
}

/** The discount codes the agent may offer. Saved as a whole list (replaces the old one). */
export function DiscountTemplatesPanel({
    settings,
    hardLimits,
    onSaved,
}: {
    settings: ActionSettingsData;
    hardLimits?: ActionHardLimits;
    onSaved: () => void;
}) {
    const limits = hardLimits ?? FALLBACK_HARD_LIMITS;
    const MAX_DISCOUNT_PERCENT = limits.max_discount_percentage;
    const MAX_EXPIRY_DAYS = limits.max_discount_expiry_days;
    const initial = asArray<DiscountTemplate>(settings.discount_templates).map(toTemplateDraft);
    const [list, setList] = useState<TemplateDraft[]>(initial);
    const { busy, error, run } = useAction();
    const dirty = JSON.stringify(list) !== JSON.stringify(initial);
    const client = templateErrors(list, limits);
    const errors = { ...fieldErrors(error), ...client };

    function update(i: number, patch: Partial<TemplateDraft>) {
        setList(list.map((t, j) => (j === i ? { ...t, ...patch } : t)));
    }

    async function save() {
        const ok = await run('save', () => policiesApi.updateSettings({ discount_templates: list.map(toPayload) }));
        if (ok) onSaved();
    }

    return (
        <Section
            title="Discount codes the agent may offer"
            description="The agent can only create codes from these templates. Every code works once, for one customer, and expires. The code itself is made by fusterai, never by the AI."
        >
            {list.length === 0 && (
                <p className="text-xs text-muted-foreground">No templates yet, so the agent cannot offer a discount code.</p>
            )}
            <ul className="space-y-3">
                {list.map((t, i) => {
                    const p = `discount_templates.${i}`;
                    const unit = t.type === 'percentage' ? '%' : '(shop currency)';
                    return (
                        <li key={i} className="space-y-3 rounded-lg border border-border p-3">
                            <div className="grid gap-3 sm:grid-cols-3">
                                <div className="space-y-1">
                                    <label className="text-xs font-medium" htmlFor={`${p}.label`}>
                                        Name
                                    </label>
                                    <Input
                                        id={`${p}.label`}
                                        value={t.label}
                                        placeholder="Sorry 10%"
                                        onChange={(e) => update(i, { label: e.target.value })}
                                    />
                                    <FieldError message={errors[`${p}.label`]} />
                                </div>
                                <div className="space-y-1">
                                    <label className="text-xs font-medium" htmlFor={`${p}.key`}>
                                        Short key
                                    </label>
                                    <Input
                                        id={`${p}.key`}
                                        value={t.key}
                                        placeholder="sorry10"
                                        onChange={(e) => update(i, { key: e.target.value.toLowerCase() })}
                                    />
                                    <FieldError message={errors[`${p}.key`]} />
                                </div>
                                <div className="space-y-1">
                                    <label className="text-xs font-medium" htmlFor={`${p}.type`}>
                                        Kind
                                    </label>
                                    <select
                                        id={`${p}.type`}
                                        className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                                        value={t.type}
                                        onChange={(e) => update(i, { type: e.target.value })}
                                    >
                                        <option value="percentage">Percentage off</option>
                                        <option value="fixed">Fixed amount off</option>
                                    </select>
                                    <FieldError message={errors[`${p}.type`]} />
                                </div>
                                <NumberField
                                    id={`${p}.value`}
                                    label={`Normal value ${unit}`}
                                    value={t.value}
                                    onChange={(v) => update(i, { value: v })}
                                    error={errors[`${p}.value`]}
                                    step="0.01"
                                />
                                <NumberField
                                    id={`${p}.max_value`}
                                    label={`Most the agent may give ${unit}`}
                                    help={
                                        t.type === 'percentage'
                                            ? `Empty = the normal value. Never above ${MAX_DISCOUNT_PERCENT}%.`
                                            : 'Empty = the normal value.'
                                    }
                                    value={t.max_value}
                                    onChange={(v) => update(i, { max_value: v })}
                                    error={errors[`${p}.max_value`]}
                                    step="0.01"
                                />
                                <NumberField
                                    id={`${p}.expires_days`}
                                    label="Expires after (days)"
                                    help={`At most ${MAX_EXPIRY_DAYS}.`}
                                    value={t.expires_days}
                                    onChange={(v) => update(i, { expires_days: v })}
                                    error={errors[`${p}.expires_days`]}
                                    min={1}
                                    max={MAX_EXPIRY_DAYS}
                                />
                                <NumberField
                                    id={`${p}.minimum_subtotal`}
                                    label="Minimum order (shop currency, optional)"
                                    value={t.minimum_subtotal}
                                    onChange={(v) => update(i, { minimum_subtotal: v })}
                                    error={errors[`${p}.minimum_subtotal`]}
                                    step="0.01"
                                />
                            </div>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                className="text-muted-foreground hover:text-destructive"
                                onClick={() => setList(list.filter((_, j) => j !== i))}
                            >
                                <Trash2Icon className="h-3.5 w-3.5" /> Remove this template
                            </Button>
                        </li>
                    );
                })}
            </ul>
            <div className="flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={list.length >= 20}
                    onClick={() =>
                        setList([
                            ...list,
                            { key: '', label: '', type: 'percentage', value: '', max_value: '', expires_days: '30', minimum_subtotal: '' },
                        ])
                    }
                >
                    <PlusIcon className="h-3.5 w-3.5" /> Add a template
                </Button>
                <Button type="button" disabled={!dirty || busy !== null || Object.keys(client).length > 0} onClick={() => void save()}>
                    {busy === 'save' ? 'Saving…' : 'Save discount templates'}
                </Button>
                {dirty && (
                    <Button type="button" variant="ghost" onClick={() => setList(initial)} disabled={busy !== null}>
                        Undo changes
                    </Button>
                )}
                {dirty && !busy && <span className="text-xs text-muted-foreground">Not saved yet (saving replaces the whole list)</span>}
            </div>
            <ErrorLine error={error} />
        </Section>
    );
}
