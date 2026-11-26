import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { LockIcon, ShieldCheckIcon, ShieldXIcon } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { ErrorLine, Spinner } from '@/Components/agent/LoopParts';
import { AGENT_SCREEN_PATHS, agentScreenUrl, asArray, toApiError, type ApiError } from '@/lib/agentLoop';
import { policiesApi, unattendedGates } from '@/lib/agentActionPolicies';
import type {
    ActionPolicyEntry,
    ActionSettingsResponse,
    Catalogue,
    CatalogueAction,
    PoliciesResponse,
    ScopeAdvisory,
    UnattendedGates,
} from '@/types/agentActionPolicies';
import { AgentPage, EmptyState, Notice, Section } from '../AgentParts';
import { ActionPolicyCard } from './policies/ActionPolicyCard';
import { GlobalGateLines } from './policies/AlwaysConfirmDialog';
import { ApprovalSettingsPanel, DiscountTemplatesPanel } from './policies/SettingsPanels';

interface Data {
    catalogue: Catalogue;
    policies: PoliciesResponse;
    settings: ActionSettingsResponse;
}

function ConnectionPanel({ catalogue }: { catalogue: Catalogue }) {
    const advisories = asArray<ScopeAdvisory>(catalogue.advisories);
    return (
        <Section title="Shopify connection">
            {catalogue.shopify_connected ? (
                <p className="flex items-center gap-1.5 text-sm">
                    <ShieldCheckIcon className="h-4 w-4 text-success" /> Shopify is connected.
                    {!catalogue.scopes_known && (
                        <span className="text-muted-foreground">
                            {' '}
                            fusterai does not know yet which permissions the app has: click “Test connection” on{' '}
                            <Link href="/agent/connections" className="text-primary underline">
                                Connections
                            </Link>
                            .
                        </span>
                    )}
                </p>
            ) : (
                <p className="flex items-center gap-1.5 text-sm">
                    <ShieldXIcon className="h-4 w-4 text-muted-foreground" /> Shopify is not connected, so no action can run. Connect it on{' '}
                    <Link href="/agent/connections" className="text-primary underline">
                        Connections
                    </Link>
                    .
                </p>
            )}
            {advisories.length > 0 && (
                <ul className="space-y-1.5">
                    {advisories.map((a) => (
                        <li key={a.scope} className="rounded-md border border-info/30 bg-info/5 px-3 py-2 text-xs">
                            <span className="font-medium">Note ({a.scope}):</span> {a.text}
                        </li>
                    ))}
                </ul>
            )}
        </Section>
    );
}

function GatesPanel({ gates, settings }: { gates: UnattendedGates | null; settings: ActionSettingsResponse['settings'] }) {
    const cap = gates?.actions_per_day ?? null;
    return (
        <Section
            title="What has to be true before anything runs on its own"
            description="These live in Agent settings and apply to every action together. Changing an action to “Runs on its own” below does nothing until they allow it."
            actions={
                <Button asChild size="sm" variant="outline">
                    <Link href="/agent/settings">Agent settings</Link>
                </Button>
            }
        >
            <div className="grid gap-2 text-xs sm:grid-cols-3">
                <div className="rounded-md border border-border px-3 py-2">
                    <p className="text-muted-foreground">Agent mode</p>
                    <p className="font-semibold">{gates === null ? <Spinner /> : (gates.mode ?? 'Could not read')}</p>
                    {gates?.paused_until && (
                        <p className="text-warning">Automatic work is paused until {new Date(gates.paused_until).toLocaleString()}.</p>
                    )}
                </div>
                <div className="rounded-md border border-border px-3 py-2">
                    <p className="text-muted-foreground">Kill switch</p>
                    <p className="font-semibold">
                        {gates === null ? (
                            <Spinner />
                        ) : gates.kill_switch === null ? (
                            'Could not read'
                        ) : gates.kill_switch ? (
                            'On (all stopped)'
                        ) : (
                            'Off'
                        )}
                    </p>
                </div>
                <div className="rounded-md border border-border px-3 py-2">
                    <p className="text-muted-foreground">Actions per day that may run on their own</p>
                    <p className="font-semibold">{gates === null ? <Spinner /> : cap === null ? 'Could not read' : cap}</p>
                    <p className="text-muted-foreground">0 = nothing runs unattended.</p>
                </div>
            </div>
            <div className="text-xs">
                <GlobalGateLines actionKey="" action={undefined} settings={settings} gates={gates} />
            </div>
        </Section>
    );
}

function LockedRulesPanel({ settings, currency }: { settings: ActionSettingsResponse['settings']; currency: string | null }) {
    return (
        <Section title="Rules nobody can switch off" description="Built in. Not even an admin can change these here.">
            <ul className="space-y-1.5 text-xs">
                <li className="flex items-start gap-2">
                    <LockIcon className="mt-0.5 h-3 w-3 shrink-0 text-muted-foreground" />
                    Sending a free replacement order always needs a person’s approval.
                </li>
                <li className="flex items-start gap-2">
                    <LockIcon className="mt-0.5 h-3 w-3 shrink-0 text-muted-foreground" />
                    For refunds, cancellations, address changes, discounts and replacements, the sender’s email must match the order’s email
                    before anything runs on its own. If it doesn’t, a person is asked and warned.
                </li>
                <li className="flex items-start gap-2">
                    <LockIcon className="mt-0.5 h-3 w-3 shrink-0 text-muted-foreground" />
                    Refunds (and cancellations that refund) above {Number(settings.refund_hard_ceiling).toFixed(2)}{' '}
                    {currency ?? '(shop currency)'} always need a person. You can lower or raise that amount in the settings below, but
                    never above the built-in maximum.
                </li>
                <li className="flex items-start gap-2">
                    <LockIcon className="mt-0.5 h-3 w-3 shrink-0 text-muted-foreground" />
                    The AI can never change these permissions itself: only an admin on this page can.
                </li>
            </ul>
        </Section>
    );
}

/** Admin screen: which Shopify actions the agent may take, and how (docs/agent-actions.md). */
export default function ActionPoliciesPage() {
    const [data, setData] = useState<Data | null>(null);
    const [gates, setGates] = useState<UnattendedGates | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [loading, setLoading] = useState(true);
    const alive = useRef(true);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const [catalogue, policies, settings] = await Promise.all([
                policiesApi.catalogue(),
                policiesApi.policies(),
                policiesApi.settings(),
            ]);
            if (!alive.current) return;
            setData({ catalogue, policies, settings });
            setGates(unattendedGates(settings));
            setError(null);
        } catch (e) {
            if (alive.current) setError(toApiError(e));
        } finally {
            if (alive.current) setLoading(false);
        }
    }, []);

    useEffect(() => {
        alive.current = true;
        void load();
        return () => {
            alive.current = false;
        };
    }, [load]);

    const links = (
        <>
            <Button asChild size="sm" variant="outline">
                <Link href={agentScreenUrl('actions')}>Approvals</Link>
            </Button>
            <Button asChild size="sm" variant="outline">
                <Link href={agentScreenUrl('actionAudit')}>Action log</Link>
            </Button>
        </>
    );

    let body: ReactNode;
    if (error?.status === 403) {
        body = (
            <EmptyState icon={<LockIcon className="h-4 w-4" />} title="Only admins can change what the agent may do in Shopify">
                Ask the shop owner (an admin) if something here needs changing.
            </EmptyState>
        );
    } else if (!data) {
        body = loading ? (
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                <Spinner /> Loading…
            </p>
        ) : (
            <div className="space-y-2">
                <ErrorLine error={error} />
                <Button size="sm" variant="outline" onClick={() => void load()}>
                    Try again
                </Button>
            </div>
        );
    } else {
        const { catalogue, policies, settings } = data;
        const byKey = new Map<string, CatalogueAction>(asArray<CatalogueAction>(catalogue.actions).map((a) => [a.key, a]));
        const limitKeys = asArray<string>(policies.limit_keys);
        const entries = asArray<ActionPolicyEntry>(policies.policies);
        body = (
            <>
                {error && <Notice tone="warning">Could not refresh just now: {error.message} What you see may be out of date.</Notice>}
                <ConnectionPanel catalogue={catalogue} />
                <GatesPanel gates={gates} settings={settings.settings} />
                <LockedRulesPanel settings={settings.settings} currency={settings.shop_currency ?? null} />

                <section className="space-y-3" aria-labelledby="actions-heading">
                    <div className="flex items-center justify-between gap-2">
                        <h2 id="actions-heading" className="text-sm font-semibold">
                            Actions
                        </h2>
                        {loading && <Spinner className="text-muted-foreground" />}
                    </div>
                    {entries.length === 0 ? (
                        <EmptyState title="No actions are defined">Nothing to set up.</EmptyState>
                    ) : (
                        entries.map((entry) => (
                            <ActionPolicyCard
                                key={`${entry.action_key}-${JSON.stringify([entry.workspace, entry.playbooks])}`}
                                entry={entry}
                                action={byKey.get(entry.action_key)}
                                limitKeys={limitKeys}
                                settings={settings.settings}
                                gates={gates}
                                connected={!!catalogue.shopify_connected}
                                onSaved={() => void load()}
                            />
                        ))
                    )}
                </section>

                <ApprovalSettingsPanel
                    key={`settings-${JSON.stringify({ ...settings.settings, discount_templates: null, updated_at: null, updated_by: null })}`}
                    settings={settings.settings}
                    roles={asArray<string>(settings.roles)}
                    hardLimits={settings.hard_limits}
                    onSaved={() => void load()}
                />
                <DiscountTemplatesPanel
                    key={`templates-${JSON.stringify(settings.settings.discount_templates)}`}
                    settings={settings.settings}
                    hardLimits={settings.hard_limits}
                    onSaved={() => void load()}
                />
            </>
        );
    }

    return (
        <AgentPage
            title="Shopify actions: what the agent may do"
            headTitle="Shopify action permissions"
            current={AGENT_SCREEN_PATHS.actionPolicies}
            width="max-w-4xl"
            actions={links}
            description="Refunds, cancellations, address changes and more. Nothing is switched on when you install: each action starts on “Never”. Choose “Needs approval” to let the agent ask, and a person approves each one. Only choose “Runs on its own” after it has worked well with approval."
        >
            {body}
        </AgentPage>
    );
}
