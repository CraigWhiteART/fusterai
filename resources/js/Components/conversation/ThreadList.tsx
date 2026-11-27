import { useEffect, useRef, useState, type ReactNode } from 'react';
import { ArrowDownIcon } from 'lucide-react';
import SlotRenderer from '@/Components/SlotRenderer';
import { ChatThreadItem, EmailThreadItem } from './ThreadItem';
import type { DetailConversation, DetailThread } from './types';

interface Props {
    conversation: DetailConversation;
    threads: DetailThread[];
    isChat: boolean;
    commerceAssist?: unknown;
    visitorTyping?: boolean;
    onCancelSchedule: (threadId: number) => void;
    /** Header badges by thread id (the QA badge on reviewed replies). */
    threadBadges?: Record<number, ReactNode>;
    /** Rendered after the newest message, in the same scroll (AI draft, action cards). */
    after?: ReactNode;
    /** When set and `after` is scrolled out of view below, a pill with this label jumps to it. */
    afterLabel?: string | null;
}

/**
 * Scrolls the newest thread into view on open and whenever a thread arrives,
 * so the latest message is what you see first.
 */
export default function ThreadList({
    conversation,
    threads,
    isChat,
    commerceAssist,
    visitorTyping,
    onCancelSchedule,
    threadBadges,
    after,
    afterLabel,
}: Props) {
    const scrollRef = useRef<HTMLDivElement>(null);
    const lastRef = useRef<HTMLDivElement>(null);
    const afterRef = useRef<HTMLDivElement>(null);
    const [afterBelow, setAfterBelow] = useState(false);
    const count = threads.length;

    // Scroll only this pane (scrollIntoView would also move overflow-hidden ancestors).
    useEffect(() => {
        const pane = scrollRef.current;
        const last = lastRef.current;
        if (!pane) return;
        pane.scrollTop = isChat || !last ? pane.scrollHeight : Math.max(0, last.offsetTop - 16);
    }, [count, isChat]);

    // Is the content after the thread (the AI draft) below the visible part of the pane?
    useEffect(() => {
        const pane = scrollRef.current;
        const node = afterRef.current;
        if (!pane || !node || typeof IntersectionObserver === 'undefined') return;
        const observer = new IntersectionObserver(
            ([entry]) => setAfterBelow(!entry.isIntersecting && entry.boundingClientRect.top > (entry.rootBounds?.bottom ?? 0)),
            { root: pane },
        );
        observer.observe(node);
        return () => observer.disconnect();
    }, []);

    function jumpToAfter() {
        const pane = scrollRef.current;
        const node = afterRef.current;
        if (pane && node) pane.scrollTo({ top: Math.max(0, node.offsetTop - 16), behavior: 'smooth' });
    }

    return (
        <div className="relative flex-1 min-h-0 flex flex-col">
            <div ref={scrollRef} className="relative flex-1 overflow-y-auto bg-muted/20">
                <div className={isChat ? 'px-4 py-4 space-y-3' : 'w-full max-w-4xl mx-auto px-5 py-6 space-y-5'}>
                    {threads.length === 0 && <p className="text-sm text-muted-foreground text-center py-10">No messages yet</p>}
                    {threads.map((thread, i) => (
                        <div key={thread.id} ref={i === threads.length - 1 ? lastRef : undefined}>
                            {isChat ? (
                                <ChatThreadItem thread={thread} conversation={conversation} commerceAssist={commerceAssist} />
                            ) : (
                                <EmailThreadItem
                                    thread={thread}
                                    conversation={conversation}
                                    commerceAssist={commerceAssist}
                                    onCancelSchedule={onCancelSchedule}
                                    badge={threadBadges?.[thread.id]}
                                />
                            )}
                        </div>
                    ))}
                    {visitorTyping && <p className="text-[11px] text-muted-foreground px-1">Visitor is typing…</p>}
                    <SlotRenderer name="conversation.threads.after" props={{ conversation, threads }} />
                    {/* Always mounted so the observer has a target; empty when there is nothing after the thread. */}
                    <div ref={afterRef} className="space-y-3 empty:hidden">
                        {after}
                    </div>
                </div>
            </div>
            {afterLabel && afterBelow && (
                <button
                    type="button"
                    onClick={jumpToAfter}
                    className="absolute bottom-3 left-1/2 -translate-x-1/2 flex items-center gap-1.5 rounded-full border border-info/30 bg-background px-3 py-1.5 text-xs font-medium text-info shadow-lg hover:bg-info/10 transition-colors"
                >
                    <ArrowDownIcon className="h-3.5 w-3.5" />
                    {afterLabel}
                </button>
            )}
        </div>
    );
}
