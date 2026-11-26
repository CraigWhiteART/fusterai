import React from 'react';
import { router, useForm } from '@inertiajs/react';
import { OctagonXIcon, PauseCircleIcon, PlayCircleIcon } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import { cn } from '@/lib/utils';
import { AgentPage, FieldError, Notice, SaveButton, Section, errorFor, formatDate } from './AgentParts';
import type { AgentMode, AgentSettings, AutoSendStatus } from '@/types/agent';

interface Props {
    settings: AgentSettings;
    modes: AgentMode[];
    default_link_domains: string[];
    autonomy_enforced: boolean;
    auto_send_status: AutoSendStatus;
    language_options: string[];
    channel_options: string[];
}

const MODE_INFO: Record<AgentMode, { title: string; body: string }> = {
    off: {
        title: 'Off',
        body: 'The agent does nothing. The older reply suggestions keep working as before.',
    },
    shadow: {
        title: 'Shadow (practice)',
        body: 'The agent reads every new email and records what it would have written, but nobody sees a draft. When a person replies, their reply is compared with the agent’s (the shadow report). Use it to decide when to go live.',
    },
    draft: {
        title: 'Draft for review',
        body: 'The agent writes a draft (or hands over with a note) on each new email. A person reads it, edits if needed and sends it. Nothing is ever sent automatically.',
    },
    live: {
        title: 'Live (auto-send for approved playbooks)',
        body: 'Like Draft, except that replies for playbooks you have switched to “Auto” may be sent without a person, after a waiting period you can cancel. Every other reply is still a draft.',
    },
};

const CHANNEL_LABELS: Record<string, string> = { email: 'Email', chat: 'Live chat', whatsapp: 'WhatsApp' };

export default function AgentSettingsPage({
    settings,
    modes,
    default_link_domains,
    auto_send_status,
    language_options,
    channel_options,
}: Props) {
    const { data, setData, setDefaults, patch, processing, errors, isDirty } = useForm({
        mode: settings.mode,
        kill_switch: settings.kill_switch,
        confirm_live: false,
        auto_send_delay_seconds: settings.auto_send_delay_seconds,
        caps: settings.caps,
        link_allowlist: settings.link_allowlist,
        auto_send: settings.auto_send,
    });
    const [allowlistText, setAllowlistText] = React.useState(settings.link_allowlist.join(', '));
    const goingLive = data.mode === 'live' && settings.mode !== 'live';

    function submit(e: React.FormEvent) {
        e.preventDefault();
        patch('/agent/settings', { preserveScroll: true, onSuccess: () => setDefaults() });
    }

    function setAllowlist(text: string) {
        setAllowlistText(text);
        setData(
            'link_allowlist',
            text
                .split(/[\s,]+/)
                .map((d) => d.trim())
                .filter(Boolean),
        );
    }

    function toggleIn(list: string[], value: string, on: boolean): string[] {
        return on ? Array.from(new Set([...list, value])) : list.filter((v) => v !== value);
    }

    return (
        <AgentPage
            title="Agent settings"
            current="/agent/settings"
            description="Whether the AI agent works on incoming email, what it may send by itself, and the emergency stops."
            width="max-w-3xl"
        >
            {settings.kill_switch && (
                <Notice tone="danger">
                    <span className="font-semibold">The kill switch is on.</span> The agent is stopped for every conversation, whatever the
                    mode below says. Nothing is sent automatically.
                </Notice>
            )}

            <PauseControl settings={settings} status={auto_send_status} />

            <form onSubmit={submit} className="space-y-6">
                <Section title="Mode" description="What the agent does when a customer email arrives.">
                    <div className="space-y-2" role="radiogroup" aria-label="Agent mode">
                        {modes.map((mode) => {
                            const info = MODE_INFO[mode];
                            return (
                                <label
                                    key={mode}
                                    className={cn(
                                        'flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm transition-colors hover:bg-muted/40',
                                        data.mode === mode ? 'border-primary/50 bg-primary/5' : 'border-border',
                                    )}
                                >
                                    <input
                                        type="radio"
                                        name="mode"
                                        value={mode}
                                        checked={data.mode === mode}
                                        onChange={() => setData('mode', mode)}
                                        className="mt-1"
                                    />
                                    <span>
                                        <span className="font-medium">{info.title}</span>
                                        <span className="block text-muted-foreground">{info.body}</span>
                                    </span>
                                </label>
                            );
                        })}
                    </div>
                    {goingLive && (
                        <label className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
                            <input
                                type="checkbox"
                                checked={data.confirm_live}
                                onChange={(e) => setData('confirm_live', e.target.checked)}
                                className="mt-1"
                            />
                            <span>
                                I understand that replies for playbooks set to <strong>Auto</strong> will be emailed to customers without a
                                person reading them first (after the waiting period below). An email that has gone out cannot be recalled.
                            </span>
                        </label>
                    )}
                    <FieldError message={errors.mode} />
                    <FieldError message={errorFor(errors, 'confirm_live')} />
                </Section>

                <WhatAutoSends settings={settings} data={data} status={auto_send_status} />

                <Section
                    title="Kill switch"
                    description="An emergency stop. While it is on, the agent skips every new email in any mode: no drafts, no handover notes, no automatic sends. Saving it also cancels every reply that is waiting to be auto-sent (they come back as drafts)."
                >
                    <div
                        className={cn(
                            'flex items-center gap-3 rounded-lg border p-3',
                            data.kill_switch ? 'border-destructive/40 bg-destructive/5' : 'border-border',
                        )}
                    >
                        <Switch id="kill_switch" checked={data.kill_switch} onCheckedChange={(v) => setData('kill_switch', v)} />
                        <div className="flex-1">
                            <Label htmlFor="kill_switch" className="flex items-center gap-1.5">
                                {data.kill_switch && <OctagonXIcon className="h-4 w-4 text-destructive" />}
                                {data.kill_switch ? 'Stopped: the agent is off everywhere' : 'Running normally'}
                            </Label>
                            <p className="text-xs text-muted-foreground">
                                Takes effect as soon as you save, including for replies already queued.
                            </p>
                        </div>
                    </div>
                    <FieldError message={errors.kill_switch} />
                </Section>

                <Section
                    title="Automatic sending"
                    description="Only used in Live mode, and only for playbooks switched to Auto. Every limit is checked again right before each email goes out."
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <NumberField
                            id="delay"
                            label="Wait before sending (seconds)"
                            hint="The reply shows in the conversation with a countdown, Cancel and Edit instead. 0 = send at once."
                            value={data.auto_send_delay_seconds}
                            onChange={(v) => setData('auto_send_delay_seconds', v)}
                            error={errors.auto_send_delay_seconds}
                        />
                        <NumberField
                            id="cap_day"
                            label="Automatic sends per day"
                            hint="Across the whole workspace. 0 = none."
                            value={data.caps.auto_send_per_day}
                            onChange={(v) => setData('caps', { ...data.caps, auto_send_per_day: v })}
                            error={errorFor(errors, 'caps.auto_send_per_day')}
                        />
                        <NumberField
                            id="cap_customer"
                            label="Automatic sends per customer per day"
                            value={data.caps.auto_send_per_customer_per_day}
                            onChange={(v) => setData('caps', { ...data.caps, auto_send_per_customer_per_day: v })}
                            error={errorFor(errors, 'caps.auto_send_per_customer_per_day')}
                        />
                        <NumberField
                            id="cap_consecutive"
                            label="Auto-replies in a row without a person"
                            hint="Stops loops with other robots and autoresponders."
                            value={data.caps.auto_send_consecutive_per_conversation}
                            onChange={(v) => setData('caps', { ...data.caps, auto_send_consecutive_per_conversation: v })}
                            error={errorFor(errors, 'caps.auto_send_consecutive_per_conversation')}
                        />
                        <NumberField
                            id="cap_actions"
                            label="Shopify actions per day"
                            hint="Most Shopify actions the agent may take on its own per day (only actions set to “Runs on its own” in Action rules). 0 = nothing runs unattended; everything waits for approval."
                            value={data.caps.actions_per_day}
                            onChange={(v) => setData('caps', { ...data.caps, actions_per_day: v })}
                            error={errorFor(errors, 'caps.actions_per_day')}
                        />
                        <div className="space-y-1">
                            <Label htmlFor="status_after">Conversation status after an automatic send</Label>
                            <select
                                id="status_after"
                                className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                                value={data.auto_send.status_after_send}
                                onChange={(e) =>
                                    setData('auto_send', { ...data.auto_send, status_after_send: e.target.value as 'pending' | 'open' })
                                }
                            >
                                <option value="pending">Pending (waiting on the customer)</option>
                                <option value="open">Open</option>
                            </select>
                            <p className="text-xs text-muted-foreground">Never closed automatically.</p>
                            <FieldError message={errorFor(errors, 'auto_send.status_after_send')} />
                        </div>
                    </div>

                    <fieldset className="space-y-1.5">
                        <legend className="text-sm font-medium">Languages the agent may answer by itself</legend>
                        <div className="flex flex-wrap gap-2">
                            {Array.from(new Set([...language_options, ...data.auto_send.allowed_languages])).map((lang) => (
                                <label key={lang} className="flex items-center gap-1.5 rounded-md border border-border px-2 py-1 text-xs">
                                    <input
                                        type="checkbox"
                                        checked={data.auto_send.allowed_languages.includes(lang)}
                                        onChange={(e) =>
                                            setData('auto_send', {
                                                ...data.auto_send,
                                                allowed_languages: toggleIn(data.auto_send.allowed_languages, lang, e.target.checked),
                                            })
                                        }
                                    />
                                    {lang}
                                </label>
                            ))}
                        </div>
                        <p className="text-xs text-muted-foreground">Emails in any other language are always drafts.</p>
                        <FieldError message={errorFor(errors, 'auto_send.allowed_languages')} />
                    </fieldset>

                    <fieldset className="space-y-1.5">
                        <legend className="text-sm font-medium">Channels</legend>
                        <div className="flex flex-wrap gap-2">
                            {channel_options.map((channel) => (
                                <label
                                    key={channel}
                                    className="flex items-center gap-1.5 rounded-md border border-border px-2 py-1 text-xs"
                                >
                                    <input
                                        type="checkbox"
                                        checked={data.auto_send.channels.includes(channel)}
                                        onChange={(e) =>
                                            setData('auto_send', {
                                                ...data.auto_send,
                                                channels: toggleIn(data.auto_send.channels, channel, e.target.checked),
                                            })
                                        }
                                    />
                                    {CHANNEL_LABELS[channel] ?? channel}
                                </label>
                            ))}
                        </div>
                        <FieldError message={errorFor(errors, 'auto_send.channels')} />
                    </fieldset>

                    <div className="space-y-1">
                        <Label htmlFor="disclosure">Disclosure line (optional)</Label>
                        <Input
                            id="disclosure"
                            value={data.auto_send.disclosure_line}
                            placeholder="This reply was written by our AI assistant. Just reply if you need a person."
                            onChange={(e) => setData('auto_send', { ...data.auto_send, disclosure_line: e.target.value })}
                        />
                        <p className="text-xs text-muted-foreground">
                            Added under every automatically sent reply. Empty = none. The mailbox signature is always used, never a team
                            member’s.
                        </p>
                        <FieldError message={errorFor(errors, 'auto_send.disclosure_line')} />
                    </div>
                </Section>

                <Section
                    title="Links the agent may include"
                    description="Drafts that contain a link to any other website fail the checks and are handed to a person."
                >
                    <div className="space-y-1.5">
                        <Label htmlFor="link_allowlist">Extra allowed domains</Label>
                        <Input
                            id="link_allowlist"
                            value={allowlistText}
                            placeholder="help.example.com, example.com"
                            onChange={(e) => setAllowlist(e.target.value)}
                        />
                        <p className="text-xs text-muted-foreground">
                            Separate with commas.{' '}
                            {default_link_domains.length > 0
                                ? `Always allowed: ${default_link_domains.join(', ')}.`
                                : 'Your shop and carrier domains are always allowed once they are connected.'}
                        </p>
                        <FieldError message={errorFor(errors, 'link_allowlist')} />
                    </div>
                </Section>

                <div className="flex items-center gap-3">
                    <SaveButton processing={processing} disabled={!isDirty || (goingLive && !data.confirm_live)}>
                        Save settings
                    </SaveButton>
                    {isDirty && !processing && <span className="text-xs text-muted-foreground">Unsaved changes</span>}
                </div>
            </form>
        </AgentPage>
    );
}

/** The obvious global "Pause AI agent" control (POST /agent/autosend/pause | resume). */
function PauseControl({ settings, status }: { settings: AgentSettings; status: AutoSendStatus }) {
    const [busy, setBusy] = React.useState(false);
    const [error, setError] = React.useState<string | null>(null);
    const paused = !!settings.paused_until;

    function call(url: string) {
        setBusy(true);
        setError(null);
        window.axios
            .post(url, {})
            .then(() => router.reload({ only: ['settings', 'auto_send_status'] }))
            .catch(() => setError('That did not work. Try again, or use the kill switch below.'))
            .finally(() => setBusy(false));
    }

    return (
        <div
            className={cn(
                'flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4',
                paused ? 'border-warning/40 bg-warning/10' : 'border-border bg-card',
            )}
        >
            <div className="min-w-0 space-y-0.5 text-sm">
                <p className="font-semibold">{paused ? 'Automatic sending is paused' : 'Pause AI agent'}</p>
                <p className="text-xs text-muted-foreground">
                    {paused
                        ? `Paused until ${formatDate(settings.paused_until)}${settings.paused_reason ? ` (${settings.paused_reason})` : ''}. Replies are drafts for a person; nothing resumes by itself.`
                        : `Stops every automatic send at once and cancels the ${status.pending} waiting to go out (they become drafts). Drafting carries on.`}
                </p>
                {error && <p className="text-xs text-destructive">{error}</p>}
            </div>
            {paused ? (
                <Button type="button" variant="outline" disabled={busy} onClick={() => call('/agent/autosend/resume')}>
                    <PlayCircleIcon className="h-4 w-4" /> Resume automatic sending
                </Button>
            ) : (
                <Button type="button" variant="destructive" disabled={busy} onClick={() => call('/agent/autosend/pause')}>
                    <PauseCircleIcon className="h-4 w-4" /> Pause AI agent
                </Button>
            )}
        </div>
    );
}

/** Plain-English summary of exactly what will and will not be sent without a person. */
function WhatAutoSends({
    settings,
    data,
    status,
}: {
    settings: AgentSettings;
    data: {
        mode: AgentMode;
        kill_switch: boolean;
        auto_send_delay_seconds: number;
        caps: AgentSettings['caps'];
        auto_send: AgentSettings['auto_send'];
    };
    status: AutoSendStatus;
}) {
    const off = data.mode !== 'live' || data.kill_switch || !!settings.paused_until;
    const playbooks = status.auto_playbooks;

    return (
        <Section title="What will be sent automatically" description="With the settings on this page (after you save).">
            {off || playbooks.length === 0 ? (
                <p className="text-sm">
                    <strong>Nothing.</strong>{' '}
                    {data.kill_switch
                        ? 'The kill switch is on.'
                        : settings.paused_until
                          ? 'Automatic sending is paused.'
                          : data.mode !== 'live'
                            ? 'The agent is not in Live mode.'
                            : 'No playbook is switched to Auto yet (do that on a playbook’s page once its tests pass).'}{' '}
                    Every reply is a draft for a person.
                </p>
            ) : (
                <div className="space-y-3 text-sm">
                    <p>A reply is sent without a person only when all of these are true:</p>
                    <ul className="list-disc space-y-1 pl-5 text-muted-foreground">
                        <li>
                            The email matches one of these Auto playbooks, and the conversation falls in its rollout share:{' '}
                            {playbooks
                                .map((p) => `${p.name} (${p.rollout_percent}%${p.auto_hold ? ', on hold while re-testing' : ''})`)
                                .join(', ')}
                            .
                        </li>
                        <li>
                            The checks passed first time (no rewrite), every fact is backed by the order, carrier or knowledge, and no
                            Shopify action (refund, cancel…) is needed.
                        </li>
                        <li>
                            The customer is not angry, frustrated or flagged as risky, writes in{' '}
                            {data.auto_send.allowed_languages.join(', ') || 'no allowed language'}, on{' '}
                            {data.auto_send.channels.join(', ') || 'no channel'}, is not blocked, and their email is not itself an automatic
                            reply or newsletter.
                        </li>
                        <li>
                            Nobody has replied since the customer’s last message, fewer than{' '}
                            {data.caps.auto_send_consecutive_per_conversation} automatic replies are already in a row on that conversation,
                            and today’s limits ({data.caps.auto_send_per_day} per day, {data.caps.auto_send_per_customer_per_day} per
                            customer) are not used up ({status.sent_today} sent today).
                        </li>
                        <li>
                            It then waits {data.auto_send_delay_seconds} seconds, showing a countdown with Cancel and Edit instead, and is
                            checked again just before it goes out.
                        </li>
                    </ul>
                    <p className="text-muted-foreground">
                        Everything else is a draft or a handover, as in Draft mode. More than {status.max_per_hour} automatic sends in an
                        hour pauses all automatic sending for {status.pause_hours} hours; a playbook whose results go bad is switched back
                        to Draft. Neither is ever switched back on automatically.
                    </p>
                </div>
            )}
        </Section>
    );
}

function NumberField({
    id,
    label,
    hint,
    value,
    onChange,
    error,
}: {
    id: string;
    label: string;
    hint?: string;
    value: number;
    onChange: (value: number) => void;
    error?: string;
}) {
    return (
        <div className="space-y-1">
            <Label htmlFor={id}>{label}</Label>
            <Input id={id} type="number" min={0} value={value} onChange={(e) => onChange(Number(e.target.value))} />
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            <FieldError message={error} />
        </div>
    );
}
