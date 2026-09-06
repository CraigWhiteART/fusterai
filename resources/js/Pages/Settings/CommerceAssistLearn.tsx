import React, { useEffect, useMemo, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import CommerceAssistNav from './CommerceAssistNav';

interface Stats {
    sent_replies: number;
    unscanned: number;
    pending: number;
    accepted: number;
    examples: number;
    facts: number;
    documents: number;
}

interface Scan {
    id: number;
    status: string;
    total_count: number;
    scanned_count: number;
    proposed_count: number;
    skipped_count: number;
    error: string | null;
    finished_at: string | null;
}

interface Learning {
    id: number;
    conversation_id: number | null;
    kind: 'approved_reply' | 'knowledge' | 'live_fact';
    status: string;
    title: string | null;
    body: string | null;
    customer_message: string | null;
    final_reply: string | null;
    source_customer_message: string | null;
    source_final_reply: string | null;
    subject: string | null;
    intent: string | null;
    subtype: string | null;
    product_keywords: string[];
    time_sensitive: boolean;
    expires_at: string | null;
    rationale: string | null;
}

interface Props {
    stats: Stats;
    scan: Scan | null;
    learnings: Learning[];
    filter: string;
    intents: string[];
}

const kindLabels: Record<Learning['kind'], string> = {
    knowledge: 'Knowledge',
    live_fact: 'Current fact',
    approved_reply: 'Approved reply',
};

function kindBadge(kind: Learning['kind']): 'secondary' | 'success' | 'outline' {
    if (kind === 'knowledge') {
        return 'secondary';
    }
    if (kind === 'live_fact') {
        return 'success';
    }
    return 'outline';
}

export default function CommerceAssistLearn({ stats, scan, learnings, filter, intents }: Props) {
    const scanning = scan?.status === 'queued' || scan?.status === 'running';
    const current = learnings[0] ?? null;
    const [kind, setKind] = useState<Learning['kind']>(current?.kind ?? 'knowledge');
    const [title, setTitle] = useState(current?.title ?? '');
    const [body, setBody] = useState(current?.body ?? '');
    const [customerMessage, setCustomerMessage] = useState(current?.customer_message ?? '');
    const [finalReply, setFinalReply] = useState(current?.final_reply ?? '');
    const [intent, setIntent] = useState(current?.intent ?? '');
    const [subtype, setSubtype] = useState(current?.subtype ?? '');
    const [keywords, setKeywords] = useState((current?.product_keywords ?? []).join(', '));
    const [expiresAt, setExpiresAt] = useState(current?.expires_at ?? '');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!scanning) {
            return;
        }

        const timer = window.setInterval(() => {
            router.reload({ only: ['scan', 'learnings', 'stats'] });
        }, 2000);

        return () => window.clearInterval(timer);
    }, [scanning, scan?.id]);

    useEffect(() => {
        setKind(current?.kind ?? 'knowledge');
        setTitle(current?.title ?? '');
        setBody(current?.body ?? '');
        setCustomerMessage(current?.customer_message ?? '');
        setFinalReply(current?.final_reply ?? '');
        setIntent(current?.intent ?? '');
        setSubtype(current?.subtype ?? '');
        setKeywords((current?.product_keywords ?? []).join(', '));
        setExpiresAt(current?.expires_at ?? '');
    }, [current?.id]);

    const pendingByKind = useMemo(
        () => ({
            knowledge: learnings.filter((item) => item.kind === 'knowledge').length,
            live_fact: learnings.filter((item) => item.kind === 'live_fact').length,
            approved_reply: learnings.filter((item) => item.kind === 'approved_reply').length,
        }),
        [learnings],
    );

    function visitFilter(next: string) {
        router.get(
            '/settings/commerce-assist/learn',
            next ? { kind: next } : {},
            { preserveState: false, preserveScroll: true },
        );
    }

    function accept() {
        if (!current) {
            return;
        }

        setBusy(true);
        router.post(
            `/settings/commerce-assist/learn/${current.id}/accept`,
            {
                kind,
                title,
                body,
                customer_message: customerMessage,
                final_reply: finalReply,
                intent,
                subtype,
                product_keywords: keywords,
                expires_at: expiresAt || null,
            },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    }

    function reject() {
        if (!current) {
            return;
        }

        setBusy(true);
        router.post(`/settings/commerce-assist/learn/${current.id}/reject`, {}, { preserveScroll: true, onFinish: () => setBusy(false) });
    }

    return (
        <AppLayout>
            <Head title="Learn from sent emails" />
            <div className="w-full px-6 py-8 space-y-8">
                <div className="space-y-2">
                    <h1 className="text-2xl font-bold tracking-tight">Learn from sent emails</h1>
                    <p className="text-sm text-muted-foreground max-w-3xl">
                        Starting from scratch does not mean typing the knowledge base, current facts, and approved replies by hand.
                        Scan emails this team already sent. AI proposes what to keep. You accept, edit, or skip — nothing is published
                        until you say so.
                    </p>
                    <CommerceAssistNav current="/settings/commerce-assist/learn" />
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat label="Sent replies" value={stats.sent_replies} hint={`${stats.unscanned} not scanned yet`} />
                    <Stat label="Approved replies" value={stats.examples} href="/settings/commerce-assist/examples" />
                    <Stat label="Current facts" value={stats.facts} href="/settings/commerce-assist/facts" />
                    <Stat label="Knowledge docs" value={stats.documents} href="/ai/knowledge-base" />
                </div>

                <section className="rounded-xl border border-border bg-card p-5 space-y-4 max-w-3xl">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="space-y-1">
                            <h2 className="text-sm font-semibold">Scan sent history</h2>
                            <p className="text-xs text-muted-foreground">
                                Reads the newest {Math.min(40, Math.max(stats.unscanned, 0)) || 40} agent replies that are not already
                                examples. Knowledge and facts are generalized — order numbers and emails are stripped.
                            </p>
                        </div>
                        <Button
                            disabled={scanning || stats.unscanned === 0}
                            onClick={() => router.post('/settings/commerce-assist/learn/scan')}
                        >
                            {scanning ? 'Scanning…' : stats.unscanned === 0 ? 'Caught up' : 'Scan sent emails'}
                        </Button>
                    </div>

                    {scanning && scan && (
                        <p className="text-sm text-muted-foreground">
                            Reading {scan.scanned_count} of {scan.total_count} emails · {scan.proposed_count} proposals so far
                        </p>
                    )}

                    {scan?.status === 'failed' && (
                        <p className="text-sm text-destructive">{scan.error || 'Scan failed. Try again.'}</p>
                    )}

                    {scan?.status === 'completed' && stats.pending === 0 && stats.unscanned === 0 && (
                        <p className="text-sm text-muted-foreground">
                            History has been read. Accepted items live under approved replies, current facts, and the knowledge base.
                        </p>
                    )}
                </section>

                {learnings.length > 0 && current && (
                    <section className="space-y-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="space-y-1">
                                <h2 className="text-sm font-semibold">Review {learnings.length} proposal{learnings.length === 1 ? '' : 's'}</h2>
                                <p className="text-xs text-muted-foreground">
                                    Go through one at a time. Accepting writes it to the right place.
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <FilterChip label="All" active={filter === ''} onClick={() => visitFilter('')} count={learnings.length} />
                                <FilterChip
                                    label="Knowledge"
                                    active={filter === 'knowledge'}
                                    onClick={() => visitFilter('knowledge')}
                                    count={pendingByKind.knowledge}
                                />
                                <FilterChip
                                    label="Facts"
                                    active={filter === 'live_fact'}
                                    onClick={() => visitFilter('live_fact')}
                                    count={pendingByKind.live_fact}
                                />
                                <FilterChip
                                    label="Replies"
                                    active={filter === 'approved_reply'}
                                    onClick={() => visitFilter('approved_reply')}
                                    count={pendingByKind.approved_reply}
                                />
                            </div>
                        </div>

                        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
                            <div className="rounded-xl border border-border bg-card p-5 space-y-4">
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge variant={kindBadge(current.kind)}>{kindLabels[current.kind]}</Badge>
                                    {current.subject && <p className="text-sm font-medium">{current.subject}</p>}
                                    {current.conversation_id && (
                                        <Link
                                            href={`/conversations/${current.conversation_id}`}
                                            className="text-xs text-muted-foreground hover:text-foreground"
                                        >
                                            Open ticket
                                        </Link>
                                    )}
                                </div>

                                {current.rationale && <p className="text-sm text-muted-foreground">{current.rationale}</p>}

                                <div className="grid gap-3 md:grid-cols-2">
                                    <SourceBlock label="Customer wrote" text={current.source_customer_message} />
                                    <SourceBlock label="Agent sent" text={current.source_final_reply} />
                                </div>

                                <div className="grid gap-3 md:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <Label>Save as</Label>
                                        <select
                                            className="w-full border rounded-md px-3 py-2 text-sm bg-background"
                                            value={kind}
                                            onChange={(e) => setKind(e.target.value as Learning['kind'])}
                                        >
                                            <option value="knowledge">Knowledge base article</option>
                                            <option value="live_fact">Current fact</option>
                                            <option value="approved_reply">Approved reply</option>
                                        </select>
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Intent</Label>
                                        <select
                                            className="w-full border rounded-md px-3 py-2 text-sm bg-background"
                                            value={intent}
                                            onChange={(e) => setIntent(e.target.value)}
                                        >
                                            <option value="">Optional</option>
                                            {intents.map((slug) => (
                                                <option key={slug} value={slug}>
                                                    {slug}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                </div>

                                {kind === 'approved_reply' ? (
                                    <div className="grid gap-3 md:grid-cols-2">
                                        <div className="space-y-1.5">
                                            <Label>Generalized customer message</Label>
                                            <Textarea rows={6} value={customerMessage} onChange={(e) => setCustomerMessage(e.target.value)} />
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label>Generalized reply</Label>
                                            <Textarea rows={6} value={finalReply} onChange={(e) => setFinalReply(e.target.value)} />
                                        </div>
                                    </div>
                                ) : (
                                    <>
                                        {kind === 'knowledge' && (
                                            <div className="space-y-1.5">
                                                <Label>Title</Label>
                                                <Input value={title} onChange={(e) => setTitle(e.target.value)} />
                                            </div>
                                        )}
                                        <div className="space-y-1.5">
                                            <Label>{kind === 'live_fact' ? 'Fact' : 'Article'}</Label>
                                            <Textarea rows={6} value={body} onChange={(e) => setBody(e.target.value)} />
                                        </div>
                                    </>
                                )}

                                <div className="grid gap-3 md:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <Label>Subtype</Label>
                                        <Input value={subtype} onChange={(e) => setSubtype(e.target.value)} />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Product keywords</Label>
                                        <Input
                                            value={keywords}
                                            onChange={(e) => setKeywords(e.target.value)}
                                            placeholder="optional, comma-separated"
                                        />
                                    </div>
                                </div>

                                {kind === 'live_fact' && (
                                    <div className="space-y-1.5 max-w-xs">
                                        <Label>Expires</Label>
                                        <Input type="date" value={expiresAt} onChange={(e) => setExpiresAt(e.target.value)} />
                                        <p className="text-xs text-muted-foreground">Leave blank if it should stay until you retire it.</p>
                                    </div>
                                )}

                                <div className="flex flex-wrap gap-2 pt-1">
                                    <Button onClick={accept} disabled={busy}>
                                        Accept and next
                                    </Button>
                                    <Button variant="outline" onClick={reject} disabled={busy}>
                                        Skip
                                    </Button>
                                    {pendingByKind.approved_reply > 1 && kind === 'approved_reply' && (
                                        <Button
                                            variant="ghost"
                                            disabled={busy}
                                            onClick={() =>
                                                router.post('/settings/commerce-assist/learn/accept-replies', {}, { preserveScroll: true })
                                            }
                                        >
                                            Accept remaining replies
                                        </Button>
                                    )}
                                </div>
                            </div>

                            <aside className="space-y-2">
                                <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Up next</p>
                                {learnings.slice(0, 12).map((item, index) => (
                                    <div
                                        key={item.id}
                                        className={`rounded-lg border px-3 py-2 text-sm ${index === 0 ? 'border-primary bg-card' : 'border-border bg-card/60'}`}
                                    >
                                        <p className="text-xs text-muted-foreground">{kindLabels[item.kind]}</p>
                                        <p className="truncate">{item.title || item.subject || item.body || 'Untitled'}</p>
                                    </div>
                                ))}
                            </aside>
                        </div>
                    </section>
                )}

                {!scanning && learnings.length === 0 && stats.sent_replies === 0 && (
                    <p className="text-sm text-muted-foreground">
                        Import or send a few support emails first. Once there is sent history, scan it here.
                    </p>
                )}
            </div>
        </AppLayout>
    );
}

function Stat({ label, value, hint, href }: { label: string; value: number; hint?: string; href?: string }) {
    const inner = (
        <div className="rounded-xl border border-border bg-card p-4 space-y-1">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="text-2xl font-semibold tracking-tight">{value}</p>
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
        </div>
    );

    if (!href) {
        return inner;
    }

    return (
        <Link href={href} className="block hover:border-foreground/20">
            {inner}
        </Link>
    );
}

function FilterChip({
    label,
    active,
    onClick,
    count,
}: {
    label: string;
    active: boolean;
    onClick: () => void;
    count: number;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`rounded-full border px-3 py-1 text-xs ${active ? 'border-primary text-primary' : 'border-border text-muted-foreground hover:text-foreground'}`}
        >
            {label} {count}
        </button>
    );
}

function SourceBlock({ label, text }: { label: string; text: string | null }) {
    return (
        <div className="rounded-lg bg-muted/40 p-3 space-y-1">
            <p className="text-[11px] uppercase tracking-wide text-muted-foreground">{label}</p>
            <p className="text-xs whitespace-pre-wrap text-muted-foreground line-clamp-6">{text || '—'}</p>
        </div>
    );
}
