import React from 'react';
import { Head, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/Components/ui/button';
import CommerceAssistNav from './CommerceAssistNav';

interface Fact {
    id: number;
    title: string | null;
    body: string;
    intent_slugs: string[];
    product_keywords: string[];
    retired_at: string | null;
    refreshed_count: number;
    created_at: string | null;
}

interface Props {
    facts: Fact[];
}

export default function CommerceAssistFacts({ facts }: Props) {
    const active = facts.filter((fact) => !fact.retired_at);
    const retired = facts.filter((fact) => fact.retired_at);

    return (
        <AppLayout>
            <Head title="Current facts" />
            <div className="w-full px-6 py-8 space-y-8">
                <div className="space-y-2">
                    <h1 className="text-2xl font-bold tracking-tight">Current facts</h1>
                    <p className="text-sm text-muted-foreground">
                        Live stock, dates and timelines. These override older knowledge base articles and rewrite related unsent drafts.
                    </p>
                    <CommerceAssistNav current="/settings/commerce-assist/facts" />
                </div>

                {active.length === 0 && (
                    <p className="text-sm text-muted-foreground">No current facts. Add one from a conversation while reviewing a draft.</p>
                )}

                <div className="space-y-3">
                    {active.map((fact) => (
                        <div key={fact.id} className="rounded-xl border border-border bg-card p-4 space-y-2">
                            <p className="text-sm whitespace-pre-wrap">{fact.body}</p>
                            <p className="text-xs text-muted-foreground">
                                {(fact.product_keywords ?? []).join(' · ') || 'All matching intents'}
                                {fact.intent_slugs?.length ? ` · ${fact.intent_slugs.join(', ')}` : ''}
                                {fact.refreshed_count ? ` · ${fact.refreshed_count} drafts queued` : ''}
                            </p>
                            <Button
                                size="sm"
                                variant="outline"
                                className="h-7 text-xs"
                                onClick={() => router.post(`/settings/commerce-assist/facts/${fact.id}/retire`)}
                            >
                                Retire
                            </Button>
                        </div>
                    ))}
                </div>

                {retired.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="text-sm font-semibold text-muted-foreground">Retired</h2>
                        {retired.map((fact) => (
                            <p key={fact.id} className="text-xs text-muted-foreground line-through">
                                {fact.body}
                            </p>
                        ))}
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
