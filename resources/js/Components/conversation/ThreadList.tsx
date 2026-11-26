import { useEffect, useRef, type ReactNode } from 'react';
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
}: Props) {
    const scrollRef = useRef<HTMLDivElement>(null);
    const lastRef = useRef<HTMLDivElement>(null);
    const count = threads.length;

    // Scroll only this pane (scrollIntoView would also move overflow-hidden ancestors).
    useEffect(() => {
        const pane = scrollRef.current;
        const last = lastRef.current;
        if (!pane) return;
        pane.scrollTop = isChat || !last ? pane.scrollHeight : Math.max(0, last.offsetTop - 16);
    }, [count, isChat]);

    return (
        <div ref={scrollRef} className="relative flex-1 overflow-y-auto bg-muted/20">
            <div className={isChat ? 'px-4 py-4 space-y-3' : 'w-full px-5 py-6 space-y-5'}>
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
            </div>
        </div>
    );
}
