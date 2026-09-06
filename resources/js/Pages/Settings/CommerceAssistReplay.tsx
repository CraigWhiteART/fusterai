import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/Components/ui/button';

interface Candidate {
    id: number;
    subject: string;
    customer: string | null;
    status: string;
    original_reply: string;
}

interface Run {
    id: number;
    conversation_id: number;
    subject: string | null;
    original_reply: string | null;
    current_ai_reply: string | null;
    passed: boolean | null;
    sources: { shopify?: string; knowledge?: string[]; examples?: string[] } | null;
    created_at: string | null;
}

interface Props {
    conversations: Candidate[];
    runs: Run[];
}

export default function CommerceAssistReplay({ conversations, runs }: Props) {
    return (
        <AppLayout>
            <Head title="AI Replay" />
            <div className="w-full px-6 py-8 space-y-8">
                <div className="space-y-2">
                    <h1 className="text-2xl font-bold tracking-tight">Replay historical tickets</h1>
                    <p className="text-sm text-muted-foreground">
                        Run the current AI configuration against an old conversation. Nothing is sent to the customer.
                    </p>
                    <div className="flex flex-wrap gap-3 text-sm">
                        <Link href="/settings/commerce-assist" className="text-muted-foreground hover:text-foreground">
                            Settings
                        </Link>
                        <Link href="/settings/commerce-assist/intents" className="text-muted-foreground hover:text-foreground">
                            Intent rules
                        </Link>
                        <Link href="/settings/commerce-assist/examples" className="text-muted-foreground hover:text-foreground">
                            Approved replies
                        </Link>
                        <Link href="/settings/commerce-assist/replay" className="text-primary font-medium">
                            Replay
                        </Link>
                    </div>
                </div>

                {runs.length > 0 && (
                    <section className="overflow-x-auto rounded-xl border border-border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/40 text-xs uppercase tracking-wide text-muted-foreground">
                                <tr>
                                    <th className="text-left px-4 py-2">Ticket</th>
                                    <th className="text-left px-4 py-2">Original reply</th>
                                    <th className="text-left px-4 py-2">Current AI reply</th>
                                    <th className="text-left px-4 py-2">Pass?</th>
                                </tr>
                            </thead>
                            <tbody>
                                {runs.map((run) => (
                                    <tr key={run.id} className="border-t border-border align-top">
                                        <td className="px-4 py-3 w-48">
                                            <p className="font-medium">{run.subject}</p>
                                            <p className="text-xs text-muted-foreground">{run.sources?.shopify}</p>
                                        </td>
                                        <td className="px-4 py-3 max-w-sm whitespace-pre-wrap text-muted-foreground">
                                            {run.original_reply || '—'}
                                        </td>
                                        <td className="px-4 py-3 max-w-sm whitespace-pre-wrap">{run.current_ai_reply || '—'}</td>
                                        <td className="px-4 py-3">
                                            <div className="flex gap-2">
                                                <Button
                                                    size="sm"
                                                    variant={run.passed === true ? 'default' : 'outline'}
                                                    className="h-7 text-xs"
                                                    onClick={() =>
                                                        router.patch(`/settings/commerce-assist/replay/${run.id}/mark`, { passed: true })
                                                    }
                                                >
                                                    Pass
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant={run.passed === false ? 'destructive' : 'outline'}
                                                    className="h-7 text-xs"
                                                    onClick={() =>
                                                        router.patch(`/settings/commerce-assist/replay/${run.id}/mark`, { passed: false })
                                                    }
                                                >
                                                    Fail
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </section>
                )}

                <section className="space-y-3">
                    <h2 className="text-sm font-semibold">Historical tickets</h2>
                    {conversations.map((conversation) => (
                        <div key={conversation.id} className="rounded-xl border border-border bg-card p-4 flex items-start justify-between gap-4">
                            <div className="min-w-0">
                                <p className="text-sm font-medium">{conversation.subject}</p>
                                <p className="text-xs text-muted-foreground">
                                    {conversation.customer} · {conversation.status}
                                </p>
                                <p className="text-xs text-muted-foreground mt-2 line-clamp-3 whitespace-pre-wrap">
                                    {conversation.original_reply}
                                </p>
                            </div>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => router.post(`/settings/commerce-assist/replay/${conversation.id}`)}
                            >
                                Run current AI
                            </Button>
                        </div>
                    ))}
                    {conversations.length === 0 && (
                        <p className="text-sm text-muted-foreground">No historical tickets with both a customer message and an agent reply.</p>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
