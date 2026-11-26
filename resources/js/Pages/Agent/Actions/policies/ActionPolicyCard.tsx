import { useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import { LockIcon, Trash2Icon } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Disclosure, ErrorLine } from '@/Components/agent/LoopParts';
import { cn } from '@/lib/utils';
import { asArray, useAction } from '@/lib/agentLoop';
import {
    EFFECTIVE_SOURCE_LABELS,
    FORCED_LABELS,
    FULFILMENT_STATE_LABELS,
    MODE_HELP,
    MODE_LABELS,
    MODE_ORDER,
    asMode,
    describeLimits,
    draftErrors,
    fieldErrors,
    fromDraft,
    limitFieldsFor,
    modeLabel,
    policiesApi,
    requiredForAlways,
    scopeGroupsText,
    toDraft,
    type LimitDraft,
    type LimitField,
} from '@/lib/agentActionPolicies';
import type {
    ActionPolicyEntry,
    ActionSettingsData,
    CatalogueAction,
    Policy,
    PolicyMode,
    UnattendedGates,
} from '@/types/agentActionPolicies';
import { FieldError, formatDate } from '../../AgentParts';
import { AlwaysConfirmDialog } from './AlwaysConfirmDialog';

function ActionBadges({ action, entry }: { action: CatalogueAction | undefined; entry: ActionPolicyEntry }) {
    const money = action?.money ?? entry.money;
    return (
        <div className="flex flex-wrap gap-1">
            {money && <Badge variant="outline">Money</Badge>}
            {action && !action.reversible && <Badge variant="destructive">Can’t be undone</Badge>}
            {action?.reply_waits && <Badge variant="secondary">Reply waits for it</Badge>}
            {action?.warehouse_sensitive && <Badge variant="secondary">Warehouse-sensitive (4PX)</Badge>}
        </div>
    );
}

function Availability({ action, connected }: { action: CatalogueAction | undefined; connected: boolean }) {
    if (!action || action.available) return null;
    const missing = scopeGroupsText(action.missing_scopes) || scopeGroupsText(action.required_scopes);
    return (
        <div className="space-y-1 rounded-lg border border-warning/40 bg-warning/10 p-3 text-xs">
            <p className="font-medium">Not available yet: {action.unavailable_reason ?? 'Shopify does not allow it.'}</p>
            {connected ? (
                missing && (
                    <p className="text-muted-foreground">
                        To allow it: in the Shopify Dev Dashboard, open the app → Versions → create a version that adds{' '}
                        <span className="font-mono text-foreground">{missing}</span>, release it, approve the update on your store, then
                        click “Test connection” on{' '}
                        <Link href="/agent/connections" className="text-primary underline">
                            Connections
                        </Link>
                        . Until then the agent’s requests for this are cancelled, whatever you choose below.
                    </p>
                )
            ) : (
                <p className="text-muted-foreground">
                    Connect Shopify on{' '}
                    <Link href="/agent/connections" className="text-primary underline">
                        Connections
                    </Link>{' '}
                    first.
                </p>
            )}
        </div>
    );
}

function ModePicker({
    name,
    value,
    onChange,
    alwaysLockedReason,
}: {
    name: string;
    value: PolicyMode;
    onChange: (mode: PolicyMode) => void;
    alwaysLockedReason: string | null;
}) {
    return (
        <div className="grid gap-2 sm:grid-cols-3" role="radiogroup" aria-label="What the agent may do">
            {MODE_ORDER.map((mode) => {
                const locked = mode === 'always' && !!alwaysLockedReason;
                const selected = value === mode;
                return (
                    <label
                        key={mode}
                        className={cn(
                            'flex items-start gap-2 rounded-lg border p-2.5 text-xs transition-colors',
                            locked ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:bg-muted/40',
                            selected && mode === 'never' && 'border-border bg-muted/60',
                            selected && mode === 'approval' && 'border-primary/50 bg-primary/5',
                            selected && mode === 'always' && 'border-warning/60 bg-warning/10',
                            !selected && 'border-border',
                        )}
                        title={locked ? (alwaysLockedReason ?? undefined) : undefined}
                    >
                        <input
                            type="radio"
                            name={name}
                            value={mode}
                            checked={selected}
                            disabled={locked}
                            onChange={() => onChange(mode)}
                            className="mt-0.5"
                        />
                        <span>
                            <span className="flex items-center gap-1 font-medium">
                                {locked && <LockIcon className="h-3 w-3" />}
                                {MODE_LABELS[mode]}
                                {mode === 'never' && <span className="font-normal text-muted-foreground">(default)</span>}
                            </span>
                            <span className="block text-muted-foreground">{locked ? alwaysLockedReason : MODE_HELP[mode]}</span>
                        </span>
                    </label>
                );
            })}
        </div>
    );
}

function LimitInput({
    field,
    draft,
    setDraft,
    error,
    required,
    money,
    idPrefix,
}: {
    field: LimitField;
    draft: LimitDraft;
    setDraft: (d: LimitDraft) => void;
    error?: string;
    required: boolean;
    money: boolean;
    idPrefix: string;
}) {
    const id = `${idPrefix}-${field.key}`;
    if (field.kind === 'bool') {
        const locked = money;
        return (
            <div className="space-y-1 sm:col-span-2">
                <label className={cn('flex items-start gap-2 text-xs', locked && 'cursor-not-allowed')}>
                    <input
                        type="checkbox"
                        className="mt-0.5"
                        checked={locked ? true : draft.require_sender_email_match}
                        disabled={locked}
                        onChange={(e) => setDraft({ ...draft, require_sender_email_match: e.target.checked })}
                    />
                    <span>
                        <span className="flex items-center gap-1 font-medium">
                            {locked && <LockIcon className="h-3 w-3" />}
                            {field.label}
                        </span>
                        <span className="block text-muted-foreground">
                            {locked
                                ? 'Always on for actions that move money or change where goods go. It cannot be switched off.'
                                : field.help}
                        </span>
                    </span>
                </label>
                <FieldError message={error} />
            </div>
        );
    }
    if (field.kind === 'states') {
        return (
            <fieldset className="space-y-1 sm:col-span-2">
                <legend className="text-xs font-medium">{field.label}</legend>
                <div className="flex flex-wrap gap-1.5">
                    {Object.entries(FULFILMENT_STATE_LABELS).map(([state, text]) => (
                        <label key={state} className="flex items-center gap-1.5 rounded-md border border-border px-2 py-1 text-xs">
                            <input
                                type="checkbox"
                                checked={draft.allowed_fulfilment_states.includes(state)}
                                onChange={(e) =>
                                    setDraft({
                                        ...draft,
                                        allowed_fulfilment_states: e.target.checked
                                            ? Array.from(new Set([...draft.allowed_fulfilment_states, state]))
                                            : draft.allowed_fulfilment_states.filter((s) => s !== state),
                                    })
                                }
                            />
                            {text}
                        </label>
                    ))}
                </div>
                <p className="text-xs text-muted-foreground">{field.help}</p>
                <FieldError message={error} />
            </fieldset>
        );
    }
    const key = field.key as keyof LimitDraft;
    const isText = field.kind === 'countries';
    return (
        <div className="space-y-1">
            <label htmlFor={id} className="text-xs font-medium">
                {field.label}
                {required && <span className="text-destructive"> (required to run on its own)</span>}
            </label>
            <Input
                id={id}
                type={isText ? 'text' : 'number'}
                inputMode={isText ? 'text' : 'decimal'}
                min={isText ? undefined : 0}
                step={field.kind === 'money' ? '0.01' : field.kind === 'percent' ? '0.1' : '1'}
                value={String(draft[key] ?? '')}
                placeholder={isText ? 'e.g. AU, NZ' : 'No limit'}
                aria-invalid={!!error}
                onChange={(e) => setDraft({ ...draft, [key]: e.target.value })}
            />
            <p className="text-xs text-muted-foreground">{field.help}</p>
            <FieldError message={error} />
        </div>
    );
}

function LimitsEditor({
    fields,
    draft,
    setDraft,
    errors,
    required,
    money,
    idPrefix,
}: {
    fields: LimitField[];
    draft: LimitDraft;
    setDraft: (d: LimitDraft) => void;
    errors: Record<string, string>;
    required: string[];
    money: boolean;
    idPrefix: string;
}) {
    return (
        <div className="grid gap-3 sm:grid-cols-2">
            {fields.map((f) => (
                <LimitInput
                    key={f.key}
                    field={f}
                    draft={draft}
                    setDraft={setDraft}
                    error={errors[`limits.${f.key}`]}
                    required={required.includes(f.key)}
                    money={money}
                    idPrefix={idPrefix}
                />
            ))}
        </div>
    );
}

function PlaybookOverrides({
    label,
    actionKey,
    rows,
    onChanged,
}: {
    label: string;
    actionKey: string;
    rows: Policy[];
    onChanged: () => void;
}) {
    const { busy, error, run } = useAction();
    if (rows.length === 0) return null;

    async function remove(row: Policy) {
        if (row.id === null) return;
        const name = row.playbook?.name || row.playbook?.slug || 'this playbook';
        if (
            !window.confirm(
                `Remove the “${name}” setting for ${label}? The shop-wide setting will apply to that playbook instead. Nothing else changes.`,
            )
        ) {
            return;
        }
        const ok = await run(`delete-${row.id}`, () => policiesApi.deletePolicy(row.id as number));
        if (ok) onChanged();
    }

    return (
        <div className="space-y-1.5">
            <p className="text-xs font-medium">Playbook settings for this action</p>
            <ul className="space-y-1.5">
                {rows.map((row) => (
                    <li
                        key={row.id ?? row.playbook?.id}
                        className="flex items-start gap-2 rounded-md border border-border px-2.5 py-2 text-xs"
                    >
                        <div className="min-w-0 flex-1 space-y-0.5">
                            <p>
                                <span className="font-medium">
                                    {row.playbook?.name || row.playbook?.slug || `Playbook ${row.playbook?.id ?? ''}`}
                                </span>
                                {' · '}
                                {modeLabel(row.mode)}
                                {' · '}
                                <span className={row.override_workspace ? 'text-warning' : 'text-muted-foreground'}>
                                    {row.override_workspace
                                        ? 'replaces the shop-wide setting for this playbook'
                                        : 'can only make the shop-wide setting stricter'}
                                </span>
                            </p>
                            <p className="text-muted-foreground">{describeLimits(row.limits, actionKey).join(' ')}</p>
                            {row.updated_at && <p className="text-muted-foreground">Last changed {formatDate(row.updated_at)}</p>}
                        </div>
                        <Button
                            type="button"
                            size="xs"
                            variant="ghost"
                            className="text-muted-foreground hover:text-destructive"
                            disabled={busy !== null}
                            onClick={() => void remove(row)}
                        >
                            <Trash2Icon className="h-3 w-3" /> {busy === `delete-${row.id}` ? 'Removing…' : 'Remove'}
                        </Button>
                    </li>
                ))}
            </ul>
            <ErrorLine error={error} />
        </div>
    );
}

/** One action: what it is, whether Shopify allows it, the shop-wide mode and limits, playbook settings. */
export function ActionPolicyCard({
    entry,
    action,
    limitKeys,
    settings,
    gates,
    connected,
    onSaved,
}: {
    entry: ActionPolicyEntry;
    action: CatalogueAction | undefined;
    limitKeys: string[];
    settings: ActionSettingsData | null;
    gates: UnattendedGates | null;
    connected: boolean;
    onSaved: () => void;
}) {
    const key = entry.action_key;
    const label = action?.label ?? entry.label;
    const money = !!(action?.money ?? entry.money);
    const savedMode = asMode(entry.workspace?.mode);
    const fields = useMemo(() => limitFieldsFor(key, limitKeys), [key, limitKeys]);
    const savedDraft = useMemo(() => toDraft(entry.workspace?.limits, money), [entry.workspace?.limits, money]);

    const [mode, setMode] = useState<PolicyMode>(savedMode);
    const [draft, setDraft] = useState<LimitDraft>(savedDraft);
    const [confirming, setConfirming] = useState(false);
    const { busy, error, setError, run } = useAction();

    const alwaysLockedReason =
        !entry.unattended_allowed || action?.unattended_allowed === false
            ? `${label} can never run on its own: a person always approves it.`
            : null;
    const required = mode === 'always' ? requiredForAlways(key, asArray(settings?.discount_templates)) : [];
    const clientErrors = draftErrors(draft, fields, required);
    const errors = { ...fieldErrors(error), ...clientErrors };
    const payloadLimits = fromDraft(draft, fields, money);
    const dirty = mode !== savedMode || JSON.stringify(payloadLimits) !== JSON.stringify(fromDraft(savedDraft, fields, money));
    const hasClientErrors = Object.keys(clientErrors).length > 0;
    const blockedAlways = mode === 'always' && !!alwaysLockedReason;

    async function save() {
        const result = await run('save', () => policiesApi.updatePolicy(key, { mode, limits: payloadLimits }));
        if (result) {
            setConfirming(false);
            onSaved();
        }
    }

    function onSave() {
        setError(null);
        if (mode === 'always') setConfirming(true);
        else void save();
    }

    const effective = entry.effective;
    const forced = asArray<string>(effective?.forced);
    const saveLabel = mode === 'never' ? 'Save: never' : mode === 'approval' ? 'Save: needs approval' : 'Review and switch on…';

    return (
        <article
            className={cn('space-y-4 rounded-xl border bg-card p-5', savedMode === 'always' ? 'border-warning/50' : 'border-border')}
            aria-labelledby={`action-${key}`}
        >
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 space-y-1">
                    <h3 id={`action-${key}`} className="text-sm font-semibold">
                        {label}
                    </h3>
                    {action?.summary && <p className="text-xs text-muted-foreground">{action.summary}</p>}
                    <ActionBadges action={action} entry={entry} />
                </div>
                <div className="text-right text-xs">
                    <p>
                        Now:{' '}
                        <span
                            className={cn(
                                'font-semibold',
                                savedMode === 'always' && 'text-warning',
                                savedMode === 'never' && 'text-muted-foreground',
                            )}
                        >
                            {modeLabel(savedMode)}
                        </span>
                    </p>
                    {entry.workspace?.updated_at && (
                        <p className="text-muted-foreground">Changed {formatDate(entry.workspace.updated_at)}</p>
                    )}
                </div>
            </header>

            <Availability action={action} connected={connected} />

            <ModePicker name={`mode-${key}`} value={mode} onChange={setMode} alwaysLockedReason={alwaysLockedReason} />
            <FieldError message={errors.mode} />

            {mode === 'always' ? (
                <div className="space-y-2 rounded-lg border border-warning/30 p-3">
                    <p className="text-xs font-medium">Limits for running on its own</p>
                    <p className="text-xs text-muted-foreground">
                        Requests outside these limits wait for a person. Empty = no limit of that kind.
                    </p>
                    <LimitsEditor
                        fields={fields}
                        draft={draft}
                        setDraft={setDraft}
                        errors={errors}
                        required={required}
                        money={money}
                        idPrefix={key}
                    />
                </div>
            ) : mode === 'approval' ? (
                <Disclosure title="Limits (only used if this later runs on its own; a person checks every request now)">
                    <LimitsEditor
                        fields={fields}
                        draft={draft}
                        setDraft={setDraft}
                        errors={errors}
                        required={required}
                        money={money}
                        idPrefix={key}
                    />
                </Disclosure>
            ) : null}

            <div className="flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    variant={mode === 'always' ? 'destructive' : 'default'}
                    disabled={!dirty || busy !== null || hasClientErrors || blockedAlways}
                    onClick={onSave}
                >
                    {busy === 'save' && !confirming ? 'Saving…' : saveLabel}
                </Button>
                {dirty && (
                    <Button
                        type="button"
                        variant="ghost"
                        disabled={busy !== null}
                        onClick={() => {
                            setMode(savedMode);
                            setDraft(savedDraft);
                            setError(null);
                        }}
                    >
                        Undo changes
                    </Button>
                )}
                {dirty && !busy && <span className="text-xs text-muted-foreground">Not saved yet</span>}
                {hasClientErrors && dirty && <span className="text-xs text-destructive">Fix the fields marked above first.</span>}
            </div>
            {!confirming && <ErrorLine error={error} />}

            {effective && (
                <div className="space-y-0.5 rounded-md bg-muted/40 px-2.5 py-2 text-xs">
                    <p>
                        <span className="font-medium">In effect:</span> {modeLabel(effective.mode)},{' '}
                        {EFFECTIVE_SOURCE_LABELS[effective.source] ?? effective.source}.
                    </p>
                    {forced.map((f) => (
                        <p key={f} className="text-muted-foreground">
                            {FORCED_LABELS[f] ?? f}
                        </p>
                    ))}
                    {asMode(effective.mode) === 'always' && (
                        <p className="text-muted-foreground">{describeLimits(effective.limits, key).join(' ')}</p>
                    )}
                    <p className="text-muted-foreground">
                        A playbook can also tighten this for its own emails (shown below when there is one).
                    </p>
                </div>
            )}

            <PlaybookOverrides label={label} actionKey={key} rows={asArray<Policy>(entry.playbooks)} onChanged={onSaved} />

            <AlwaysConfirmDialog
                open={confirming}
                actionKey={key}
                label={label}
                action={action}
                limits={payloadLimits}
                settings={settings}
                gates={gates}
                saving={busy === 'save'}
                error={error}
                onConfirm={() => void save()}
                onCancel={() => setConfirming(false)}
            />
        </article>
    );
}
