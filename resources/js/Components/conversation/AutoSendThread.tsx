import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { BotIcon, ClockIcon, FlagIcon } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Textarea } from '@/Components/ui/textarea';
import type { ThreadAutoSendMeta } from '@/types/agent';
import type { DetailThread } from './types';

/**
 * AI auto-send pieces of a thread (docs/agent-autosend.md):
 *  - AutoSendPending: countdown + Cancel / Edit instead while an auto-reply waits to go out
 *  - AiSentBadge: "Sent by AI agent" on auto-sent replies
 *  - AutoSendWrong: "This was wrong" on a sent auto-reply (feeds the circuit breaker)
 *
 * "Edit instead" cancels the send (the text comes back as the normal AI
 * draft) and fires a `fusterai:load-reply` window event with the text, so
 * the conversation view can load it straight into the composer.
 */

export function autoSendMeta(thread: DetailThread): ThreadAutoSendMeta | null {
    if (thread.origin !== 'ai') return null;
    const meta = thread.meta?.auto_send;
    return meta && typeof meta === 'object' ? (meta as ThreadAutoSendMeta) : null;
}

export function isPendingAutoSend(thread: DetailThread): boolean {
    const meta = autoSendMeta(thread);
    return thread.type === 'message' && !!thread.send_at && (meta?.status === 'scheduled' || meta?.status === 'sending');
}

function useSecondsLeft(until: string | null | undefined): number {
    const target = until ? new Date(until).getTime() : 0;
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        if (!target) return;
        const id = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(id);
    }, [target]);
    return Math.max(0, Math.ceil((target - now) / 1000));
}

function formatLeft(seconds: number): string {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return m > 0 ? `${m}m ${String(s).padStart(2, '0')}s` : `${s}s`;
}

export function AutoSendPending({ thread, conversationId }: { thread: DetailThread; conversationId: number }) {
    const left = useSecondsLeft(thread.send_at);
    const [busy, setBusy] = useState<null | 'cancel' | 'edit'>(null);
    const [error, setError] = useState<string | null>(null);

    function cancel(edit: boolean) {
        setBusy(edit ? 'edit' : 'cancel');
        setError(null);
        window.axios
            .post<{ ok: boolean; draft: string; edit: boolean }>(`/agent/auto-sends/${thread.id}/cancel`, { edit })
            .then(({ data }) => {
                if (edit) {
                    window.dispatchEvent(new CustomEvent('fusterai:load-reply', { detail: { conversationId, text: data.draft } }));
                }
                router.reload({ async: true });
            })
            .catch((e) => {
                setError(e?.response?.status === 409 ? 'Too late: it has already been sent or cancelled.' : 'Could not cancel. Try again.');
                router.reload({ async: true });
            })
            .finally(() => setBusy(null));
    }

    return (
        <div
            role="status"
            className="mx-4 mb-3 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-primary/30 bg-primary/5 px-3 py-2"
        >
            <div className="flex items-center gap-1.5 text-xs font-medium text-primary">
                <ClockIcon className="h-3.5 w-3.5 shrink-0" />
                {left > 0 ? `The AI agent will send this in ${formatLeft(left)}` : 'The AI agent is sending this now…'}
            </div>
            <div className="flex items-center gap-2">
                <Button type="button" size="sm" variant="outline" disabled={busy !== null} onClick={() => cancel(true)}>
                    {busy === 'edit' ? 'Opening…' : 'Edit instead'}
                </Button>
                <Button type="button" size="sm" variant="destructive" disabled={busy !== null} onClick={() => cancel(false)}>
                    {busy === 'cancel' ? 'Cancelling…' : 'Cancel'}
                </Button>
            </div>
            {error && <p className="w-full text-xs text-destructive">{error}</p>}
        </div>
    );
}

export function AiSentBadge({ thread }: { thread: DetailThread }) {
    const meta = autoSendMeta(thread);
    if (thread.origin !== 'ai') return null;
    const pending = isPendingAutoSend(thread);
    return (
        <span className="inline-flex items-center gap-1 rounded-full bg-primary/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-primary">
            <BotIcon className="h-3 w-3" />
            {pending ? 'AI agent · scheduled' : meta?.status === 'cancelled' ? 'AI agent · not sent' : 'Sent by AI agent'}
        </span>
    );
}

export function AutoSendWrong({ thread }: { thread: DetailThread }) {
    const meta = autoSendMeta(thread);
    const [open, setOpen] = useState(false);
    const [reason, setReason] = useState('');
    const [state, setState] = useState<'idle' | 'saving' | 'done' | 'error'>('idle');
    if (thread.type !== 'message' || meta?.status !== 'sent') return null;

    function submit() {
        setState('saving');
        window.axios
            .post(`/agent/auto-sends/${thread.id}/wrong`, { reason })
            .then(() => setState('done'))
            .catch(() => setState('error'));
    }

    if (state === 'done') {
        return (
            <p className="px-5 pb-3 text-xs text-muted-foreground">
                Thanks. Marked as wrong; the agent’s safety checks take it into account.
            </p>
        );
    }

    return (
        <div className="px-5 pb-3">
            {!open ? (
                <button
                    type="button"
                    onClick={() => setOpen(true)}
                    className="inline-flex items-center gap-1 text-xs text-muted-foreground transition-colors hover:text-destructive"
                >
                    <FlagIcon className="h-3 w-3" /> This was wrong
                </button>
            ) : (
                <div className="space-y-2">
                    <Textarea
                        rows={2}
                        value={reason}
                        aria-label="What was wrong with this reply"
                        placeholder="What was wrong with this reply?"
                        onChange={(e) => setReason(e.target.value)}
                    />
                    <div className="flex items-center gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="destructive"
                            disabled={reason.trim().length < 3 || state === 'saving'}
                            onClick={submit}
                        >
                            {state === 'saving' ? 'Sending…' : 'Mark as wrong'}
                        </Button>
                        <Button type="button" size="sm" variant="ghost" onClick={() => setOpen(false)}>
                            Never mind
                        </Button>
                        {state === 'error' && <span className="text-xs text-destructive">Could not save. Try again.</span>}
                    </div>
                </div>
            )}
        </div>
    );
}
