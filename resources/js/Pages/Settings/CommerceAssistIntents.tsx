import React, { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import { Textarea } from '@/Components/ui/textarea';

interface Intent {
    id: number;
    slug: string;
    parent_slug: string | null;
    name: string;
    rules: string;
    always_human: boolean;
    auto_send_allowed: boolean;
}

interface Props {
    intents: Intent[];
}

function IntentEditor({ intent }: { intent: Intent }) {
    const { data, setData, put, processing } = useForm({
        rules: intent.rules,
        always_human: intent.always_human,
        auto_send_allowed: intent.auto_send_allowed,
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        put(`/settings/commerce-assist/intents/${intent.id}`);
    }

    return (
        <form onSubmit={submit} className="rounded-xl border border-border bg-card p-5 space-y-3">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="text-sm font-semibold">{intent.name}</p>
                    <p className="text-xs text-muted-foreground font-mono">{intent.slug}</p>
                </div>
                {intent.parent_slug && <span className="text-[11px] text-muted-foreground">subtype of {intent.parent_slug}</span>}
            </div>
            <div className="space-y-1.5">
                <Label>Rules</Label>
                <Textarea rows={6} value={data.rules} onChange={(e) => setData('rules', e.target.value)} />
            </div>
            <div className="flex flex-wrap gap-6">
                <label className="flex items-center gap-2 text-sm">
                    <Switch checked={data.always_human} onCheckedChange={(v) => setData('always_human', v)} />
                    Always human
                </label>
                <label className="flex items-center gap-2 text-sm">
                    <Switch checked={data.auto_send_allowed} onCheckedChange={(v) => setData('auto_send_allowed', v)} />
                    Auto-send allowed later
                </label>
            </div>
            <Button type="submit" size="sm" disabled={processing}>
                Save
            </Button>
        </form>
    );
}

export default function CommerceAssistIntents({ intents }: Props) {
    const [filter, setFilter] = useState('');
    const visible = intents.filter(
        (intent) =>
            intent.name.toLowerCase().includes(filter.toLowerCase()) || intent.slug.toLowerCase().includes(filter.toLowerCase()),
    );

    return (
        <AppLayout>
            <Head title="Intent rules" />
            <div className="w-full px-6 py-8 space-y-6">
                <div className="space-y-2">
                    <h1 className="text-2xl font-bold tracking-tight">Intent rules</h1>
                    <p className="text-sm text-muted-foreground">Small rule sets per intent — not one giant prompt.</p>
                    <div className="flex flex-wrap gap-3 text-sm">
                        <Link href="/settings/commerce-assist" className="text-muted-foreground hover:text-foreground">
                            Settings
                        </Link>
                        <Link href="/settings/commerce-assist/intents" className="text-primary font-medium">
                            Intent rules
                        </Link>
                        <Link href="/settings/commerce-assist/examples" className="text-muted-foreground hover:text-foreground">
                            Approved replies
                        </Link>
                        <Link href="/settings/commerce-assist/replay" className="text-muted-foreground hover:text-foreground">
                            Replay
                        </Link>
                    </div>
                </div>
                <input
                    className="max-w-sm w-full border rounded-md px-3 py-2 text-sm bg-background"
                    placeholder="Filter intents…"
                    value={filter}
                    onChange={(e) => setFilter(e.target.value)}
                />
                <div className="grid gap-4 lg:grid-cols-2">
                    {visible.map((intent) => (
                        <IntentEditor key={intent.id} intent={intent} />
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
