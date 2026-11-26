import React from 'react';
import { Head, Link, router, useForm, usePoll } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from './AgentParts';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import { HistoryIcon, PauseIcon, PlayIcon, SquareIcon, Undo2Icon, AlertTriangleIcon } from 'lucide-react';

interface MailboxOption {
    id: number;
    name: string;
    email: string;
}

interface Counters {
    envelopes_fetched: number;
    messages_seen: number;
    threads_total: number;
    threads_kept: number;
    threads_active: number;
    threads_dropped: number;
    threads_skipped: number;
    messages_to_import: number;
    bodies_fetched: number;
    threads_materialised: number;
    conversations_created: number;
    messages_ingested: number;
    messages_linked: number;
    messages_skipped: number;
    errors: number;
}

type Action = 'pause' | 'resume' | 'cancel' | 'undo';

interface Run {
    id: number;
    mailbox: MailboxOption | null;
    months: number;
    since: string;
    dry_run: boolean;
    include_unanswered: boolean;
    status: string;
    stage: string;
    stage_index: number;
    counters: Counters;
    kept_reasons: Record<string, number>;
    dropped_reasons: Record<string, number>;
    skipped_reasons: Record<string, number>;
    last_error: string | null;
    last_error_at: string | null;
    started_at: string | null;
    finished_at: string | null;
    heartbeat_at: string | null;
    created_at: string | null;
    can: Record<Action, boolean>;
}

interface Sample {
    id: number;
    subject: string;
    status: string;
    imported: boolean;
    customer: string | null;
    messages: number;
    created_at: string | null;
    last_reply_at: string | null;
    url: string;
}

interface DroppedSample {
    id: number;
    reason: string;
    subject: string | null;
    customer_email: string | null;
    messages: number;
    last_at: string | null;
}

interface Props {
    mailboxes: MailboxOption[];
    defaults: { months: number; include_unanswered: boolean; active_window_days: number };
    runs: Run[];
    selected: (Run & { samples: Sample[]; dropped_samples: DroppedSample[] }) | null;
}

const STAGES: { key: string; label: string }[] = [
    { key: 'metadata', label: 'Read headers' },
    { key: 'filter', label: 'Pick threads' },
    { key: 'bodies', label: 'Fetch text' },
    { key: 'materialise', label: 'Create conversations' },
    { key: 'index', label: 'Search index' },
    { key: 'done', label: 'Done' },
];

const REASONS: Record<string, string> = {
    two_way: 'Customer and us both wrote',
    unanswered: 'Customer wrote, we never replied',
    unanswered_recent: 'Unanswered, still recent (imported open)',
    out_of_window: 'Older than the window',
    draft_or_chat: 'Drafts or chats only',
    internal: 'Internal (only our addresses)',
    bulk: 'Newsletters / bulk mail',
    auto_reply: 'Auto-replies',
    notification: 'Notifications / bounces',
    no_customer_reply: 'We wrote, nobody answered',
    no_customer: 'No customer address',
    live: 'Handled by live sync',
    already_imported: 'Already imported',
};

const ACTIVE = ['queued', 'running', 'undoing'];

function statusVariant(status: string): 'default' | 'secondary' | 'success' | 'destructive' | 'outline' {
    if (status === 'completed') return 'success';
    if (status === 'failed') return 'destructive';
    if (status === 'running' || status === 'undoing' || status === 'queued') return 'default';
    if (status === 'paused') return 'secondary';
    return 'outline';
}

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}

function n(value: number): string {
    return value.toLocaleString();
}

function Stat({ label, value, hint }: { label: string; value: number; hint?: string }) {
    return (
        <div className="rounded-lg border border-border px-4 py-3">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="text-xl font-semibold tabular-nums mt-0.5">{n(value)}</p>
            {hint && <p className="text-[11px] text-muted-foreground mt-0.5">{hint}</p>}
        </div>
    );
}

function ReasonTable({ title, reasons }: { title: string; reasons: Record<string, number> }) {
    const entries = Object.entries(reasons).sort((a, b) => b[1] - a[1]);
    if (entries.length === 0) return null;

    return (
        <div>
            <p className="text-xs font-medium text-muted-foreground mb-1.5">{title}</p>
            <ul className="divide-y divide-border rounded-lg border border-border text-sm">
                {entries.map(([reason, count]) => (
                    <li key={reason} className="flex items-center justify-between px-3 py-2">
                        <span>{REASONS[reason] ?? reason}</span>
                        <span className="tabular-nums text-muted-foreground">{n(count)}</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

export default function Import({ mailboxes, defaults, runs, selected }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        mailbox_id: mailboxes[0]?.id ?? 0,
        months: defaults.months,
        dry_run: true,
        include_unanswered: defaults.include_unanswered,
    });

    const busy = runs.some((r) => ACTIVE.includes(r.status));
    const { start, stop } = usePoll(3000, { only: ['runs', 'selected'] }, { autoStart: false });

    React.useEffect(() => {
        if (busy) start();
        else stop();
    }, [busy, start, stop]);

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post(route('agent.import.store'));
    }

    function act(run: Run, action: Action) {
        if (action === 'undo' && !window.confirm('Delete every conversation this import created? Live conversations are not touched.')) {
            return;
        }
        if (action === 'cancel' && !window.confirm('Stop this import? What it already imported stays (you can undo it afterwards).')) {
            return;
        }
        router.post(route('agent.import.action', { run: run.id, action }), {}, { preserveScroll: true });
    }

    return (
        <AppLayout>
            <Head title="Import history" />

            <div className="w-full px-6 py-8 space-y-6">
                <PageHeader
                    title="Import history"
                    current="/agent/import"
                    description={
                        <>
                            Copies past customer conversations (inbox and sent) from the mailbox into fusterai as closed, imported
                            conversations with their real dates, so the agent can learn from them. Gmail is only read, never changed. Nobody
                            is notified and nothing is sent. Threads still waiting on a reply from the last {defaults.active_window_days}{' '}
                            days are imported open.
                        </>
                    }
                />

                <form onSubmit={submit} className="rounded-xl border border-border bg-card max-w-3xl">
                    <div className="flex items-start gap-3 px-6 py-5 border-b border-border">
                        <div className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                            <HistoryIcon className="h-4 w-4 text-primary" />
                        </div>
                        <div>
                            <h2 className="text-[15px] font-semibold">New import</h2>
                            <p className="text-xs text-muted-foreground mt-0.5">
                                Start with a dry run: it reads headers only and shows how many threads would be imported and why the others
                                are left out.
                            </p>
                        </div>
                    </div>

                    <div className="px-6 py-5 grid gap-5 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="mailbox_id">Mailbox</Label>
                            <select
                                id="mailbox_id"
                                value={data.mailbox_id}
                                onChange={(e) => setData('mailbox_id', Number(e.target.value))}
                                className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                            >
                                {mailboxes.map((m) => (
                                    <option key={m.id} value={m.id}>
                                        {m.name} ({m.email})
                                    </option>
                                ))}
                            </select>
                            {mailboxes.length === 0 && <p className="text-xs text-muted-foreground">No mailbox with IMAP settings.</p>}
                            {errors.mailbox_id && <p className="text-xs text-destructive">{errors.mailbox_id}</p>}
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="months">Months back</Label>
                            <Input
                                id="months"
                                type="number"
                                min={1}
                                max={120}
                                value={data.months}
                                onChange={(e) => setData('months', Number(e.target.value))}
                            />
                            {errors.months && <p className="text-xs text-destructive">{errors.months}</p>}
                        </div>

                        <div className="flex items-center justify-between rounded-lg border border-border px-4 py-3">
                            <div>
                                <p className="text-sm font-medium">Dry run</p>
                                <p className="text-xs text-muted-foreground mt-0.5">Count only, import nothing.</p>
                            </div>
                            <Switch checked={data.dry_run} onCheckedChange={(checked) => setData('dry_run', checked)} />
                        </div>

                        <div className="flex items-center justify-between rounded-lg border border-border px-4 py-3">
                            <div>
                                <p className="text-sm font-medium">Include unanswered</p>
                                <p className="text-xs text-muted-foreground mt-0.5">Also threads nobody replied to.</p>
                            </div>
                            <Switch
                                checked={data.include_unanswered}
                                onCheckedChange={(checked) => setData('include_unanswered', checked)}
                            />
                        </div>
                    </div>

                    <div className="flex justify-end px-6 pb-5">
                        <Button type="submit" disabled={processing || mailboxes.length === 0}>
                            {data.dry_run ? 'Start dry run' : 'Start import'}
                        </Button>
                    </div>
                </form>

                {selected && (
                    <div className="rounded-xl border border-border bg-card">
                        <div className="flex flex-wrap items-start justify-between gap-3 px-6 py-5 border-b border-border">
                            <div>
                                <div className="flex items-center gap-2">
                                    <h2 className="text-[15px] font-semibold">
                                        {selected.dry_run ? 'Dry run' : 'Import'} #{selected.id}
                                    </h2>
                                    <Badge variant={statusVariant(selected.status)}>{selected.status}</Badge>
                                </div>
                                <p className="text-xs text-muted-foreground mt-1">
                                    {selected.mailbox?.email} · since {selected.since} ({selected.months} months)
                                    {selected.include_unanswered ? ' · including unanswered' : ''} · started{' '}
                                    {formatDate(selected.started_at)}
                                    {selected.finished_at ? ` · finished ${formatDate(selected.finished_at)}` : ''}
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {selected.can.pause && (
                                    <Button variant="outline" size="sm" onClick={() => act(selected, 'pause')}>
                                        <PauseIcon /> Pause
                                    </Button>
                                )}
                                {selected.can.resume && (
                                    <Button variant="outline" size="sm" onClick={() => act(selected, 'resume')}>
                                        <PlayIcon /> Resume
                                    </Button>
                                )}
                                {selected.can.cancel && (
                                    <Button variant="outline" size="sm" onClick={() => act(selected, 'cancel')}>
                                        <SquareIcon /> Cancel
                                    </Button>
                                )}
                                {selected.can.undo && (
                                    <Button variant="destructive" size="sm" onClick={() => act(selected, 'undo')}>
                                        <Undo2Icon /> Undo import
                                    </Button>
                                )}
                            </div>
                        </div>

                        <div className="px-6 py-5 space-y-6">
                            <ol className="flex flex-wrap gap-2 text-xs">
                                {STAGES.filter((s) => !(selected.dry_run && ['bodies', 'materialise', 'index'].includes(s.key))).map(
                                    (s) => {
                                        const index = STAGES.findIndex((x) => x.key === s.key);
                                        const state =
                                            index < selected.stage_index || selected.stage === 'done'
                                                ? 'done'
                                                : index === selected.stage_index
                                                  ? 'current'
                                                  : 'todo';
                                        return (
                                            <li
                                                key={s.key}
                                                className={
                                                    'rounded-full border px-3 py-1 ' +
                                                    (state === 'done'
                                                        ? 'border-primary/30 bg-primary/10 text-primary'
                                                        : state === 'current'
                                                          ? 'border-primary text-foreground font-medium'
                                                          : 'border-border text-muted-foreground')
                                                }
                                            >
                                                {s.label}
                                            </li>
                                        );
                                    },
                                )}
                            </ol>

                            {selected.last_error && (
                                <div className="flex gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm">
                                    <AlertTriangleIcon className="h-4 w-4 shrink-0 text-destructive mt-0.5" />
                                    <div>
                                        <p className="font-medium">Last error ({formatDate(selected.last_error_at)})</p>
                                        <p className="text-xs text-muted-foreground break-all mt-0.5">{selected.last_error}</p>
                                    </div>
                                </div>
                            )}

                            <div className="grid gap-3 grid-cols-2 md:grid-cols-4">
                                <Stat label="Headers read" value={selected.counters.envelopes_fetched} />
                                <Stat label="Threads in window" value={selected.counters.threads_total} />
                                <Stat
                                    label={selected.dry_run ? 'Threads that would be imported' : 'Threads to import'}
                                    value={selected.counters.threads_kept}
                                    hint={`${n(selected.counters.messages_to_import)} messages, ${n(selected.counters.threads_active)} still open`}
                                />
                                <Stat
                                    label="Threads left out"
                                    value={selected.counters.threads_dropped + selected.counters.threads_skipped}
                                />
                                {!selected.dry_run && (
                                    <>
                                        <Stat label="Message texts fetched" value={selected.counters.bodies_fetched} />
                                        <Stat
                                            label="Threads done"
                                            value={selected.counters.threads_materialised}
                                            hint={`of ${n(selected.counters.threads_kept)}`}
                                        />
                                        <Stat label="Conversations created" value={selected.counters.conversations_created} />
                                        <Stat
                                            label="Messages imported"
                                            value={selected.counters.messages_ingested}
                                            hint={`${n(selected.counters.messages_linked)} matched existing, ${n(selected.counters.errors)} errors`}
                                        />
                                    </>
                                )}
                            </div>

                            <div className="grid gap-4 md:grid-cols-3">
                                <ReasonTable title="Imported" reasons={selected.kept_reasons} />
                                <ReasonTable title="Left out" reasons={selected.dropped_reasons} />
                                <ReasonTable title="Nothing to do" reasons={selected.skipped_reasons} />
                            </div>

                            {selected.samples.length > 0 && (
                                <div>
                                    <p className="text-xs font-medium text-muted-foreground mb-1.5">Imported conversations (latest)</p>
                                    <ul className="divide-y divide-border rounded-lg border border-border text-sm">
                                        {selected.samples.map((c) => (
                                            <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                                                <Link href={c.url} className="text-primary hover:underline truncate max-w-[60%]">
                                                    {c.subject}
                                                </Link>
                                                <span className="text-xs text-muted-foreground">
                                                    {c.customer} · {c.messages} messages · {formatDate(c.last_reply_at)} · {c.status}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}

                            {selected.dropped_samples.length > 0 && (
                                <div>
                                    <p className="text-xs font-medium text-muted-foreground mb-1.5">Examples of threads left out</p>
                                    <ul className="divide-y divide-border rounded-lg border border-border text-sm">
                                        {selected.dropped_samples.map((t) => (
                                            <li key={t.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                                                <span className="truncate max-w-[60%]">{t.subject ?? '(no subject)'}</span>
                                                <span className="text-xs text-muted-foreground">
                                                    {REASONS[t.reason] ?? t.reason} · {t.customer_email ?? '—'} · {formatDate(t.last_at)}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </div>
                    </div>
                )}

                {runs.length > 0 && (
                    <div className="rounded-xl border border-border bg-card">
                        <div className="px-6 py-4 border-b border-border">
                            <h2 className="text-[15px] font-semibold">Previous runs</h2>
                        </div>
                        <ul className="divide-y divide-border text-sm">
                            {runs.map((r) => (
                                <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 px-6 py-3">
                                    <Link
                                        href={route('agent.import.index', { run: r.id })}
                                        preserveScroll
                                        className={r.id === selected?.id ? 'font-medium' : 'text-primary hover:underline'}
                                    >
                                        #{r.id} {r.dry_run ? 'Dry run' : 'Import'} · {r.mailbox?.email} · {r.months} months
                                    </Link>
                                    <span className="flex items-center gap-2 text-xs text-muted-foreground">
                                        {n(r.counters.threads_kept)} threads · {n(r.counters.conversations_created)} conversations ·{' '}
                                        {formatDate(r.created_at)}
                                        <Badge variant={statusVariant(r.status)}>{r.status}</Badge>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
