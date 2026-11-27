import { useMemo, useState } from 'react';
import { GraduationCapIcon, XIcon } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Textarea } from '@/Components/ui/textarea';
import { ErrorLine, Spinner } from '@/Components/agent/LoopParts';
import { loopApi, toApiError, type ApiError } from '@/lib/agentLoop';
import { FeedbackFlow, useFeedbackTracker } from './DraftFeedback';
import type { AgentRun, DetailThread } from './types';

/** Edits smaller than this are routine polishing: no prompt. */
const MIN_PERCENT = 25;
const DISMISS_PREFIX = 'agent.teach.dismissed.';

function plainText(thread: DetailThread | undefined): string {
    if (!thread) return '';
    if (thread.body_plain) return thread.body_plain.trim();
    try {
        const doc = new DOMParser().parseFromString(thread.body ?? '', 'text/html');
        return (doc.body.textContent ?? '').trim();
    } catch {
        return '';
    }
}

function dismissed(runId: number): boolean {
    try {
        return window.sessionStorage.getItem(DISMISS_PREFIX + runId) === '1';
    } catch {
        return false;
    }
}

/**
 * Passive prompt after the agent's draft was sent heavily edited, replaced
 * with the person's own reply, or answered from Gmail (feedback events on the
 * run: sent_edited / rejected with a thread / gmail_reply). Offers to turn the
 * difference into feedback, pre-filled with what was actually sent; the rest
 * is the normal feedback flow (scope chip, diff, approve) minus the preview.
 */
export default function TeachFromEdit({
    run,
    threads,
    conversationId,
    isAdmin,
}: {
    run: AgentRun | null;
    threads: DetailThread[];
    conversationId: number;
    isAdmin: boolean;
}) {
    const [hidden, setHidden] = useState(false);
    const [open, setOpen] = useState(false);
    const [text, setText] = useState('');
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const tracker = useFeedbackTracker();

    const event = useMemo(() => {
        if (!run || run.decision !== 'draft' || !run.draft) return null;
        const list = Array.isArray(run.feedback) ? run.feedback : [];
        return (
            [...list]
                .reverse()
                .find(
                    (f) =>
                        (f.kind === 'sent_edited' && (f.percent_changed ?? 0) >= MIN_PERCENT) ||
                        f.kind === 'gmail_reply' ||
                        (f.kind === 'rejected' && f.thread_id !== null),
                ) ?? null
        );
    }, [run]);

    const sent = useMemo(() => plainText(threads.find((t) => t.id === event?.thread_id)), [threads, event]);

    if (!run || !event || hidden || dismissed(run.id) || !sent) return null;

    const what =
        event.kind === 'gmail_reply'
            ? 'This was answered from Gmail instead of with the AI draft.'
            : event.kind === 'rejected'
              ? 'The AI draft was replaced with a different reply.'
              : `The AI draft was changed a lot before sending (${event.percent_changed ?? '?'}% different).`;

    function dismiss() {
        if (!run) return;
        try {
            window.sessionStorage.setItem(DISMISS_PREFIX + run.id, '1');
        } catch {
            // fine: hidden for this view only
        }
        setHidden(true);
    }

    function start() {
        setText(
            `I changed the AI draft before sending. This is what I actually sent:\n"""\n${sent.slice(0, 2500)}\n"""\nWhat the agent should do differently: `,
        );
        setOpen(true);
    }

    async function submit() {
        if (!run || !text.trim()) return;
        setSending(true);
        setError(null);
        try {
            tracker.track(await loopApi.submitFeedback(conversationId, { run_id: run.id, text: text.trim().slice(0, 4000) }));
        } catch (e) {
            setError(toApiError(e));
        } finally {
            setSending(false);
        }
    }

    return (
        <div>
            <div className="rounded-lg border border-primary/20 bg-primary/5 p-2.5">
                <div className="flex items-start gap-2">
                    <GraduationCapIcon className="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                    <p className="min-w-0 flex-1 text-xs">
                        <span className="text-muted-foreground">{what} </span>
                        {!open && <span className="font-medium">Teach the agent from this edit?</span>}
                    </p>
                    {!open && (
                        <Button size="xs" variant="outline" className="h-6 text-[11px]" onClick={start}>
                            Teach
                        </Button>
                    )}
                    <button type="button" aria-label="Not now" onClick={dismiss} className="text-muted-foreground hover:text-foreground">
                        <XIcon className="h-3.5 w-3.5" />
                    </button>
                </div>

                {open && !tracker.feedback && (
                    <form
                        className="mt-2 space-y-1.5"
                        onSubmit={(e) => {
                            e.preventDefault();
                            void submit();
                        }}
                    >
                        <div className="grid gap-1.5 sm:grid-cols-2">
                            <div className="min-w-0 rounded border border-border bg-background p-2">
                                <p className="mb-0.5 text-[10px] font-semibold uppercase text-muted-foreground">AI draft</p>
                                <p className="max-h-32 overflow-y-auto whitespace-pre-wrap text-xs">{run.draft}</p>
                            </div>
                            <div className="min-w-0 rounded border border-primary/20 bg-background p-2">
                                <p className="mb-0.5 text-[10px] font-semibold uppercase text-primary">What was sent</p>
                                <p className="max-h-32 overflow-y-auto whitespace-pre-wrap text-xs">{sent}</p>
                            </div>
                        </div>
                        <Textarea
                            value={text}
                            onChange={(e) => setText(e.target.value)}
                            rows={4}
                            maxLength={4000}
                            autoFocus
                            aria-label="What the agent should learn"
                            className="bg-background text-xs"
                        />
                        <div className="flex items-center gap-1.5">
                            <Button type="submit" size="sm" className="h-7 text-xs" disabled={!text.trim() || sending}>
                                {sending ? <Spinner className="h-3 w-3" /> : null} Send to the agent
                            </Button>
                            <Button type="button" size="sm" variant="ghost" className="h-7 text-xs" onClick={() => setOpen(false)}>
                                Cancel
                            </Button>
                            <span className="text-[10px] text-muted-foreground">Nothing changes until it is approved.</span>
                        </div>
                        <ErrorLine error={error} />
                    </form>
                )}

                {tracker.feedback && (
                    <div className="mt-2 max-h-[45vh] overflow-y-auto rounded-md border border-border bg-background p-2.5">
                        <FeedbackFlow
                            tracker={tracker}
                            isAdmin={isAdmin}
                            playbookId={run.playbook?.id ?? null}
                            draftText={run.draft ?? ''}
                            preview={null}
                            onRetry={() => void submit()}
                            retrying={sending}
                        />
                    </div>
                )}
            </div>
        </div>
    );
}
