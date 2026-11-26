import { useCallback, useRef, useState, type ReactNode } from 'react';
import { AlertTriangleIcon, BrainIcon, CheckIcon, EyeIcon, HandIcon, HourglassIcon, RefreshCwIcon, XIcon } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { cn, sanitizeHtml } from '@/lib/utils';
import { capitalise, handoverLabel } from './agentLabels';
import DraftFeedback, { type DraftPreview } from './DraftFeedback';
import { draftToHtml } from './html';
import { DraftWhy, HandoverWhy } from './RunWhy';
import type { ActionGateInfo } from './ConversationActions';
import type { AgentRun, AiDraft } from './types';
import type { AiDraftError } from './useAiDraft';

export interface AiDraftPanelProps {
    draft: AiDraft | null;
    /** Tokens streamed so far for a draft being generated. */
    streamingText: string;
    generating: boolean;
    error: AiDraftError | null;
    /** Machine-readable reason the agent asked for a person (conversations.handover_reason). */
    handoverReason?: string | null;
    aiConfigured: boolean;
    onAccept: () => void;
    onRetry: () => void;
    onDismiss: () => void;
    onClearError: () => void;
    /** Latest live run (GET /agent/conversations/{id}/run); null when unknown or unavailable. */
    run?: AgentRun | null;
    /** Admins get edit links from the explanation. */
    isAdmin?: boolean;
    /** Temporary "preview" draft shown in place of the real one (see DraftFeedback). */
    preview?: DraftPreview;
    /** Conversation id: enables the feedback box under agent drafts. */
    conversationId?: number;
    /** Insert a given text into the reply (the feedback box's "Use this draft"). */
    onAcceptText?: (text: string) => void;
    /** Rendered under the draft, inside the panel (module slot `conversation.draft.after`). */
    children?: ReactNode;
    /** Reply coupling with Shopify actions (GET /agent/actions/conversations/{id}). */
    actionGate?: ActionGateInfo | null;
}

/**
 * The AI draft for the open conversation. States, in order of precedence:
 * error banner · "Needs a human" banner (+ what the agent found) · generating
 * (skeleton or streamed text) · draft with Accept / Retry / Dismiss, the "why"
 * disclosure and the feedback seam. Renders nothing when there is nothing to say.
 */
export default function AiDraftPanel({
    draft,
    streamingText,
    generating,
    error,
    handoverReason,
    aiConfigured,
    onAccept,
    onRetry,
    onDismiss,
    onClearError,
    run,
    isAdmin = false,
    preview,
    conversationId,
    onAcceptText,
    children,
    actionGate,
}: AiDraftPanelProps) {
    const draftRef = useRef<HTMLDivElement>(null);
    const [selection, setSelection] = useState('');

    const handoverRun = run && run.decision === 'handover' ? run : null;
    const handover = handoverRun?.handover_label ?? handoverLabel(handoverReason ?? handoverRun?.handover_reason);
    // Only explain a draft with the run that wrote it.
    const draftRun = run && draft && run.decision === 'draft' && draft.id !== null && run.ai_suggestion_id === draft.id ? run : null;
    const shownText = preview?.text ?? draft?.content ?? '';
    // The gate is about the latest live run; only apply it to the draft that run wrote.
    const gate = actionGate && draftRun && actionGate.runId === draftRun.id && actionGate.state !== 'ready' ? actionGate : null;
    const waiting = gate?.state === 'waiting';
    const needsRewrite = gate?.state === 'needs_rewrite';

    const readSelection = useCallback(() => {
        const sel = window.getSelection();
        const node = draftRef.current;
        if (!sel || !node || sel.rangeCount === 0 || !node.contains(sel.anchorNode)) {
            setSelection('');
            return;
        }
        setSelection(sel.toString().trim());
    }, []);

    if (!error && !handover && !generating && !draft) return null;

    return (
        <div className="space-y-2 px-4 pt-3">
            {error && (
                <div className="flex items-start gap-2 rounded-lg border border-destructive/20 bg-destructive/10 px-3 py-2.5">
                    <AlertTriangleIcon className="h-4 w-4 text-destructive shrink-0 mt-0.5" />
                    <div className="flex-1 min-w-0">
                        <p className="text-xs font-semibold text-destructive">{error.title}</p>
                        <p className="text-xs text-muted-foreground mt-0.5">{error.message}</p>
                    </div>
                    <Button size="sm" variant="ghost" className="h-6 w-6 p-0 shrink-0" onClick={onClearError} aria-label="Dismiss error">
                        <XIcon className="h-3 w-3" />
                    </Button>
                </div>
            )}

            {handover && (
                <div className="rounded-lg border border-warning/30 bg-warning/10 px-3 py-2.5">
                    <div className="flex items-start gap-2">
                        <HandIcon className="h-4 w-4 text-warning shrink-0 mt-0.5" />
                        <div className="flex-1 min-w-0">
                            <p className="text-xs font-semibold text-warning">Needs a human: {handover}</p>
                            {!draft && !generating && (
                                <p className="text-xs text-muted-foreground mt-0.5">
                                    The agent did not draft a reply for this conversation.
                                </p>
                            )}
                        </div>
                        {!draft && !generating && aiConfigured && (
                            <Button size="sm" variant="ghost" className="h-6 text-[11px] px-2 shrink-0" onClick={onRetry}>
                                Draft anyway
                            </Button>
                        )}
                    </div>
                    {handoverRun && !draft && <HandoverWhy run={handoverRun} isAdmin={isAdmin} conversationId={conversationId} />}
                </div>
            )}

            {(generating || draft) && (
                <div className="rounded-lg border border-info/20 bg-info/10 p-3">
                    <div className="flex items-center gap-1.5 mb-2">
                        <BrainIcon className={cn('h-4 w-4 text-info', generating && 'animate-pulse')} />
                        <span className="text-xs font-semibold text-info">{generating ? 'Drafting reply…' : 'AI draft'}</span>
                        {!generating && draftRun?.playbook && (
                            <span className="ml-auto truncate text-[11px] text-muted-foreground">{draftRun.playbook.name}</span>
                        )}
                    </div>

                    {generating ? (
                        streamingText ? (
                            <div className="text-xs text-foreground leading-relaxed bg-background rounded-md p-2.5 border border-info/20 max-h-40 overflow-y-auto whitespace-pre-wrap">
                                {streamingText}
                                <span className="inline-block w-0.5 h-3 bg-info ml-0.5 animate-pulse align-middle" />
                            </div>
                        ) : (
                            <div className="space-y-2 py-1">
                                {[1, 2, 3].map((i) => (
                                    <div key={i} className={cn('h-3 bg-info/25 rounded animate-pulse', i === 3 && 'w-3/5')} />
                                ))}
                            </div>
                        )
                    ) : (
                        draft && (
                            <>
                                {preview?.text != null && (
                                    <div className="mb-1.5 flex items-center gap-1.5 rounded-md bg-primary/10 px-2 py-1 text-[11px] text-primary">
                                        <EyeIcon className="h-3 w-3 shrink-0" />
                                        <span className="flex-1 truncate">
                                            {preview.label ? capitalise(preview.label) : 'Previewing a proposed draft'}
                                        </span>
                                        <button type="button" className="font-medium underline" onClick={preview.clear}>
                                            Show current draft
                                        </button>
                                    </div>
                                )}
                                <div
                                    ref={draftRef}
                                    onMouseUp={readSelection}
                                    onKeyUp={readSelection}
                                    className="prose prose-sm max-w-none text-xs text-foreground leading-relaxed bg-background rounded-md p-2.5 mb-2 border border-info/20 max-h-40 overflow-y-auto"
                                    dangerouslySetInnerHTML={{ __html: sanitizeHtml(draftToHtml(shownText)) }}
                                />
                                {draftRun && <DraftWhy run={draftRun} isAdmin={isAdmin} conversationId={conversationId} />}
                                {waiting && (
                                    <div className="mb-2 flex items-start gap-1.5 rounded-md border border-warning/40 bg-warning/10 px-2.5 py-1.5 text-xs">
                                        <HourglassIcon className="mt-0.5 h-3.5 w-3.5 shrink-0 text-warning" />
                                        <span>
                                            <span className="font-semibold">This reply waits for a Shopify action</span>
                                            {gate?.waitingFor.length ? `: ${gate.waitingFor.join(', ')}` : ''}. It says the action is done,
                                            so approve the action above and send the reply only once it shows{' '}
                                            <span className="font-medium">Done</span>.
                                        </span>
                                    </div>
                                )}
                                {needsRewrite && (
                                    <div className="mb-2 flex items-start gap-1.5 rounded-md border border-destructive/30 bg-destructive/10 px-2.5 py-1.5 text-xs">
                                        <AlertTriangleIcon className="mt-0.5 h-3.5 w-3.5 shrink-0 text-destructive" />
                                        <span className="flex-1">
                                            <span className="font-semibold">Needs rewriting:</span> this draft promises something that did
                                            not happen{gate?.failed.length ? ` (${gate.failed.join(', ')})` : ''}. The agent is writing a
                                            new one; you can also ask now.
                                        </span>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="h-6 shrink-0 px-2 text-[11px]"
                                            onClick={onRetry}
                                            disabled={!aiConfigured}
                                        >
                                            <RefreshCwIcon className="mr-1 h-3 w-3" /> Redraft
                                        </Button>
                                    </div>
                                )}
                                <div className="flex gap-2">
                                    <Button
                                        size="sm"
                                        className="flex-1 h-7 text-xs"
                                        onClick={onAccept}
                                        disabled={needsRewrite}
                                        variant={waiting ? 'outline' : 'default'}
                                        title={
                                            needsRewrite
                                                ? 'This draft promises an action that did not happen. Redraft it instead.'
                                                : waiting
                                                  ? 'You can edit it now, but send it only after the Shopify action is done.'
                                                  : undefined
                                        }
                                    >
                                        {waiting ? <HourglassIcon className="h-3 w-3 mr-1" /> : <CheckIcon className="h-3 w-3 mr-1" />}
                                        {waiting ? 'Accept (send after the action is done)' : 'Accept'}
                                    </Button>
                                    <Button size="sm" variant="outline" className="h-7 text-xs" onClick={onRetry} disabled={!aiConfigured}>
                                        <RefreshCwIcon className="h-3 w-3 mr-1" /> {needsRewrite ? 'Redraft' : 'Retry'}
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        className="h-7 w-7 p-0"
                                        onClick={onDismiss}
                                        aria-label="Dismiss draft"
                                        title="Dismiss this draft (recorded as rejected feedback for the agent)"
                                    >
                                        <XIcon className="h-3 w-3" />
                                    </Button>
                                </div>
                                {draftRun && preview && (
                                    <DraftFeedback
                                        key={draftRun.id}
                                        runId={draftRun.id}
                                        draftText={draft.content}
                                        selection={selection}
                                        preview={preview}
                                        conversationId={conversationId}
                                        isAdmin={isAdmin}
                                        playbookId={draftRun.playbook?.id ?? null}
                                        onAccept={onAcceptText}
                                    />
                                )}
                                {children}
                            </>
                        )
                    )}
                </div>
            )}
        </div>
    );
}
