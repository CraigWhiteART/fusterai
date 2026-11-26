import { useMemo, useRef, useState, type ReactNode } from 'react';
import { ClockIcon, MoreHorizontalIcon } from 'lucide-react';
import SlotRenderer from '@/Components/SlotRenderer';
import { Avatar, AvatarFallback, AvatarImage } from '@/Components/ui/avatar';
import { cn, getInitials, sanitizeHtml } from '@/lib/utils';
import { relativeTime } from './format';
import { splitQuotedHtml } from './html';
import { AiSentBadge, AutoSendPending, AutoSendWrong, isPendingAutoSend } from './AutoSendThread';
import type { DetailConversation, DetailThread } from './types';
import { useImageFallback } from './useImageFallback';

function authorOf(thread: DetailThread) {
    const isFromCustomer = !!thread.customer_id;
    const author = isFromCustomer ? thread.customer : thread.user;
    // Replies written in Gmail by an address that is not a user have no user: show the sender name.
    const name = author?.name ?? (typeof thread.meta?.from_name === 'string' ? thread.meta.from_name : null) ?? 'Unknown';
    return { isFromCustomer, author, name };
}

function Attachments({ thread, compact }: { thread: DetailThread; compact?: boolean }) {
    const downloads = (thread.attachments ?? []).filter((a) => !a.inline);
    if (downloads.length === 0) return null;
    return (
        <div className={cn('flex flex-wrap gap-1.5', compact ? 'mt-1.5' : 'px-4 pb-3 pt-0.5')}>
            {downloads.map((a) => (
                <a
                    key={a.id}
                    href={a.url}
                    target="_blank"
                    rel="noreferrer"
                    className="text-[11px] text-primary hover:text-primary/80 flex items-center gap-1 bg-primary/6 hover:bg-primary/10 px-2 py-1 rounded-md border border-primary/10 transition-colors"
                >
                    📎 {a.filename}
                </a>
            ))}
        </div>
    );
}

function ActivityItem({ thread }: { thread: DetailThread }) {
    return (
        <div className="flex items-center gap-3 py-1.5">
            <div className="flex-1 h-px bg-border/50" />
            <span
                className="text-[11px] text-muted-foreground/60 shrink-0 px-1"
                dangerouslySetInnerHTML={{ __html: sanitizeHtml(thread.body) }}
            />
            <div className="flex-1 h-px bg-border/50" />
        </div>
    );
}

/** Email body with quoted earlier messages collapsed behind a toggle. */
function EmailBody({ html }: { html: string }) {
    const [showQuoted, setShowQuoted] = useState(false);
    const { visible, quoted } = useMemo(() => {
        const split = splitQuotedHtml(html);
        return { visible: sanitizeHtml(split.visible), quoted: split.quoted === null ? null : sanitizeHtml(split.quoted) };
    }, [html]);

    return (
        <div className="px-5 pb-4">
            <div className="prose prose-sm max-w-none leading-relaxed text-foreground/80" dangerouslySetInnerHTML={{ __html: visible }} />
            {quoted !== null && (
                <>
                    <button
                        type="button"
                        onClick={() => setShowQuoted((v) => !v)}
                        title={showQuoted ? 'Hide quoted text' : 'Show quoted text'}
                        aria-expanded={showQuoted}
                        className="mt-2 inline-flex items-center gap-1 rounded-md border border-border bg-muted/50 px-1.5 py-0.5 text-[11px] text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
                    >
                        <MoreHorizontalIcon className="h-3 w-3" />
                        {showQuoted ? 'Hide quoted text' : 'Show quoted text'}
                    </button>
                    {showQuoted && (
                        <div
                            className="prose prose-sm max-w-none mt-2 border-l-2 border-border pl-3 leading-relaxed text-muted-foreground"
                            dangerouslySetInnerHTML={{ __html: quoted }}
                        />
                    )}
                </>
            )}
        </div>
    );
}

interface ThreadItemProps {
    thread: DetailThread;
    conversation: DetailConversation;
    commerceAssist?: unknown;
    onCancelSchedule?: (threadId: number) => void;
    /** Extra header badge (e.g. the QA badge on a reviewed reply, admins only). */
    badge?: ReactNode;
}

/** One email-style thread: header, scheduled banner, body, attachments, module actions. */
export function EmailThreadItem({ thread, conversation, commerceAssist, onCancelSchedule, badge }: ThreadItemProps) {
    const isNote = thread.type === 'note';
    // AI auto-replies have their own pending banner (countdown, Cancel, Edit instead).
    const isAutoPending = isPendingAutoSend(thread);
    const isScheduled = !isAutoPending && !!thread.send_at && new Date(thread.send_at) > new Date();
    const { isFromCustomer, author, name } = authorOf(thread);
    const rootRef = useRef<HTMLDivElement>(null);
    useImageFallback(rootRef);

    if (thread.type === 'activity') return <ActivityItem thread={thread} />;

    return (
        <div
            ref={rootRef}
            className={cn(
                'rounded-2xl text-sm overflow-hidden',
                isNote
                    ? 'border border-amber-200/60 bg-amber-50/60 dark:border-amber-800/30 dark:bg-amber-950/20'
                    : isFromCustomer
                      ? 'border border-border/70 bg-card shadow-sm'
                      : 'border-l-[3px] border border-primary/20 border-l-primary/50 bg-primary/[0.025] shadow-sm',
            )}
        >
            <div className="flex items-center justify-between px-5 pt-4 pb-3">
                <div className="flex items-center gap-3 min-w-0">
                    <Avatar className="size-8 shrink-0">
                        <AvatarImage src={author?.avatar ?? undefined} alt={name} />
                        <AvatarFallback
                            className={cn(
                                'text-xs font-bold',
                                isFromCustomer ? 'bg-muted text-foreground/70' : 'bg-primary/15 text-primary',
                            )}
                        >
                            {getInitials(name)}
                        </AvatarFallback>
                    </Avatar>
                    <div className="min-w-0">
                        <span className="font-semibold text-[13px] text-foreground">{name}</span>
                        {isFromCustomer && thread.customer?.email && (
                            <span className="text-[11px] text-muted-foreground/70 ml-2">{thread.customer.email}</span>
                        )}
                    </div>
                </div>
                <div className="flex items-center gap-2 shrink-0 ml-2">
                    {isNote && (
                        <span className="text-[10px] bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400 px-1.5 py-0.5 rounded-full font-semibold tracking-wide uppercase">
                            Note
                        </span>
                    )}
                    {badge}
                    {thread.origin === 'ai' && <AiSentBadge thread={thread} />}
                    {!isFromCustomer && !isNote && thread.origin !== 'ai' && (
                        <span className="text-[10px] bg-primary/8 text-primary px-1.5 py-0.5 rounded-full font-semibold tracking-wide uppercase">
                            {thread.origin === 'gmail' ? 'Reply via Gmail' : 'Reply'}
                        </span>
                    )}
                    {!isFromCustomer && !isNote && thread.opened_at && (
                        <span className="text-[10px] bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400 px-1.5 py-0.5 rounded-full font-semibold tracking-wide uppercase">
                            Opened
                        </span>
                    )}
                    <span className="text-[11px] text-muted-foreground/55 tabular-nums">
                        {new Date(thread.created_at).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' })}
                    </span>
                </div>
            </div>

            {isAutoPending && <AutoSendPending thread={thread} conversationId={conversation.id} />}

            {isScheduled && (
                <div className="mx-4 mb-3 px-3 py-2 rounded-lg bg-amber-50 border border-amber-200/60 dark:bg-amber-950/30 dark:border-amber-800/40 flex items-center justify-between gap-2">
                    <div className="flex items-center gap-1.5 text-amber-700 dark:text-amber-400 text-xs font-medium">
                        <ClockIcon className="h-3.5 w-3.5 shrink-0" />
                        Scheduled for {new Date(thread.send_at!).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' })}
                    </div>
                    {onCancelSchedule && (
                        <button
                            type="button"
                            onClick={() => onCancelSchedule(thread.id)}
                            className="text-xs text-amber-600 hover:text-amber-800 dark:text-amber-500 dark:hover:text-amber-300 underline shrink-0 transition-colors"
                        >
                            Cancel
                        </button>
                    )}
                </div>
            )}

            {isNote ? (
                <div
                    className="px-5 pb-4 prose prose-sm max-w-none leading-relaxed text-foreground/80"
                    dangerouslySetInnerHTML={{ __html: sanitizeHtml(thread.body) }}
                />
            ) : (
                <EmailBody html={thread.body} />
            )}

            <Attachments thread={thread} />

            {thread.origin === 'ai' && <AutoSendWrong thread={thread} />}

            <div className="px-4 pb-2 empty:hidden">
                <SlotRenderer name="conversation.thread.actions" props={{ thread, conversation, commerceAssist }} />
            </div>
        </div>
    );
}

/** Chat-channel message bubble. */
export function ChatThreadItem({ thread, conversation, commerceAssist }: ThreadItemProps) {
    const isNote = thread.type === 'note';
    const { isFromCustomer, name } = authorOf(thread);
    const rootRef = useRef<HTMLDivElement>(null);
    useImageFallback(rootRef);

    return (
        <div ref={rootRef} className={cn('flex flex-col', isFromCustomer ? 'items-start' : 'items-end')}>
            <span className="text-xs text-muted-foreground mb-1 px-1">
                {name} · {relativeTime(thread.created_at)}
                {isNote && ' · Note'}
            </span>
            <div
                className={cn(
                    'max-w-[70%] px-4 py-2 text-sm leading-relaxed prose prose-sm',
                    isNote
                        ? 'bg-warning/10 border border-warning/30 rounded-2xl text-foreground'
                        : isFromCustomer
                          ? 'bg-muted rounded-2xl rounded-tl-sm text-foreground'
                          : 'bg-primary text-primary-foreground rounded-2xl rounded-tr-sm prose-invert',
                )}
                dangerouslySetInnerHTML={{ __html: sanitizeHtml(thread.body) }}
            />
            <Attachments thread={thread} compact />
            <SlotRenderer name="conversation.thread.actions" props={{ thread, conversation, commerceAssist }} />
        </div>
    );
}
