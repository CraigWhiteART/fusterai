import React from 'react';
import { router, useForm } from '@inertiajs/react';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import {
    AgentPage,
    FieldError,
    ItemListEditor,
    Notice,
    SaveButton,
    Section,
    VersionHistory,
    errorFor,
    listChange,
    textChange,
    type VersionChange,
} from './AgentParts';
import type { ListItem, VoiceVersion } from '@/types/agent';

interface Props {
    live: VoiceVersion | null;
    versions: VoiceVersion[];
}

const texts = (items: ListItem[] | null | undefined) => (items ?? []).map((i) => i.text);

function describeVoice(previous: VoiceVersion | null, current: VoiceVersion): VersionChange[] {
    if (!previous) return [];
    return [
        ...textChange('Personality', previous.personality, current.personality),
        ...listChange('Do', texts(previous.do_phrases), texts(current.do_phrases)),
        ...listChange('Never say', texts(previous.dont_phrases), texts(current.dont_phrases)),
        ...listChange('Greetings', texts(previous.greetings), texts(current.greetings)),
        ...listChange('Sign-offs', texts(previous.sign_offs), texts(current.sign_offs)),
        ...textChange('Maximum words', previous.max_words?.toString(), current.max_words?.toString()),
        ...textChange('Length guidance', previous.length_guidance, current.length_guidance),
    ];
}

export default function Voice({ live, versions }: Props) {
    const { data, setData, setDefaults, post, processing, errors, isDirty } = useForm({
        personality: live?.personality ?? '',
        do_phrases: (live?.do_phrases ?? []) as ListItem[],
        dont_phrases: (live?.dont_phrases ?? []) as ListItem[],
        greetings: (live?.greetings ?? []) as ListItem[],
        sign_offs: (live?.sign_offs ?? []) as ListItem[],
        max_words: live?.max_words ?? null,
        length_guidance: live?.length_guidance ?? '',
        change_note: '',
    });
    const [restoring, setRestoring] = React.useState<number | null>(null);

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post('/agent/voice', {
            preserveScroll: true,
            onSuccess: () => {
                setDefaults({ ...data, change_note: '' });
                setData('change_note', '');
            },
        });
    }

    function restore(version: VoiceVersion) {
        setRestoring(version.id);
        router.post(`/agent/voice/versions/${version.id}/restore`, {}, { preserveScroll: true, onFinish: () => setRestoring(null) });
    }

    return (
        <AgentPage
            title="Voice"
            current="/agent/voice"
            description="How the agent sounds in every reply. Saving creates a new version that goes live immediately; older versions can be restored."
        >
            {live?.is_starter && (
                <Notice tone="warning">
                    This is the starter voice. Edit it to match how your team actually writes: the personality, phrases you like, and
                    phrases you never want to see.
                </Notice>
            )}
            {!live && <Notice tone="warning">No voice is live yet. Write a personality below and save to create version 1.</Notice>}

            <form onSubmit={submit} className="space-y-6">
                <Section title="Personality" description="A few sentences on tone: who is writing, how formal, how warm.">
                    <Textarea
                        id="personality"
                        aria-label="Personality"
                        rows={6}
                        value={data.personality}
                        onChange={(e) => setData('personality', e.target.value)}
                    />
                    <FieldError message={errors.personality} />
                </Section>

                <Section title="Phrases">
                    <ItemListEditor
                        label="Do"
                        help="Habits worth keeping, e.g. “Use the customer's first name”."
                        items={data.do_phrases}
                        onChange={(items) => setData('do_phrases', items)}
                        error={errorFor(errors, 'do_phrases')}
                    />
                    <ItemListEditor
                        label="Never say"
                        help="Exact phrases. A draft containing one fails the checks and goes to a person."
                        items={data.dont_phrases}
                        onChange={(items) => setData('dont_phrases', items)}
                        error={errorFor(errors, 'dont_phrases')}
                    />
                    <ItemListEditor
                        label="Greetings"
                        items={data.greetings}
                        onChange={(items) => setData('greetings', items)}
                        error={errorFor(errors, 'greetings')}
                        placeholder="Hi {first_name},"
                    />
                    <ItemListEditor
                        label="Sign-offs"
                        items={data.sign_offs}
                        onChange={(items) => setData('sign_offs', items)}
                        error={errorFor(errors, 'sign_offs')}
                    />
                </Section>

                <Section title="Length">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="max_words">Maximum words</Label>
                            <Input
                                id="max_words"
                                type="number"
                                min={20}
                                max={2000}
                                value={data.max_words ?? ''}
                                placeholder="No limit"
                                onChange={(e) => setData('max_words', e.target.value === '' ? null : Number(e.target.value))}
                            />
                            <FieldError message={errors.max_words} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="length_guidance">Length guidance</Label>
                            <Input
                                id="length_guidance"
                                value={data.length_guidance}
                                placeholder="Short: two or three sentences"
                                onChange={(e) => setData('length_guidance', e.target.value)}
                            />
                            <FieldError message={errors.length_guidance} />
                        </div>
                    </div>
                </Section>

                <div className="flex flex-wrap items-end gap-3">
                    <div className="min-w-64 flex-1 space-y-1.5">
                        <Label htmlFor="change_note">What changed (optional, shown in the history)</Label>
                        <Input id="change_note" value={data.change_note} onChange={(e) => setData('change_note', e.target.value)} />
                        <FieldError message={errors.change_note} />
                    </div>
                    <SaveButton processing={processing} disabled={!isDirty}>
                        Save as new version
                    </SaveButton>
                </div>
            </form>

            <section className="space-y-3">
                <h2 className="text-lg font-semibold">Version history</h2>
                <VersionHistory
                    versions={versions}
                    describe={describeVoice}
                    onRestore={restore}
                    restoring={restoring}
                    noun="voice version"
                />
            </section>
        </AgentPage>
    );
}
