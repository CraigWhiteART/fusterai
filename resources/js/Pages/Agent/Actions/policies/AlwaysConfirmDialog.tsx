import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { ShieldAlertIcon } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { ErrorLine } from '@/Components/agent/LoopParts';
import type { ApiError } from '@/lib/agentLoop';
import { describeLimits } from '@/lib/agentActionPolicies';
import type { ActionSettingsData, CatalogueAction, PolicyLimits, UnattendedGates } from '@/types/agentActionPolicies';

/** The switches outside this page that must all allow it (agent settings, hard rules). */
export function GlobalGateLines({
    actionKey,
    action,
    settings,
    gates,
}: {
    actionKey: string;
    action: CatalogueAction | undefined;
    settings: ActionSettingsData | null;
    gates: UnattendedGates | null;
}) {
    const cap = gates?.actions_per_day ?? null;
    return (
        <ul className="list-disc space-y-1 pl-5">
            <li>
                The agent must be in <strong>Live</strong> mode
                {gates?.mode ? (
                    gates.mode === 'live' ? (
                        <> (it is).</>
                    ) : (
                        <>
                            . <strong>It is in “{gates.mode}” mode right now</strong>, so nothing runs on its own yet.
                        </>
                    )
                ) : (
                    <> (see Agent settings).</>
                )}
            </li>
            <li>
                The kill switch must be off
                {gates?.kill_switch === true ? (
                    <>
                        . <strong>It is on right now</strong>, so nothing runs on its own.
                    </>
                ) : gates?.kill_switch === false ? (
                    <> (it is).</>
                ) : (
                    <>.</>
                )}
            </li>
            <li>
                {cap === 0 ? (
                    <>
                        <strong>The daily limit for actions that run on their own is 0</strong>, so nothing will run on its own until you
                        raise it in{' '}
                        <Link href="/agent/settings" className="text-primary underline">
                            Agent settings
                        </Link>{' '}
                        (“Shopify actions per day”).
                    </>
                ) : cap !== null ? (
                    <>
                        At most <strong>{cap}</strong> Shopify action{cap === 1 ? '' : 's'} a day run on their own across the whole shop
                        (all actions together). After that they wait for a person. Change it in{' '}
                        <Link href="/agent/settings" className="text-primary underline">
                            Agent settings
                        </Link>
                        .
                    </>
                ) : (
                    <>
                        The daily limit for actions that run on their own (“Shopify actions per day” in{' '}
                        <Link href="/agent/settings" className="text-primary underline">
                            Agent settings
                        </Link>
                        ) must be above 0. It starts at 0, which means nothing runs on its own.
                    </>
                )}
            </li>
            {action?.money && <li>The email must come from the address on the order (always checked for money and address actions).</li>}
            {(actionKey === 'refund' || actionKey === 'cancel_order') && (
                <li>
                    Refunds above {settings ? `${Number(settings.refund_hard_ceiling).toFixed(2)} (shop currency)` : 'the refund ceiling'}{' '}
                    always wait for a person.
                </li>
            )}
            {action?.warehouse_sensitive && (
                <li>
                    Warehouse (4PX) check: only while the order has not shipped, nothing has been sent to a warehouse, and the order is
                    younger than {settings ? `${settings.unattended_order_age_minutes} minutes` : 'the warehouse window'}. Otherwise a
                    person decides.
                </li>
            )}
            <li>The order is read again from Shopify and every check is repeated just before it runs.</li>
            <li>If the agent handed the email to a person instead of drafting, it waits for that person.</li>
        </ul>
    );
}

/**
 * Two-step confirmation before an action may run unattended: first restate
 * exactly what will apply, then an explicit acknowledgement (and, for money
 * actions, typing the action's name) before Save is enabled.
 */
export function AlwaysConfirmDialog({
    open,
    actionKey,
    label,
    action,
    limits,
    settings,
    gates,
    saving,
    error,
    onConfirm,
    onCancel,
}: {
    open: boolean;
    actionKey: string;
    label: string;
    action: CatalogueAction | undefined;
    limits: PolicyLimits;
    settings: ActionSettingsData | null;
    gates: UnattendedGates | null;
    saving: boolean;
    error: ApiError | null;
    onConfirm: () => void;
    onCancel: () => void;
}) {
    const [step, setStep] = useState<1 | 2>(1);
    const [understood, setUnderstood] = useState(false);
    const [typed, setTyped] = useState('');
    const needsTyping = !!action?.money;
    const typedOk = !needsTyping || typed.trim().toLowerCase() === label.trim().toLowerCase();
    const ready = understood && typedOk && !saving;

    function close() {
        setStep(1);
        setUnderstood(false);
        setTyped('');
        onCancel();
    }

    return (
        <Dialog open={open} onOpenChange={(v) => !v && close()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2 text-base">
                        <ShieldAlertIcon className="h-4 w-4 text-warning" />
                        Let “{label}” run on its own?
                    </DialogTitle>
                    <DialogDescription>
                        Step {step} of 2. Within the limits below, the agent will do this in Shopify without anyone checking first.
                        {action && !action.reversible ? ' It cannot be undone.' : ''}
                    </DialogDescription>
                </DialogHeader>

                {step === 1 ? (
                    <div className="space-y-3 text-sm">
                        <div className="space-y-1">
                            <p className="font-medium">It only runs on its own when all of this is true:</p>
                            <ul className="list-disc space-y-1 pl-5">
                                {describeLimits(limits, actionKey).map((line) => (
                                    <li key={line}>{line}</li>
                                ))}
                            </ul>
                        </div>
                        <div className="space-y-1">
                            <p className="font-medium">And these settings elsewhere allow it:</p>
                            <GlobalGateLines actionKey={actionKey} action={action} settings={settings} gates={gates} />
                        </div>
                        <p className="text-muted-foreground">
                            Anything outside these limits still waits for a person in Approvals. Every action is recorded in the action log.
                        </p>
                    </div>
                ) : (
                    <div className="space-y-3 text-sm">
                        <label className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 p-3">
                            <input
                                type="checkbox"
                                className="mt-1"
                                checked={understood}
                                onChange={(e) => setUnderstood(e.target.checked)}
                            />
                            <span>
                                I understand this runs without anyone checking, and that{' '}
                                {action && !action.reversible
                                    ? 'it cannot be undone once it has happened.'
                                    : 'I may have to undo it by hand.'}
                            </span>
                        </label>
                        {needsTyping && (
                            <div className="space-y-1">
                                <label htmlFor="confirm-action-name" className="text-xs font-medium">
                                    This moves money or changes where goods go. Type <strong>{label}</strong> to confirm.
                                </label>
                                <Input
                                    id="confirm-action-name"
                                    value={typed}
                                    autoComplete="off"
                                    onChange={(e) => setTyped(e.target.value)}
                                    placeholder={label}
                                />
                            </div>
                        )}
                        <ErrorLine error={error} />
                    </div>
                )}

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={close}>
                        Cancel, keep it as it was
                    </Button>
                    {step === 1 ? (
                        <Button type="button" onClick={() => setStep(2)}>
                            I have read this, continue
                        </Button>
                    ) : (
                        <Button type="button" variant="destructive" disabled={!ready} onClick={onConfirm}>
                            {saving ? 'Saving…' : 'Save: runs on its own'}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
