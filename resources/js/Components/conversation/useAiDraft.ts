import { useCallback, useEffect, useRef, useState } from 'react';
import type { AgentMode } from '@/types/agent';
import type { AiDraft } from './types';
import { agentOwnsDrafts } from './useAgentRun';

const TIMEOUT_MS = 90_000;

/** Prefix of the `ai.suggestion.failed` reason when the agent handed over (docs/agent-api.md). */
const HANDOVER_PREFIX = 'The AI agent handed this conversation to a human';

export interface AiDraftError {
    title: string;
    message: string;
}

export interface UseAiDraftOptions {
    /**
     * Resolves the agent mode (GET /agent/conversations/{id}/run). When the agent
     * owns drafting, a new draft is a forced re-run of the agent; otherwise
     * (off / shadow) a one-off manual agent draft is queued.
     */
    resolveMode?: () => Promise<AgentMode | null>;
}

/**
 * State for the AI draft of one conversation: the server's pending draft,
 * on-demand generation, streamed tokens and the ready/failed broadcasts. Feed
 * the `on*` handlers from useConversationChannel.
 *
 * Generation: POST /agent/conversations/{id}/regenerate when the agent owns
 * drafting (mode draft/live), POST /ai/conversations/{id}/suggest-reply otherwise.
 */
export function useAiDraft(conversationId: number, serverDraft: AiDraft | null, options: UseAiDraftOptions = {}) {
    const [draft, setDraft] = useState<AiDraft | null>(serverDraft);
    const [streamingText, setStreamingText] = useState('');
    const [requesting, setRequesting] = useState(false);
    const [error, setError] = useState<AiDraftError | null>(null);
    const [dismissed, setDismissed] = useState<number[]>([]);
    const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const resolveModeRef = useRef(options.resolveMode);
    resolveModeRef.current = options.resolveMode;

    // Adopt a new server draft after a reload, unless dismissed here or a request is running.
    const serverId = serverDraft?.id ?? null;
    const [seenServerId, setSeenServerId] = useState(serverId);
    if (serverId !== seenServerId) {
        setSeenServerId(serverId);
        if (!requesting) setDraft(serverDraft && !(serverDraft.id !== null && dismissed.includes(serverDraft.id)) ? serverDraft : null);
    }

    const clearTimer = useCallback(() => {
        if (timeoutRef.current) clearTimeout(timeoutRef.current);
        timeoutRef.current = null;
    }, []);

    useEffect(() => clearTimer, [clearTimer]);

    const stop = useCallback(
        (next: AiDraftError | null) => {
            clearTimer();
            setRequesting(false);
            setStreamingText('');
            setError(next);
        },
        [clearTimer],
    );

    const request = useCallback(async () => {
        setRequesting(true);
        setStreamingText('');
        setError(null);

        // Safety net: if nothing comes back, unblock the UI.
        clearTimer();
        timeoutRef.current = setTimeout(
            () => stop({ title: 'AI draft failed', message: 'Request timed out. The AI may be unavailable or overloaded.' }),
            TIMEOUT_MS,
        );

        const mode = (await resolveModeRef.current?.()) ?? null;

        if (agentOwnsDrafts(mode)) {
            try {
                await window.axios.post(`/agent/conversations/${conversationId}/regenerate`);
                setDraft(null);
            } catch (e: unknown) {
                const response = (e as { response?: { status?: number; data?: { message?: string } } }).response;
                if (response?.status === 409) {
                    stop({
                        title: 'The agent cannot redraft right now',
                        message: response.data?.message ?? 'The agent is switched off or its kill switch is on.',
                    });
                } else if (response?.status === 429) {
                    stop({ title: 'Too many redrafts', message: 'Wait a minute before asking the agent again.' });
                } else {
                    stop({ title: 'AI draft failed', message: 'Failed to queue a draft. Please try again.' });
                }
            }
            return;
        }

        // Agent off / shadow: a one-off agent draft for this person (never auto-sent).
        try {
            await window.axios.post(`/ai/conversations/${conversationId}/suggest-reply`);
            setDraft(null);
        } catch (e: unknown) {
            const response = (e as { response?: { status?: number; data?: { message?: string } } }).response;
            if (response?.status === 409) {
                stop({ title: 'The agent cannot draft right now', message: response.data?.message ?? 'The AI agent kill switch is on.' });
            } else {
                stop({ title: 'AI draft failed', message: 'Failed to queue a draft. Please try again.' });
            }
        }
    }, [clearTimer, conversationId, stop]);

    const onDelta = useCallback((delta: string) => setStreamingText((prev) => prev + delta), []);

    const onReady = useCallback(
        (next: { id: number; content: string }) => {
            stop(null);
            setDraft({ id: next.id, content: next.content });
        },
        [stop],
    );

    const onFailed = useCallback(
        (reason: string) => {
            // A handover is a decision, not an error: the run explains it.
            if (reason.startsWith(HANDOVER_PREFIX)) {
                stop(null);
                setDraft(null);
                return;
            }
            stop({ title: 'AI draft failed', message: reason });
        },
        [stop],
    );

    /** Mark the current draft accepted and hand it back for the composer. */
    const accept = useCallback((): AiDraft | null => {
        if (!draft) return null;
        if (draft.id) window.axios.patch(`/ai/suggestions/${draft.id}/accept`).catch(() => {});
        setDraft(null);
        return draft;
    }, [draft]);

    /**
     * Hide the draft and record the rejection (POST /agent/suggestions/{id}/dismiss,
     * a `rejected` feedback event). The hide is kept locally as well: the
     * conversation payload still lists a dismissed suggestion as pending.
     */
    const dismiss = useCallback(() => {
        const id = draft?.id;
        if (id) {
            setDismissed((prev) => [...prev, id]);
            window.axios.post(`/agent/suggestions/${id}/dismiss`).catch(() => {});
        }
        setDraft(null);
    }, [draft]);

    const clearError = useCallback(() => setError(null), []);

    return {
        draft,
        streamingText,
        generating: requesting || streamingText !== '',
        error,
        request,
        accept,
        dismiss,
        clearError,
        onDelta,
        onReady,
        onFailed,
    };
}
