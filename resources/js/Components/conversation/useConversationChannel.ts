import { useEffect, useRef, useState } from 'react';
import type { DetailThread } from './types';

export interface Viewer {
    id: number;
    name: string;
}

export interface ConversationChannelHandlers {
    /** NewThreadReceived: inbound mail, our replies, replies ingested from Gmail, notes. */
    onThread?: (thread: DetailThread) => void;
    /** ConversationUpdated: status, priority, assignment, summary, last reply changed. */
    onUpdated?: () => void;
    /** Streaming draft tokens (laravel/ai broadcast). */
    onDraftDelta?: (delta: string) => void;
    /** `runId` is the agent run that produced the draft; null for a legacy suggestion. */
    onDraftReady?: (draft: { id: number; content: string; runId: number | null }) => void;
    onDraftFailed?: (reason: string) => void;
    /** `agent.action.updated`: a Shopify action of this conversation changed state (re-fetch it). */
    onActionUpdated?: (e: { execution_id?: number; status?: string; run_id?: number | null }) => void;
}

/**
 * Real-time subscriptions for one open conversation: the private conversation
 * channel (threads, drafts, actions and, for chat, visitor typing) and the
 * presence channel (who else is viewing). The public `livechat.{token}`
 * channel belongs to the visitor's widget only.
 *
 * Mount it once per conversation (ConversationView is keyed by id), so
 * switching conversations in the split inbox leaves the old channels.
 */
export function useConversationChannel(conversationId: number, isChat: boolean, handlers: ConversationChannelHandlers) {
    const handlersRef = useRef(handlers);
    handlersRef.current = handlers;

    const [viewers, setViewers] = useState<Viewer[]>([]);
    const [visitorTyping, setVisitorTyping] = useState(false);

    useEffect(() => {
        const echo = window.Echo;
        if (!echo) return;

        const privateName = `conversation.${conversationId}`;
        const presenceName = `conversation.${conversationId}.presence`;
        let typingTimer: ReturnType<typeof setTimeout> | null = null;

        const onThread = (e: { thread: DetailThread }) => {
            if (e?.thread) handlersRef.current.onThread?.(e.thread);
        };

        echo.private(privateName)
            .listen('.thread.created', onThread)
            .listen('.conversation.updated', () => handlersRef.current.onUpdated?.())
            .listen('.text_delta', (e: { delta?: string }) => {
                if (typeof e?.delta === 'string') handlersRef.current.onDraftDelta?.(e.delta);
            })
            .listen('.ai.suggestion.ready', (e: { suggestion_id: number; content: string; run_id?: number | null }) =>
                handlersRef.current.onDraftReady?.({ id: e.suggestion_id, content: e.content, runId: e.run_id ?? null }),
            )
            .listen('.ai.suggestion.failed', (e: { reason?: string }) =>
                handlersRef.current.onDraftFailed?.(e?.reason || 'The AI could not generate a draft.'),
            )
            .listen('.agent.action.updated', (e: { execution_id?: number; status?: string; run_id?: number | null }) =>
                handlersRef.current.onActionUpdated?.(e ?? {}),
            )
            .listen('.visitor.typing', () => {
                if (!isChat) return;
                setVisitorTyping(true);
                if (typingTimer) clearTimeout(typingTimer);
                typingTimer = setTimeout(() => setVisitorTyping(false), 3000);
            });

        echo.join(presenceName)
            .here((members: Viewer[]) => setViewers(members))
            .joining((member: Viewer) => setViewers((v) => [...v.filter((x) => x.id !== member.id), member]))
            .leaving((member: Viewer) => setViewers((v) => v.filter((x) => x.id !== member.id)));

        return () => {
            if (typingTimer) clearTimeout(typingTimer);
            echo.leave(privateName);
            echo.leave(presenceName);
            setViewers([]);
            setVisitorTyping(false);
        };
    }, [conversationId, isChat]);

    return { viewers, visitorTyping, setVisitorTyping };
}
