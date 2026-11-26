import React from 'react';
import { router, useForm } from '@inertiajs/react';
import { PlusIcon, Trash2Icon } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import { Textarea } from '@/Components/ui/textarea';
import {
    AgentPage,
    FieldError,
    ItemListEditor,
    SaveButton,
    Section,
    VersionHistory,
    errorFor,
    listChange,
    textChange,
    type VersionChange,
} from '../AgentParts';
import PlaybookTests from './PlaybookTests';
import { AutoSendPanel, ConfirmAutoDialog, rolloutPreviewText } from './PlaybookAutoSend';
import type { ListItem, PlaybookAutoSend, PlaybookMode, PlaybookRule, PlaybookSummary, PlaybookVersion } from '@/types/agent';

interface Props {
    playbook: PlaybookSummary | null;
    live: PlaybookVersion | null;
    versions: PlaybookVersion[];
    modes: PlaybookMode[];
    auto_mode_available: boolean;
    actions: Record<string, string>;
    evidence_kinds: string[];
    /** Auto-send status (preconditions, breakers, history, rollout preview); existing playbooks only. */
    auto_send?: PlaybookAutoSend;
}

const MODE_TEXT: Record<PlaybookMode, string> = {
    draft: 'Draft replies for review',
    off: 'Off: hand these emails to a person',
    auto: 'Send automatically (after checks)',
};

const EVIDENCE_TEXT: Record<string, string> = {
    order: 'A verified Shopify order',
    tracking: 'Carrier tracking',
    photos: 'Photos from the customer',
};

function toggle(list: string[], value: string): string[] {
    return list.includes(value) ? list.filter((v) => v !== value) : [...list, value];
}

function ruleText(rule: PlaybookRule): string {
    return rule.if && rule.if !== 'always' ? `If ${rule.if}: ${rule.then}` : rule.then;
}

function ModeSelect({
    id,
    value,
    modes,
    autoAvailable,
    onChange,
}: {
    id: string;
    value: PlaybookMode;
    modes: PlaybookMode[];
    autoAvailable: boolean;
    onChange: (mode: PlaybookMode) => void;
}) {
    return (
        <select
            id={id}
            className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
            value={value}
            onChange={(e) => onChange(e.target.value as PlaybookMode)}
        >
            {modes.map((mode) => (
                <option key={mode} value={mode} disabled={mode === 'auto' && !autoAvailable}>
                    {MODE_TEXT[mode] ?? mode}
                    {mode === 'auto' && !autoAvailable ? ' (see the checklist below)' : ''}
                </option>
            ))}
        </select>
    );
}

export default function PlaybookEdit({ playbook, live, versions, modes, auto_mode_available, actions, evidence_kinds, auto_send }: Props) {
    const content = useForm({
        name: playbook?.name ?? '',
        slug: playbook?.slug ?? '',
        mode: (playbook?.mode ?? 'draft') as PlaybookMode,
        when_text: live?.when_text ?? '',
        rules: (live?.rules ?? []) as PlaybookRule[],
        steps: (live?.steps ?? []) as ListItem[],
        allowed_actions: live?.allowed_actions ?? [],
        required_evidence: live?.required_evidence ?? [],
        escalation: live?.escalation ?? '',
        change_note: '',
    });

    const settings = useForm({
        name: playbook?.name ?? '',
        mode: (playbook?.mode ?? 'draft') as PlaybookMode,
        rollout_percent: playbook?.rollout_percent ?? 0,
        always_human: playbook?.always_human ?? false,
        priority: playbook?.priority ?? 0,
    });
    const [restoring, setRestoring] = React.useState<number | null>(null);
    // Auto-send: `auto` is offered once the preconditions pass (the server checks them again).
    const [confirmingAuto, setConfirmingAuto] = React.useState(false);
    const autoSelectable = auto_mode_available && !!playbook && (playbook.mode === 'auto' || !!auto_send?.preconditions.ok);

    // Jump to a rule linked from a conversation ("why this draft" → rule).
    React.useEffect(() => {
        const hash = window.location.hash.slice(1);
        if (!hash.startsWith('rule-')) return;
        const el = document.getElementById(hash);
        el?.scrollIntoView({ block: 'center' });
        el?.classList.add('ring-2', 'ring-primary/50');
    }, []);

    function saveContent(e: React.FormEvent) {
        e.preventDefault();
        if (playbook) {
            content.post(`/agent/playbooks/${playbook.id}/versions`, {
                preserveScroll: true,
                onSuccess: () => {
                    content.setDefaults({ ...content.data, change_note: '' });
                    content.setData('change_note', '');
                },
            });
        } else {
            content.post('/agent/playbooks');
        }
    }

    function saveSettings(e: React.FormEvent) {
        e.preventDefault();
        if (!playbook) return;
        // Switching to auto needs an explicit confirmation (sent as confirm_auto).
        if (settings.data.mode === 'auto' && playbook.mode !== 'auto') {
            setConfirmingAuto(true);
            return;
        }
        settings.transform((d) => d);
        settings.patch(`/agent/playbooks/${playbook.id}`, { preserveScroll: true, onSuccess: () => settings.setDefaults() });
    }

    function confirmAuto() {
        if (!playbook) return;
        settings.transform((d) => ({ ...d, confirm_auto: true }));
        settings.patch(`/agent/playbooks/${playbook.id}`, {
            preserveScroll: true,
            onSuccess: () => settings.setDefaults(),
            onFinish: () => setConfirmingAuto(false),
        });
    }

    function restore(version: PlaybookVersion) {
        if (!playbook) return;
        setRestoring(version.id);
        router.post(
            `/agent/playbooks/${playbook.id}/versions/${version.id}/restore`,
            {},
            { preserveScroll: true, onFinish: () => setRestoring(null) },
        );
    }

    const describe = (previous: PlaybookVersion | null, current: PlaybookVersion): VersionChange[] => {
        if (!previous) return [];
        const label = (keys: string[]) => keys.map((k) => actions[k] ?? EVIDENCE_TEXT[k] ?? k);
        return [
            ...textChange('Applies when', previous.when_text, current.when_text),
            ...listChange('Rules', previous.rules.map(ruleText), current.rules.map(ruleText)),
            ...listChange(
                'Steps',
                previous.steps.map((s) => s.text),
                current.steps.map((s) => s.text),
            ),
            ...textChange('Hand over when', previous.escalation, current.escalation),
            ...listChange('Allowed actions', label(previous.allowed_actions), label(current.allowed_actions)),
            ...listChange('Required evidence', label(previous.required_evidence), label(current.required_evidence)),
        ];
    };

    const setRule = (index: number, patch: Partial<PlaybookRule>) =>
        content.setData(
            'rules',
            content.data.rules.map((rule, i) => (i === index ? { ...rule, ...patch } : rule)),
        );
    const e = content.errors as Record<string, string | undefined>;

    return (
        <AgentPage
            title={playbook ? playbook.name : 'New playbook'}
            headTitle={playbook ? `Playbook: ${playbook.name}` : 'New playbook'}
            current="/agent/playbooks"
            back={{ href: '/agent/playbooks', label: 'All playbooks' }}
            description={
                playbook
                    ? `${playbook.slug} · live version ${playbook.live_version ?? '—'}. Saving the instructions creates a new version that goes live immediately.`
                    : 'Describe one kind of customer email and how the agent should answer it.'
            }
        >
            {playbook && (
                <form onSubmit={saveSettings}>
                    <Section title="Settings" description="These apply straight away and are not versioned.">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label htmlFor="s_name">Name</Label>
                                <Input
                                    id="s_name"
                                    value={settings.data.name}
                                    onChange={(ev) => settings.setData('name', ev.target.value)}
                                />
                                <FieldError message={settings.errors.name} />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="s_mode">What the agent does</Label>
                                <ModeSelect
                                    id="s_mode"
                                    value={settings.data.mode}
                                    modes={modes}
                                    autoAvailable={autoSelectable}
                                    onChange={(m) => settings.setData('mode', m)}
                                />
                                <FieldError message={settings.errors.mode} />
                                <FieldError message={(settings.errors as Record<string, string | undefined>).confirm_auto} />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="s_priority">Priority</Label>
                                <Input
                                    id="s_priority"
                                    type="number"
                                    value={settings.data.priority}
                                    onChange={(ev) => settings.setData('priority', Number(ev.target.value))}
                                />
                                <p className="text-xs text-muted-foreground">Higher wins when two playbooks could apply.</p>
                                <FieldError message={settings.errors.priority} />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="s_rollout">Automatic sending rollout %</Label>
                                <Input
                                    id="s_rollout"
                                    type="number"
                                    min={0}
                                    max={100}
                                    disabled={!auto_mode_available}
                                    value={settings.data.rollout_percent}
                                    onChange={(ev) => settings.setData('rollout_percent', Number(ev.target.value))}
                                />
                                <p className="text-xs text-muted-foreground">
                                    Share of matching conversations answered automatically (when Auto is on). The same conversations stay in
                                    as you raise it. {rolloutPreviewText(auto_send?.rollout_preview, settings.data.rollout_percent)}
                                </p>
                                <FieldError message={settings.errors.rollout_percent} />
                            </div>
                        </div>
                        <div className="flex items-start gap-3 rounded-lg border border-border p-3">
                            <Switch
                                id="s_human"
                                checked={settings.data.always_human}
                                onCheckedChange={(v) => settings.setData('always_human', v)}
                            />
                            <div>
                                <Label htmlFor="s_human">Always hand over to a person</Label>
                                <p className="text-xs text-muted-foreground">
                                    The agent recognises these emails and leaves a note, but never drafts a reply.
                                </p>
                            </div>
                        </div>
                        {auto_send && <AutoSendPanel autoSend={auto_send} mode={playbook.mode} />}
                        <div className="flex items-center gap-3">
                            <Button type="submit" variant="outline" disabled={settings.processing || !settings.isDirty}>
                                {settings.processing ? 'Saving…' : 'Save settings'}
                            </Button>
                            {settings.isDirty && <span className="text-xs text-muted-foreground">Unsaved changes</span>}
                        </div>
                    </Section>
                    <ConfirmAutoDialog
                        open={confirmingAuto}
                        name={settings.data.name || playbook.name}
                        percent={settings.data.rollout_percent}
                        preview={auto_send?.rollout_preview}
                        processing={settings.processing}
                        onConfirm={confirmAuto}
                        onCancel={() => setConfirmingAuto(false)}
                    />
                </form>
            )}

            <form onSubmit={saveContent} className="space-y-6">
                {!playbook && (
                    <Section title="Basics">
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="space-y-1.5">
                                <Label htmlFor="name">Name</Label>
                                <Input id="name" value={content.data.name} onChange={(ev) => content.setData('name', ev.target.value)} />
                                <FieldError message={e.name} />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="slug">Short id (optional)</Label>
                                <Input
                                    id="slug"
                                    value={content.data.slug}
                                    placeholder="order_status"
                                    onChange={(ev) => content.setData('slug', ev.target.value)}
                                />
                                <FieldError message={e.slug} />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="mode">What the agent does</Label>
                                <ModeSelect
                                    id="mode"
                                    value={content.data.mode}
                                    modes={modes}
                                    autoAvailable={false}
                                    onChange={(m) => content.setData('mode', m)}
                                />
                                <FieldError message={e.mode} />
                            </div>
                        </div>
                    </Section>
                )}

                <Section title="Applies when" description="How the agent recognises this kind of email. Plain English is fine.">
                    <Textarea
                        id="when_text"
                        aria-label="Applies when"
                        rows={2}
                        value={content.data.when_text}
                        placeholder="The customer asks where their order is or when it will arrive."
                        onChange={(ev) => content.setData('when_text', ev.target.value)}
                    />
                    <FieldError message={e.when_text} />
                </Section>

                <Section title="Rules" description="What the reply must or must not do. Leave “If” empty for a rule that always applies.">
                    {content.data.rules.length === 0 && <p className="text-xs text-muted-foreground italic">No rules yet.</p>}
                    <div className="space-y-2">
                        {content.data.rules.map((rule, index) => (
                            <div
                                key={rule.id ?? `new-${index}`}
                                id={rule.id ? `rule-${rule.id}` : undefined}
                                className="grid gap-2 rounded-lg border border-border p-2 transition-shadow sm:grid-cols-[1fr_2fr_auto]"
                            >
                                <Input
                                    placeholder="If… (optional)"
                                    aria-label={`Rule ${index + 1} condition`}
                                    value={rule.if === 'always' ? '' : (rule.if ?? '')}
                                    onChange={(ev) => setRule(index, { if: ev.target.value || 'always' })}
                                />
                                <Input
                                    placeholder="Then…"
                                    aria-label={`Rule ${index + 1}`}
                                    value={rule.then}
                                    onChange={(ev) => setRule(index, { then: ev.target.value })}
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="h-9 w-9 p-0 text-muted-foreground hover:text-destructive"
                                    aria-label="Remove rule"
                                    onClick={() =>
                                        content.setData(
                                            'rules',
                                            content.data.rules.filter((_, i) => i !== index),
                                        )
                                    }
                                >
                                    <Trash2Icon className="h-3.5 w-3.5" />
                                </Button>
                                {(e[`rules.${index}.then`] || e[`rules.${index}.if`]) && (
                                    <p className="text-xs text-destructive sm:col-span-3">
                                        {e[`rules.${index}.then`] ?? e[`rules.${index}.if`]}
                                    </p>
                                )}
                            </div>
                        ))}
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => content.setData('rules', [...content.data.rules, { if: 'always', then: '' }])}
                    >
                        <PlusIcon className="mr-1 h-3.5 w-3.5" /> Add rule
                    </Button>
                    {e.rules && <FieldError message={e.rules} />}
                </Section>

                <Section title="Steps and hand-over">
                    <ItemListEditor
                        label="Steps"
                        help="The order to work through, e.g. “Check the order status”, “Quote the latest scan”."
                        items={content.data.steps}
                        onChange={(items) => content.setData('steps', items)}
                        error={errorFor(e, 'steps')}
                        addLabel="Add step"
                    />
                    <div className="space-y-1.5">
                        <Label htmlFor="escalation">Hand over to a person when</Label>
                        <Textarea
                            id="escalation"
                            rows={2}
                            value={content.data.escalation}
                            placeholder="The parcel has not moved for 14 days, or the customer asks for a refund."
                            onChange={(ev) => content.setData('escalation', ev.target.value)}
                        />
                        <FieldError message={e.escalation} />
                    </div>
                    <div className="space-y-2">
                        <p className="text-sm font-medium">Required evidence</p>
                        <p className="text-xs text-muted-foreground">
                            If any of these is missing, the agent hands over instead of guessing.
                        </p>
                        <div className="flex flex-wrap gap-3">
                            {evidence_kinds.map((kind) => (
                                <label key={kind} className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={content.data.required_evidence.includes(kind)}
                                        onChange={() => content.setData('required_evidence', toggle(content.data.required_evidence, kind))}
                                    />
                                    {EVIDENCE_TEXT[kind] ?? kind}
                                </label>
                            ))}
                        </div>
                        <FieldError message={errorFor(e, 'required_evidence')} />
                    </div>
                    <div className="space-y-2">
                        <p className="text-sm font-medium">Actions the agent may suggest</p>
                        <p className="text-xs text-muted-foreground">Suggestions only: a person decides, and nothing is carried out yet.</p>
                        <div className="flex flex-wrap gap-3">
                            {Object.entries(actions).map(([key, label]) => (
                                <label key={key} className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={content.data.allowed_actions.includes(key)}
                                        onChange={() => content.setData('allowed_actions', toggle(content.data.allowed_actions, key))}
                                    />
                                    {label}
                                </label>
                            ))}
                        </div>
                        <FieldError message={errorFor(e, 'allowed_actions')} />
                    </div>
                </Section>

                <div className="flex flex-wrap items-end gap-3">
                    <div className="min-w-64 flex-1 space-y-1.5">
                        <Label htmlFor="change_note">What changed (optional, shown in the history)</Label>
                        <Input
                            id="change_note"
                            value={content.data.change_note}
                            onChange={(ev) => content.setData('change_note', ev.target.value)}
                        />
                        <FieldError message={e.change_note} />
                    </div>
                    <SaveButton processing={content.processing} disabled={!!playbook && !content.isDirty}>
                        {playbook ? 'Save as new version' : 'Create playbook'}
                    </SaveButton>
                </div>
            </form>

            {playbook && <PlaybookTests playbookId={playbook.id} />}

            {playbook && (
                <section className="space-y-3">
                    <h2 className="text-lg font-semibold">Version history</h2>
                    <VersionHistory
                        versions={versions}
                        describe={describe}
                        onRestore={restore}
                        restoring={restoring}
                        noun="playbook version"
                    />
                </section>
            )}
        </AgentPage>
    );
}
