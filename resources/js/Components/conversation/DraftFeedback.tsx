import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { CheckCircle2Icon, ExternalLinkIcon, HelpCircleIcon, MessageSquareTextIcon, RotateCcwIcon, SendIcon, XIcon } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Textarea } from '@/Components/ui/textarea';
import {
    ConflictList,
    Disclosure,
    DiffEntries,
    ErrorLine,
    GateSummary,
    ProposalStatusBadge,
    Spinner,
    SuggestionNotes,
} from '@/Components/agent/LoopParts';
import {
    SCOPE_HELP,
    SCOPE_LABELS,
    SCOPE_ORDER,
    isOpenProposal,
    isSimulationActive,
    loopApi,
    proposalUrl,
    toApiError,
    usePolling,
    useWorkspaceEvents,
    type ApiError,
} from '@/lib/agentLoop';
import { cn } from '@/lib/utils';
import type { Feedback, FeedbackScope, Proposal } from '@/types/agentLoop';

/**
 * The draft feedback box (the core of the feedback loop, docs/agent-proposals.md).
 *
 * Under an agent draft the person types "don't say that, be more like this"
 * (optionally after selecting text in the draft). The manager model decides
 * where the correction belongs; this box then shows, without leaving the
 * conversation: the scope chip (one click to change it), the instruction
 * change as a before/after diff, the re-written draft (shown in the panel via
 * `preview.show`, with an old/new comparison here), conflicts, saved-test
 * results, and Approve / Reject for admins.
 *
 * State: `useDraftPreview` lives in ConversationView (one per open
 * conversation) and is passed down through AiDraftPanel's `preview` prop, so
 * the composer's Accept and the panel agree on which text is shown.
 */

export interface DraftPreview {
    /** The temporary draft shown instead of the real one, or null. */
    text: string | null;
    /** Optional short label for the banner, e.g. "Proposed: don't mention dispatch dates". */
    label: string | null;
    show: (text: string, label?: string | null) => void;
    clear: () => void;
}

export interface DraftFeedbackProps {
    /** agent_runs.id of the run that wrote the draft (AiDraftPanel only renders this for agent drafts). */
    runId: number;
    /** The real draft text (not the preview). */
    draftText: string;
    /** Text the user currently has selected inside the shown draft ('' when none). */
    selection: string;
    /** Temporarily replace the shown draft with a proposal. */
    preview: DraftPreview;
    /** Conversation the draft belongs to (feedback is posted against it). */
    conversationId?: number;
    /** Admins (manage-settings) can approve / reject; others see "sent to an admin". */
    isAdmin?: boolean;
    /** Playbook the draft used: the target when the scope is switched to "This playbook". */
    playbookId?: number | null;
    /** Insert this text into the reply (like the panel's Accept). */
    onAccept?: (text: string) => void;
}

const STORAGE_PREFIX = 'agent.feedback.run.';

function rememberFeedback(runId: number, feedbackId: number | null): void {
    try {
        if (feedbackId === null) window.sessionStorage.removeItem(STORAGE_PREFIX + runId);
        else window.sessionStorage.setItem(STORAGE_PREFIX + runId, String(feedbackId));
    } catch {
        // storage unavailable: the box just starts empty after a reload
    }
}

function recalledFeedback(runId: number): number | null {
    try {
        const v = Number(window.sessionStorage.getItem(STORAGE_PREFIX + runId));
        return Number.isFinite(v) && v > 0 ? v : null;
    } catch {
        return null;
    }
}

/** Still moving on the server: routing, preview or tests. */
function inFlight(f: Feedback | null): boolean {
    if (!f) return false;
    if (f.status === 'pending') return true;
    const ps = f.preview?.status;
    if (ps === 'pending' || ps === 'running') return true;
    return !!f.proposal?.gate && isSimulationActive(f.proposal.gate.status);
}

/**
 * Follows one feedback event: realtime events on the workspace channel, with
 * polling as the fallback (and to cover missed events).
 */
export function useFeedbackTracker(initial: Feedback | null = null) {
    const [feedback, setFeedback] = useState<Feedback | null>(initial);
    const [error, setError] = useState<ApiError | null>(null);
    const startedAt = useRef(Date.now());
    const idRef = useRef<number | null>(initial?.id ?? null);
    idRef.current = feedback?.id ?? null;
    const proposalRef = useRef<number | null>(null);
    proposalRef.current = feedback?.proposal?.id ?? null;
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const refresh = useCallback(async () => {
        const id = idRef.current;
        if (!id) return;
        try {
            const next = await loopApi.feedback(id);
            if (idRef.current === id) setFeedback(next);
        } catch (e) {
            setError(toApiError(e));
        }
    }, []);

    // Several events can arrive together (gate progress): coalesce.
    const soon = useCallback(() => {
        if (timer.current) clearTimeout(timer.current);
        timer.current = setTimeout(() => void refresh(), 400);
    }, [refresh]);
    useEffect(
        () => () => {
            if (timer.current) clearTimeout(timer.current);
        },
        [],
    );

    const realtime = useWorkspaceEvents({
        feedbackRouted: (e) => e.feedback_id === idRef.current && soon(),
        previewReady: (e) => (e.feedback_id === idRef.current || (!!e.proposal_id && e.proposal_id === proposalRef.current)) && soon(),
        proposalUpdated: (e) => (e.feedback_id === idRef.current || e.proposal_id === proposalRef.current) && soon(),
        simulationProgress: (e) => !!e.proposal_id && e.proposal_id === proposalRef.current && soon(),
        simulationFinished: (e) => !!e.proposal_id && e.proposal_id === proposalRef.current && soon(),
    });

    const moving = inFlight(feedback);
    // Poll faster without realtime; give up after 15 minutes of nothing settling.
    usePolling(() => void refresh(), realtime ? 8000 : 3000, moving && Date.now() - startedAt.current < 15 * 60_000);

    const track = useCallback((next: Feedback | null) => {
        startedAt.current = Date.now();
        idRef.current = next?.id ?? null;
        setError(null);
        setFeedback(next);
    }, []);

    const setProposal = useCallback(
        (p: Proposal) => {
            startedAt.current = Date.now();
            setFeedback((f) => (f ? { ...f, proposal: p } : f));
            // The preview / suggestions on the feedback follow the proposal: re-read them.
            soon();
        },
        [soon],
    );

    return { feedback, track, refresh, setProposal, error, setError, moving };
}

// ── The box ──────────────────────────────────────────────────────────────────

export default function DraftFeedback({
    runId,
    draftText,
    selection,
    preview,
    conversationId,
    isAdmin = false,
    playbookId = null,
    onAccept,
}: DraftFeedbackProps) {
    const tracker = useFeedbackTracker();
    const { feedback, track } = tracker;
    const [text, setText] = useState('');
    const [quoted, setQuoted] = useState('');
    const [sending, setSending] = useState(false);
    const [sendError, setSendError] = useState<ApiError | null>(null);
    const [focused, setFocused] = useState(false);

    // Keep the latest selection as the quoted text until the person clears it.
    useEffect(() => {
        if (selection) setQuoted(selection.slice(0, 2000));
    }, [selection]);

    // After a reload in the same tab, pick up the feedback given on this draft.
    useEffect(() => {
        const id = recalledFeedback(runId);
        if (!id) return;
        loopApi
            .feedback(id)
            .then((f) => (f.run_id === null || f.run_id === runId ? track(f) : rememberFeedback(runId, null)))
            .catch(() => rememberFeedback(runId, null));
    }, [runId, track]);

    const send = useCallback(
        async (body: string, scope?: FeedbackScope) => {
            if (!conversationId || !body.trim()) return;
            setSending(true);
            setSendError(null);
            try {
                const f = await loopApi.submitFeedback(conversationId, {
                    run_id: runId,
                    text: body.trim(),
                    selected_text: quoted || null,
                    scope_override: scope ?? null,
                    playbook_id: scope === 'playbook' ? playbookId : null,
                });
                rememberFeedback(runId, f.id);
                track(f);
            } catch (e) {
                setSendError(toApiError(e));
            } finally {
                setSending(false);
            }
        },
        [conversationId, runId, quoted, playbookId, track],
    );

    function reset() {
        rememberFeedback(runId, null);
        track(null);
        preview.clear();
        setText('');
        setQuoted('');
    }

    if (!conversationId) return null;

    if (feedback) {
        return (
            <div className="mt-2 rounded-md border border-border bg-background">
                <div className="flex items-start gap-2 border-b border-border px-2.5 py-1.5">
                    <MessageSquareTextIcon className="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                    <p className="min-w-0 flex-1 text-xs">
                        <span className="text-muted-foreground">You said: </span>
                        <span className="whitespace-pre-wrap">{feedback.text}</span>
                        {feedback.selected_text && (
                            <span className="block truncate text-[11px] text-muted-foreground">About “{feedback.selected_text}”</span>
                        )}
                    </p>
                    <button type="button" className="shrink-0 text-[11px] text-muted-foreground hover:text-foreground" onClick={reset}>
                        New feedback
                    </button>
                </div>
                <div className="max-h-[45vh] overflow-y-auto px-2.5 py-2">
                    <FeedbackFlow
                        tracker={tracker}
                        isAdmin={isAdmin}
                        playbookId={playbookId}
                        draftText={draftText}
                        preview={preview}
                        onAccept={onAccept}
                        onRetry={() =>
                            feedback.text &&
                            void send(
                                feedback.text,
                                feedback.scope_overridden && feedback.scope && feedback.scope !== 'unclear' ? feedback.scope : undefined,
                            )
                        }
                        retrying={sending}
                    />
                    <ErrorLine error={sendError} className="mt-1" />
                </div>
            </div>
        );
    }

    const expanded = focused || text.length > 0;
    return (
        <form
            className="mt-2 space-y-1.5"
            onSubmit={(e) => {
                e.preventDefault();
                void send(text);
            }}
        >
            {quoted && (
                <div className="flex items-center gap-1 rounded bg-muted/60 px-2 py-1 text-[11px] text-muted-foreground">
                    <span className="min-w-0 flex-1 truncate">About the selected text: “{quoted}”</span>
                    <button
                        type="button"
                        aria-label="Forget the selected text"
                        onClick={() => setQuoted('')}
                        className="shrink-0 hover:text-foreground"
                    >
                        <XIcon className="h-3 w-3" />
                    </button>
                </div>
            )}
            <div className="flex items-end gap-1.5">
                <Textarea
                    value={text}
                    onChange={(e) => setText(e.target.value)}
                    onFocus={() => setFocused(true)}
                    onBlur={() => setFocused(false)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
                            e.preventDefault();
                            void send(text);
                        }
                    }}
                    rows={expanded ? 3 : 1}
                    maxLength={4000}
                    placeholder={
                        quoted
                            ? 'What is wrong with this bit? e.g. “never promise a delivery date”'
                            : 'Teach the agent: what should change in this draft?'
                    }
                    aria-label="Feedback on this draft"
                    className="min-h-8 resize-none bg-background py-1.5 text-xs"
                />
                <Button type="submit" size="sm" className="h-8 shrink-0 px-2.5 text-xs" disabled={!text.trim() || sending}>
                    {sending ? <Spinner className="h-3 w-3" /> : <SendIcon className="h-3 w-3" />}
                    <span className="sr-only sm:not-sr-only sm:ml-1">Teach</span>
                </Button>
            </div>
            {expanded && (
                <p className="text-[10px] text-muted-foreground">
                    Tip: select the words in the draft first. Enter sends, Shift+Enter adds a line. Nothing changes until it is approved.
                </p>
            )}
            <ErrorLine error={sendError} />
        </form>
    );
}

// ── Status views (shared with "Teach the agent from this edit") ──────────────

type Tracker = ReturnType<typeof useFeedbackTracker>;

export function FeedbackFlow({
    tracker,
    isAdmin,
    playbookId,
    draftText,
    preview,
    onAccept,
    onRetry,
    retrying,
}: {
    tracker: Tracker;
    isAdmin: boolean;
    playbookId: number | null;
    draftText: string;
    /** Absent when there is no draft panel to show a preview in (the reply was already sent). */
    preview?: DraftPreview | null;
    onAccept?: (text: string) => void;
    onRetry: () => void;
    retrying?: boolean;
}) {
    const { feedback, error } = tracker;
    if (!feedback) return null;

    return (
        <div className="space-y-2.5">
            {feedback.status !== 'pending' && feedback.status !== 'failed' && <ScopeChips tracker={tracker} playbookId={playbookId} />}
            <StatusBody
                tracker={tracker}
                isAdmin={isAdmin}
                draftText={draftText}
                preview={preview ?? null}
                onAccept={onAccept}
                onRetry={onRetry}
                retrying={retrying}
            />
            <ErrorLine error={error} />
        </div>
    );
}

function ScopeChips({ tracker, playbookId }: { tracker: Tracker; playbookId: number | null }) {
    const { feedback, track } = tracker;
    const [busy, setBusy] = useState<FeedbackScope | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    if (!feedback) return null;
    const current = feedback.scope && feedback.scope !== 'unclear' ? feedback.scope : null;
    const offered = (feedback.scopes?.length ? feedback.scopes : SCOPE_ORDER).filter((s) => SCOPE_LABELS[s]);
    const locked = feedback.proposal?.status === 'applied';

    async function choose(scope: FeedbackScope) {
        if (!feedback || scope === current) return;
        setBusy(scope);
        setError(null);
        try {
            track(await loopApi.setScope(feedback.id, scope, scope === 'playbook' && playbookId ? { playbook_id: playbookId } : {}));
        } catch (e) {
            setError(toApiError(e));
        } finally {
            setBusy(null);
        }
    }

    return (
        <div className="space-y-1">
            <p className="text-[11px] text-muted-foreground">
                {current ? 'This belongs to:' : 'Where does this belong?'}
                {feedback.scope_overridden && current ? ' (your choice)' : ''}
            </p>
            <div className="flex flex-wrap gap-1" role="radiogroup" aria-label="Where this correction belongs">
                {offered.map((scope) => (
                    <button
                        key={scope}
                        type="button"
                        role="radio"
                        aria-checked={scope === current}
                        title={SCOPE_HELP[scope]}
                        disabled={busy !== null || locked}
                        onClick={() => void choose(scope)}
                        className={cn(
                            'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] transition-colors disabled:opacity-60',
                            scope === current
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border bg-background text-muted-foreground hover:border-primary/40 hover:text-foreground',
                        )}
                    >
                        {busy === scope && <Spinner className="h-2.5 w-2.5" />}
                        {SCOPE_LABELS[scope]}
                    </button>
                ))}
            </div>
            {feedback.reason && !feedback.scope_overridden && <p className="text-[11px] text-muted-foreground">Why: {feedback.reason}</p>}
            <ErrorLine error={error} />
        </div>
    );
}

function Working({ children }: { children: ReactNode }) {
    return (
        <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
            <Spinner className="h-3 w-3" /> {children}
        </p>
    );
}

function StatusBody({
    tracker,
    isAdmin,
    draftText,
    preview,
    onAccept,
    onRetry,
    retrying,
}: {
    tracker: Tracker;
    isAdmin: boolean;
    draftText: string;
    preview: DraftPreview | null;
    onAccept?: (text: string) => void;
    onRetry: () => void;
    retrying?: boolean;
}) {
    const { feedback } = tracker;
    if (!feedback) return null;

    switch (feedback.status) {
        case 'pending':
            return <Working>Working out where this belongs…</Working>;
        case 'question':
            return <QuestionView tracker={tracker} />;
        case 'one_off':
            return (
                <div className="space-y-2">
                    <p className="text-xs">
                        <span className="font-medium">Just this reply.</span>{' '}
                        <span className="text-muted-foreground">
                            Nothing is saved for next time. Pick another option above to teach the agent for good.
                        </span>
                    </p>
                    {feedback.one_off_instruction && (
                        <p className="text-[11px] text-muted-foreground">Instruction used: {feedback.one_off_instruction}</p>
                    )}
                    <PreviewView
                        feedback={feedback}
                        draftText={draftText}
                        preview={preview}
                        onAccept={onAccept}
                        label="Re-written for this reply only"
                    />
                </div>
            );
        case 'proposal':
            return feedback.proposal ? (
                <ProposalView
                    tracker={tracker}
                    proposal={feedback.proposal}
                    isAdmin={isAdmin}
                    draftText={draftText}
                    preview={preview}
                    onAccept={onAccept}
                />
            ) : (
                <Working>Preparing the change…</Working>
            );
        case 'suggestions_only':
            return (
                <SuggestionNotes
                    suggestions={feedback.suggestions}
                    intro="This needs a setting changed, which the AI never does itself. Here is what it suggests:"
                />
            );
        case 'failed':
        default:
            return (
                <div className="space-y-1.5">
                    <ErrorLine error={feedback.error || 'Something went wrong while working out this feedback.'} />
                    <Button size="xs" variant="outline" className="h-6 text-[11px]" onClick={onRetry} disabled={retrying}>
                        {retrying ? <Spinner className="h-3 w-3" /> : <RotateCcwIcon className="h-3 w-3" />} Try again
                    </Button>
                </div>
            );
    }
}

function QuestionView({ tracker }: { tracker: Tracker }) {
    const { feedback, track } = tracker;
    const [answer, setAnswer] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    if (!feedback) return null;

    async function submit() {
        if (!feedback || !answer.trim()) return;
        setBusy(true);
        setError(null);
        try {
            track(await loopApi.answer(feedback.id, answer.trim()));
            setAnswer('');
        } catch (e) {
            setError(toApiError(e));
        } finally {
            setBusy(false);
        }
    }

    return (
        <form
            className="space-y-1.5"
            onSubmit={(e) => {
                e.preventDefault();
                void submit();
            }}
        >
            <p className="flex items-start gap-1.5 text-xs">
                <HelpCircleIcon className="mt-0.5 h-3.5 w-3.5 shrink-0 text-info" />
                <span className="font-medium">{feedback.question || 'Can you say a bit more about what should change?'}</span>
            </p>
            <div className="flex items-end gap-1.5">
                <Textarea
                    value={answer}
                    onChange={(e) => setAnswer(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
                            e.preventDefault();
                            void submit();
                        }
                    }}
                    rows={2}
                    maxLength={2000}
                    autoFocus
                    aria-label="Your answer"
                    className="min-h-8 resize-none py-1.5 text-xs"
                />
                <Button type="submit" size="sm" className="h-8 shrink-0 px-2.5 text-xs" disabled={!answer.trim() || busy}>
                    {busy ? <Spinner className="h-3 w-3" /> : 'Answer'}
                </Button>
            </div>
            <ErrorLine error={error} />
        </form>
    );
}

/** The re-written draft: shown in the panel (when there is one) plus an old/new comparison here. */
function PreviewView({
    feedback,
    draftText,
    preview,
    onAccept,
    label,
    proposal,
}: {
    feedback: Feedback;
    draftText: string;
    preview: DraftPreview | null;
    onAccept?: (text: string) => void;
    label: string;
    proposal?: Proposal | null;
}) {
    const data = feedback.preview ?? proposal?.preview ?? null;
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const shownFor = useRef<string | null>(null);
    const newDraft = data?.status === 'ready' ? (data.new_draft ?? null) : null;

    // Show each new re-write in the panel once; "Show current draft" there wins after that.
    useEffect(() => {
        if (!preview || !newDraft || shownFor.current === newDraft) return;
        shownFor.current = newDraft;
        preview.show(newDraft, label);
    }, [newDraft, preview, label]);

    async function redraft() {
        if (!proposal) return;
        setBusy(true);
        setError(null);
        try {
            await loopApi.preview(proposal.id);
        } catch (e) {
            setError(toApiError(e));
        } finally {
            setBusy(false);
        }
    }

    const redraftButton = proposal && proposal.run_id && isOpenProposal(proposal.status) && (
        <button
            type="button"
            className="text-[11px] text-muted-foreground underline hover:text-foreground disabled:opacity-50"
            onClick={() => void redraft()}
            disabled={busy}
        >
            {busy ? 'Starting…' : 'Re-write again'}
        </button>
    );

    if (!data || data.status === 'pending' || data.status === 'running') {
        return <Working>Re-writing the draft with this change…</Working>;
    }
    if (data.status === 'failed' || data.status === 'skipped') {
        return (
            <div className="space-y-1">
                <p className="text-xs text-muted-foreground">
                    {data.status === 'failed'
                        ? `The re-written draft could not be made${data.error ? `: ${data.error}` : '.'}`
                        : 'No re-written draft for this change.'}
                </p>
                {redraftButton}
                <ErrorLine error={error} />
            </div>
        );
    }

    const oldDraft = data.old_draft ?? draftText;
    const showing = !!preview && !!newDraft && preview.text === newDraft;
    return (
        <div className="space-y-1.5">
            {newDraft ? (
                <div className="flex flex-wrap items-center gap-1.5">
                    <p className="flex-1 text-xs">
                        <CheckCircle2Icon className="mr-1 inline h-3.5 w-3.5 text-success" />
                        {preview
                            ? showing
                                ? 'The new draft is shown above.'
                                : 'A new draft is ready.'
                            : 'Here is how the agent would write it now.'}
                    </p>
                    {preview && !showing && (
                        <Button size="xs" variant="outline" className="h-6 text-[11px]" onClick={() => preview.show(newDraft, label)}>
                            Show new draft
                        </Button>
                    )}
                    {preview && onAccept && (
                        <Button size="xs" className="h-6 text-[11px]" onClick={() => onAccept(newDraft)}>
                            Use this draft
                        </Button>
                    )}
                </div>
            ) : (
                <p className="text-xs text-warning">
                    With this change the agent would hand this conversation to a person
                    {data.new_handover_label
                        ? `: ${data.new_handover_label}`
                        : data.new_handover_reason
                          ? ` (${data.new_handover_reason})`
                          : '.'}
                </p>
            )}
            <Disclosure title="Compare old and new draft" defaultOpen={!preview}>
                <div className="grid gap-1.5 sm:grid-cols-2">
                    <div className="min-w-0 rounded border border-border bg-muted/20 p-2">
                        <p className="mb-0.5 text-[10px] font-semibold uppercase text-muted-foreground">Before</p>
                        <p className="max-h-48 overflow-y-auto whitespace-pre-wrap text-xs">{oldDraft || '—'}</p>
                    </div>
                    <div className="min-w-0 rounded border border-primary/20 bg-primary/5 p-2">
                        <p className="mb-0.5 text-[10px] font-semibold uppercase text-primary">After</p>
                        <p className="max-h-48 overflow-y-auto whitespace-pre-wrap text-xs">{newDraft ?? '(hands over to a person)'}</p>
                    </div>
                </div>
            </Disclosure>
            {redraftButton}
            <ErrorLine error={error} />
        </div>
    );
}

function ProposalView({
    tracker,
    proposal,
    isAdmin,
    draftText,
    preview,
    onAccept,
}: {
    tracker: Tracker;
    proposal: Proposal;
    isAdmin: boolean;
    draftText: string;
    preview: DraftPreview | null;
    onAccept?: (text: string) => void;
}) {
    const { feedback, setProposal } = tracker;
    const diff = feedback?.preview?.instruction_diff?.length ? feedback.preview.instruction_diff : proposal.diff;
    const open = isOpenProposal(proposal.status);
    const label = `Proposed: ${proposal.title ?? 'instruction change'}`;

    return (
        <div className="space-y-2.5">
            <div className="flex flex-wrap items-center gap-1.5">
                <ProposalStatusBadge status={proposal.status} />
                <span className="min-w-0 flex-1 truncate text-xs font-medium">{proposal.title}</span>
                {isAdmin && (
                    <a
                        href={proposalUrl(proposal.id)}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-0.5 text-[11px] text-muted-foreground hover:text-primary"
                    >
                        Full review <ExternalLinkIcon className="h-2.5 w-2.5" />
                    </a>
                )}
            </div>

            <Block title="The change">
                <DiffEntries entries={diff} />
            </Block>

            {feedback && open && (
                <Block title="New draft">
                    <PreviewView
                        feedback={feedback}
                        draftText={draftText}
                        preview={preview}
                        onAccept={onAccept}
                        label={label}
                        proposal={proposal}
                    />
                </Block>
            )}

            <Block title="Conflicts">
                <ConflictList proposal={proposal} canManage={isAdmin} onChange={setProposal} />
            </Block>

            {(proposal.gate || (isAdmin && open)) && (
                <Block title="Saved tests">
                    {proposal.gate ? (
                        <GateSummary simulation={proposal.gate} canManage={isAdmin} title="Re-ran saved tests" />
                    ) : (
                        <RunTests proposal={proposal} onChange={setProposal} />
                    )}
                </Block>
            )}

            <SuggestionNotes suggestions={proposal.suggestions} />

            <Decision proposal={proposal} isAdmin={isAdmin} onChange={setProposal} />
        </div>
    );
}

function Block({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div className="space-y-1">
            <p className="text-[10px] font-semibold uppercase tracking-[0.08em] text-muted-foreground/70">{title}</p>
            {children}
        </div>
    );
}

function RunTests({ proposal, onChange }: { proposal: Proposal; onChange: (p: Proposal) => void }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    return (
        <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
            <span>Not tested against saved conversations yet.</span>
            <Button
                size="xs"
                variant="outline"
                className="h-6 text-[11px]"
                disabled={busy}
                onClick={async () => {
                    setBusy(true);
                    setError(null);
                    try {
                        onChange((await loopApi.gate(proposal.id)).proposal);
                    } catch (e) {
                        setError(toApiError(e));
                    } finally {
                        setBusy(false);
                    }
                }}
            >
                {busy ? <Spinner className="h-3 w-3" /> : null} Run saved tests
            </Button>
            <ErrorLine error={error} />
        </div>
    );
}

/** Approve / Reject / Rebase for admins; a plain status line for everyone else. */
export function Decision({
    proposal,
    isAdmin,
    onChange,
    compact = true,
}: {
    proposal: Proposal;
    isAdmin: boolean;
    onChange: (p: Proposal) => void;
    compact?: boolean;
}) {
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [note, setNote] = useState('');
    const open = isOpenProposal(proposal.status);
    const gateRunning = !!proposal.gate && isSimulationActive(proposal.gate.status);
    const blocking = proposal.blocking_conflicts > 0;

    async function act(key: 'approve' | 'reject' | 'rebase') {
        setBusy(key);
        setError(null);
        try {
            const next =
                key === 'approve'
                    ? await loopApi.approve(proposal.id, note.trim() || undefined)
                    : key === 'reject'
                      ? await loopApi.reject(proposal.id, note.trim() || undefined)
                      : await loopApi.rebase(proposal.id);
            onChange(next);
        } catch (e) {
            const err = toApiError(e);
            setError(err);
            if (err.body?.proposal) onChange(err.body.proposal);
        } finally {
            setBusy(null);
        }
    }

    if (proposal.status === 'applied') {
        return (
            <p className="flex items-center gap-1.5 text-xs text-success">
                <CheckCircle2Icon className="h-3.5 w-3.5" /> Approved and live. This conversation was saved as a test case so it stays
                fixed.
            </p>
        );
    }
    if (proposal.status === 'rejected') return <p className="text-xs text-muted-foreground">Rejected. Nothing was changed.</p>;
    if (proposal.status === 'reverted') return <p className="text-xs text-muted-foreground">This change was applied and later undone.</p>;
    if (!open) return null;

    if (!isAdmin) {
        return (
            <p className="rounded-md bg-muted/40 px-2 py-1.5 text-xs text-muted-foreground">
                Sent to an admin for approval. Nothing changes until they approve it.
            </p>
        );
    }

    const why = blocking
        ? 'Resolve the conflicts marked "must resolve" first.'
        : gateRunning
          ? 'Waiting for the saved tests to finish.'
          : proposal.status === 'stale'
            ? 'The live instructions changed since this was written: rebase first.'
            : null;

    return (
        <div className="space-y-1.5 border-t border-border pt-2">
            {!compact && (
                <Textarea
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    rows={2}
                    maxLength={2000}
                    placeholder="Note for the record (optional)"
                    aria-label="Review note"
                    className="text-xs"
                />
            )}
            <div className="flex flex-wrap items-center gap-1.5">
                {proposal.status === 'stale' ? (
                    <Button size="sm" className="h-7 text-xs" disabled={busy !== null} onClick={() => void act('rebase')}>
                        {busy === 'rebase' ? <Spinner className="h-3 w-3" /> : <RotateCcwIcon className="h-3 w-3" />} Rebase on current
                        instructions
                    </Button>
                ) : (
                    <Button
                        size="sm"
                        className="h-7 text-xs"
                        disabled={busy !== null || blocking || gateRunning}
                        onClick={() => void act('approve')}
                    >
                        {busy === 'approve' ? <Spinner className="h-3 w-3" /> : <CheckCircle2Icon className="h-3 w-3" />} Approve
                    </Button>
                )}
                <Button size="sm" variant="outline" className="h-7 text-xs" disabled={busy !== null} onClick={() => void act('reject')}>
                    {busy === 'reject' ? <Spinner className="h-3 w-3" /> : <XIcon className="h-3 w-3" />} Reject
                </Button>
                {why && <span className="text-[11px] text-muted-foreground">{why}</span>}
            </div>
            <ErrorLine error={error} />
        </div>
    );
}

/** Preview state for one open conversation. Call in ConversationView and pass to AiDraftPanel. */
export function useDraftPreview(): DraftPreview {
    const [state, setState] = useState<{ text: string; label: string | null } | null>(null);
    const show = useCallback((text: string, label: string | null = null) => setState({ text, label }), []);
    const clear = useCallback(() => setState(null), []);

    return { text: state?.text ?? null, label: state?.label ?? null, show, clear };
}
