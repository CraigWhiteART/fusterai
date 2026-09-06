import React from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Input } from '@/Components/ui/input';
import CommerceAssistNav from './CommerceAssistNav';

interface Example {
    id: number;
    customer_message: string;
    final_reply: string;
    intent: string | null;
    subtype: string | null;
    sentiment: string | null;
    country: string | null;
    source: string;
    indexed_at: string | null;
}

interface Props {
    examples: Example[];
    intents: string[];
}

export default function CommerceAssistExamples({ examples, intents }: Props) {
    const { data, setData, post, processing, reset } = useForm({
        customer_message: '',
        final_reply: '',
        intent: '',
        subtype: '',
        sentiment: '',
        country: '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post('/settings/commerce-assist/examples', { onSuccess: () => reset() });
    }

    return (
        <AppLayout>
            <Head title="Approved replies" />
            <div className="w-full px-6 py-8 space-y-8">
                <div className="space-y-2">
                    <h1 className="text-2xl font-bold tracking-tight">Approved replies</h1>
                    <p className="text-sm text-muted-foreground">
                        How the agent responds — separate from the knowledge base, which answers what is true.
                    </p>
                    <CommerceAssistNav current="/settings/commerce-assist/examples" />
                </div>

                <form onSubmit={submit} className="rounded-xl border border-border bg-card p-5 space-y-3 max-w-3xl">
                    <div className="grid md:grid-cols-2 gap-3">
                        <div className="space-y-1.5">
                            <Label>Customer message</Label>
                            <Textarea
                                rows={5}
                                value={data.customer_message}
                                onChange={(e) => setData('customer_message', e.target.value)}
                                required
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Final reply</Label>
                            <Textarea rows={5} value={data.final_reply} onChange={(e) => setData('final_reply', e.target.value)} required />
                        </div>
                    </div>
                    <div className="grid md:grid-cols-4 gap-3">
                        <div className="space-y-1.5">
                            <Label>Intent</Label>
                            <select
                                className="w-full border rounded-md px-3 py-2 text-sm bg-background"
                                value={data.intent}
                                onChange={(e) => setData('intent', e.target.value)}
                            >
                                <option value="">Optional</option>
                                {intents.map((slug) => (
                                    <option key={slug} value={slug}>
                                        {slug}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Subtype</Label>
                            <Input value={data.subtype} onChange={(e) => setData('subtype', e.target.value)} />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Sentiment</Label>
                            <Input value={data.sentiment} onChange={(e) => setData('sentiment', e.target.value)} />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Country</Label>
                            <Input value={data.country} onChange={(e) => setData('country', e.target.value)} />
                        </div>
                    </div>
                    <Button type="submit" disabled={processing}>
                        Save example
                    </Button>
                </form>

                <div className="space-y-3">
                    {examples.length === 0 && <p className="text-sm text-muted-foreground">No approved replies yet.</p>}
                    {examples.map((example) => (
                        <div key={example.id} className="rounded-xl border border-border bg-card p-4 space-y-2">
                            <div className="flex items-center justify-between gap-3">
                                <p className="text-xs text-muted-foreground">
                                    #{example.id} · {example.subtype || example.intent || 'untagged'} · {example.source}
                                    {example.indexed_at ? ' · indexed' : ''}
                                </p>
                                <button
                                    type="button"
                                    className="text-xs text-destructive hover:underline"
                                    onClick={() => router.delete(`/settings/commerce-assist/examples/${example.id}`)}
                                >
                                    Delete
                                </button>
                            </div>
                            <p className="text-sm whitespace-pre-wrap">{example.customer_message}</p>
                            <p className="text-sm text-muted-foreground whitespace-pre-wrap border-t border-border pt-2">
                                {example.final_reply}
                            </p>
                        </div>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
