import React, { useCallback, useEffect, useImperativeHandle, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import type { Editor } from '@tiptap/react';
import { BrainIcon, ChevronDownIcon, ClockIcon, PaperclipIcon, SendIcon, StickyNoteIcon, XIcon } from 'lucide-react';
import RichTextEditor from '@/Components/RichTextEditor';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';
import { CannedDropdown, useCannedResponsePicker } from './CannedResponses';
import { isEmptyHtml } from './html';
import type { AgentOption } from './types';

export type ReplyType = 'message' | 'note';

/** Imperative seam used by the view (shortcuts, AI draft Accept) and later action cards. */
export interface ComposerHandle {
    /** Open the composer on a tab and focus the editor. */
    focus: (type: ReplyType) => void;
    /** Replace the reply body (HTML) and open the Reply tab. */
    setReply: (html: string) => void;
    getBody: () => string;
}

const MAX_FILE_BYTES = 20 * 1024 * 1024; // StoreThreadRequest: attachments.* max:20480 (KB)

/** "YYYY-MM-DDTHH:mm" in local time, the value format of <input type="datetime-local">. */
function localInputValue(date: Date): string {
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function firstError(errors: Record<string, string>): string {
    return Object.values(errors)[0] ?? 'Could not send. Please try again.';
}

interface ComposerProps {
    ref?: React.Ref<ComposerHandle>;
    conversationId: number;
    mailboxId?: number;
    agents: AgentOption[];
    /** Page props to reload after sending (partial reload). */
    refreshProps?: string[];
    defaultExpanded: boolean;
    aiConfigured: boolean;
    aiBusy: boolean;
    onRequestAi: () => void;
    /** Rendered above the editor (the `conversation.reply.before` module slot). */
    before?: React.ReactNode;
    /**
     * Shown above Send while the agent's reply waits on a Shopify action; sending
     * a reply then asks for confirmation first (the server does not block it).
     */
    sendWarning?: string | null;
    /** Called once a reply or note has been sent (or scheduled). */
    onSent?: (type: ReplyType) => void;
}

/** Email composer: Reply/Note tabs, Tiptap editor, "/" saved replies, attachments, Send Later. */
export default function Composer({
    ref,
    conversationId,
    mailboxId,
    agents,
    refreshProps,
    defaultExpanded,
    aiConfigured,
    aiBusy,
    onRequestAi,
    before,
    sendWarning,
    onSent,
}: ComposerProps) {
    const [type, setType] = useState<ReplyType>('message');
    const [expanded, setExpanded] = useState(defaultExpanded);
    const [body, setBody] = useState('');
    const [files, setFiles] = useState<File[]>([]);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [sendLaterAt, setSendLaterAt] = useState('');
    const [sendLaterOpen, setSendLaterOpen] = useState(false);
    const [editor, setEditor] = useState<Editor | null>(null);
    const pendingFocus = useRef(false);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const bodyRef = useRef(body);
    bodyRef.current = body;

    const canned = useCannedResponsePicker(mailboxId);
    const { onEditorUpdate } = canned;

    const onEditorReady = useCallback((instance: Editor) => setEditor(instance), []);

    // The editor unmounts while collapsed; drop the stale instance.
    useEffect(() => {
        if (!expanded) setEditor(null);
    }, [expanded]);

    useEffect(() => {
        if (!editor) return;
        const handler = () => onEditorUpdate(editor);
        editor.on('update', handler);
        if (pendingFocus.current) {
            pendingFocus.current = false;
            editor.commands.focus('end');
        }
        return () => {
            editor.off('update', handler);
        };
    }, [editor, onEditorUpdate]);

    const focus = useCallback(
        (next: ReplyType) => {
            setType(next);
            setExpanded(true);
            if (editor) setTimeout(() => editor.commands.focus('end'), 0);
            else pendingFocus.current = true;
        },
        [editor],
    );

    useImperativeHandle(
        ref,
        () => ({
            focus,
            setReply: (html: string) => {
                setBody(html);
                focus('message');
            },
            getBody: () => bodyRef.current,
        }),
        [focus],
    );

    const empty = isEmptyHtml(body);

    function addFiles(list: FileList | null) {
        if (!list) return;
        const picked = Array.from(list);
        const tooBig = picked.filter((f) => f.size > MAX_FILE_BYTES);
        setError(tooBig.length ? `${tooBig.map((f) => f.name).join(', ')} is over the 20 MB limit.` : null);
        setFiles((prev) => [...prev, ...picked.filter((f) => f.size <= MAX_FILE_BYTES)]);
        if (fileInputRef.current) fileInputRef.current.value = '';
    }

    function send(scheduleAt?: string) {
        if (empty || sending) return;
        if (type === 'message' && sendWarning && !window.confirm(`${sendWarning}\n\nSend this reply anyway?`)) return;
        setSending(true);
        setError(null);

        const payload: Record<string, string | File[]> = { body, type };
        // datetime-local is local time; send an absolute instant so the server's timezone cannot shift it.
        if (type === 'message' && scheduleAt) payload.send_at = new Date(scheduleAt).toISOString();
        if (files.length) payload.attachments = files;
        // The person confirmed the warning above: the server refuses a reply while
        // the agent's draft waits on a Shopify action unless the text was edited
        // and this flag is sent (ReplySendGuard, docs/agent-actions.md).
        if (type === 'message' && sendWarning) payload.send_anyway = '1';

        router.post(`/conversations/${conversationId}/threads`, payload, {
            preserveState: true,
            preserveScroll: true,
            only: refreshProps,
            forceFormData: files.length > 0,
            onSuccess: () => {
                setBody('');
                setFiles([]);
                setSendLaterAt('');
                setSendLaterOpen(false);
                setExpanded(defaultExpanded);
                onSent?.(type);
            },
            onError: (errors) => setError(firstError(errors)),
            onFinish: () => setSending(false),
        });
    }

    function discard() {
        setExpanded(false);
        setBody('');
        setFiles([]);
        setError(null);
    }

    return (
        <div className="bg-background shrink-0 px-4 pb-4 pt-3">
            {before}
            <div
                className={cn(
                    'border rounded-xl overflow-hidden transition-colors',
                    type === 'note' ? 'border-warning/40' : 'border-border',
                )}
            >
                <div className="flex items-center gap-1 px-3 pt-2.5 pb-1">
                    {(['message', 'note'] as const).map((t) => (
                        <button
                            key={t}
                            type="button"
                            onClick={() => focus(t)}
                            className={cn(
                                'flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium transition-all',
                                type === t && expanded
                                    ? t === 'message'
                                        ? 'bg-primary/10 text-primary'
                                        : 'bg-warning/10 text-warning'
                                    : 'text-muted-foreground hover:text-foreground hover:bg-muted/50',
                            )}
                        >
                            {t === 'message' ? (
                                <>
                                    <SendIcon className="h-3 w-3" /> Reply
                                </>
                            ) : (
                                <>
                                    <StickyNoteIcon className="h-3 w-3" /> Note
                                </>
                            )}
                        </button>
                    ))}
                    <span className="ml-auto text-[10px] text-muted-foreground/50 hidden sm:block">r reply · n note · c close</span>
                    {expanded && (
                        <button
                            type="button"
                            onClick={discard}
                            title="Discard"
                            className="ml-2 text-muted-foreground hover:text-foreground transition-colors p-1 rounded"
                        >
                            <XIcon className="h-3.5 w-3.5" />
                        </button>
                    )}
                </div>

                {expanded ? (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            send(type === 'message' ? sendLaterAt || undefined : undefined);
                        }}
                    >
                        <div className="relative">
                            <CannedDropdown results={canned.results} activeIndex={canned.activeIndex} onPick={canned.pickResponse} />
                            <RichTextEditor
                                value={body}
                                onChange={setBody}
                                placeholder={
                                    type === 'message'
                                        ? 'Write your reply… (type / for saved replies)'
                                        : 'Write an internal note… (type @ to mention an agent)'
                                }
                                minHeight="140px"
                                mailboxId={mailboxId}
                                agents={agents}
                                enableMentions={type === 'note'}
                                className={cn(
                                    'border-0 border-t border-border rounded-none shadow-none',
                                    type === 'note' && 'bg-warning/5 border-warning/30',
                                )}
                                onEditorReady={onEditorReady}
                                onKeyDown={canned.onKeyDown}
                            />
                        </div>

                        {files.length > 0 && (
                            <div className="flex flex-wrap gap-1.5 px-3 py-2 border-t border-border">
                                {files.map((file, i) => (
                                    <span
                                        key={`${file.name}-${i}`}
                                        className="inline-flex items-center gap-1 rounded-md border border-border bg-muted/40 px-2 py-0.5 text-[11px]"
                                    >
                                        📎 {file.name}
                                        <button
                                            type="button"
                                            onClick={() => setFiles((prev) => prev.filter((_, j) => j !== i))}
                                            className="text-muted-foreground hover:text-foreground"
                                            aria-label={`Remove ${file.name}`}
                                        >
                                            <XIcon className="h-3 w-3" />
                                        </button>
                                    </span>
                                ))}
                            </div>
                        )}

                        {error && <p className="px-3 py-1.5 text-xs text-destructive border-t border-border">{error}</p>}
                        {sendWarning && type === 'message' && (
                            <p className="px-3 py-1.5 text-xs border-t border-warning/30 bg-warning/10 text-foreground">{sendWarning}</p>
                        )}

                        <div className="flex items-center gap-2 px-3 py-2.5 border-t border-border bg-muted/20">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="text-info hover:bg-info/10 h-8"
                                onClick={onRequestAi}
                                disabled={aiBusy || !aiConfigured}
                                title={!aiConfigured ? 'AI not configured — add an API key in Settings' : undefined}
                            >
                                <BrainIcon className={cn('h-3.5 w-3.5 mr-1.5', aiBusy && 'animate-pulse')} />
                                {aiBusy ? 'Generating…' : 'AI Suggest'}
                            </Button>
                            <input ref={fileInputRef} type="file" multiple className="hidden" onChange={(e) => addFiles(e.target.files)} />
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-8 text-muted-foreground"
                                onClick={() => fileInputRef.current?.click()}
                                title="Attach files (max 20 MB each)"
                            >
                                <PaperclipIcon className="h-3.5 w-3.5" />
                            </Button>

                            <div className="ml-auto flex items-center gap-2">
                                {/* Send Later — replies only, not notes */}
                                {type === 'message' && (
                                    <div className="relative">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="gap-1.5 text-xs"
                                            onClick={() => setSendLaterOpen((v) => !v)}
                                        >
                                            <ClockIcon className="h-3.5 w-3.5" />
                                            {sendLaterAt
                                                ? new Date(sendLaterAt).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' })
                                                : 'Send Later'}
                                            <ChevronDownIcon className="h-3 w-3 opacity-60" />
                                        </Button>
                                        {sendLaterOpen && (
                                            <div className="absolute bottom-full right-0 mb-1.5 bg-popover border border-border rounded-xl shadow-xl p-3 z-50 w-64">
                                                <p className="text-xs font-medium text-foreground mb-2">Schedule send</p>
                                                <input
                                                    type="datetime-local"
                                                    className="w-full text-sm border border-input rounded-lg px-3 py-1.5 bg-background outline-none focus:border-ring"
                                                    value={sendLaterAt}
                                                    min={localInputValue(new Date(Date.now() + 60_000))}
                                                    onChange={(e) => setSendLaterAt(e.target.value)}
                                                />
                                                <div className="flex gap-2 mt-2">
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        className="flex-1 text-xs gap-1"
                                                        disabled={!sendLaterAt || sending || empty}
                                                        onClick={() => send(sendLaterAt)}
                                                    >
                                                        <ClockIcon className="h-3 w-3" />
                                                        Schedule
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        className="text-xs"
                                                        onClick={() => {
                                                            setSendLaterAt('');
                                                            setSendLaterOpen(false);
                                                        }}
                                                    >
                                                        Clear
                                                    </Button>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                )}
                                <Button type="submit" disabled={sending || empty} className="gap-1.5 px-5">
                                    <SendIcon className="h-3.5 w-3.5" />
                                    {type === 'message' ? (sendLaterAt ? 'Schedule Reply' : 'Send Reply') : 'Add Note'}
                                </Button>
                            </div>
                        </div>
                    </form>
                ) : (
                    <button
                        type="button"
                        onClick={() => focus(type)}
                        className="w-full text-left px-4 py-3 text-sm text-muted-foreground/60 hover:text-muted-foreground transition-colors border-t border-border"
                    >
                        {type === 'message' ? 'Write your reply…' : 'Write an internal note…'}
                    </button>
                )}
            </div>
        </div>
    );
}

interface ChatComposerProps {
    ref?: React.Ref<ComposerHandle>;
    conversationId: number;
    refreshProps?: string[];
}

/** Chat-channel composer: one line, Enter sends; a toggle switches to an internal note. */
export function ChatComposer({ ref, conversationId, refreshProps }: ChatComposerProps) {
    const [message, setMessage] = useState('');
    const [type, setType] = useState<ReplyType>('message');
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const inputRef = useRef<HTMLInputElement>(null);
    const typingTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const messageRef = useRef(message);
    messageRef.current = message;

    useEffect(
        () => () => {
            if (typingTimer.current) clearTimeout(typingTimer.current);
        },
        [],
    );

    useImperativeHandle(
        ref,
        () => ({
            focus: (next: ReplyType) => {
                setType(next);
                setTimeout(() => inputRef.current?.focus(), 0);
            },
            setReply: (html: string) => {
                // DOMParser is inert (no scripts, no image loads), unlike innerHTML on a live element.
                const doc = new DOMParser().parseFromString(html.replace(/<br\s*\/?>/gi, '\n').replace(/<\/p>/gi, '\n'), 'text/html');
                setMessage((doc.body.textContent ?? '').trim());
                setType('message');
                setTimeout(() => inputRef.current?.focus(), 0);
            },
            getBody: () => messageRef.current,
        }),
        [],
    );

    // Tell the visitor's widget an agent is typing, at most once per 2s burst.
    function broadcastTyping() {
        if (type !== 'message' || typingTimer.current) return;
        window.axios.post(`/live-chat/${conversationId}/typing`).catch(() => {});
        typingTimer.current = setTimeout(() => {
            typingTimer.current = null;
        }, 2000);
    }

    function send() {
        if (!message.trim() || sending) return;
        setSending(true);
        setError(null);
        router.post(
            `/conversations/${conversationId}/threads`,
            { body: message, type },
            {
                preserveState: true,
                preserveScroll: true,
                only: refreshProps,
                onSuccess: () => setMessage(''),
                onError: (errors) => setError(firstError(errors)),
                onFinish: () => setSending(false),
            },
        );
    }

    return (
        <div className="border-t border-border bg-background px-4 py-3 shrink-0">
            {error && <p className="mb-1.5 text-xs text-destructive">{error}</p>}
            <div className="flex items-center gap-2">
                <button
                    type="button"
                    onClick={() => setType((t) => (t === 'message' ? 'note' : 'message'))}
                    title={type === 'note' ? 'Writing an internal note — click for a reply' : 'Switch to an internal note'}
                    className={cn(
                        'shrink-0 rounded-full p-2 transition-colors',
                        type === 'note' ? 'bg-warning/15 text-warning' : 'text-muted-foreground hover:bg-muted/60',
                    )}
                >
                    <StickyNoteIcon className="h-4 w-4" />
                </button>
                <input
                    ref={inputRef}
                    type="text"
                    className={cn(
                        'flex-1 text-sm border rounded-full px-4 py-2 focus:outline-none focus:ring-2 focus:ring-ring bg-background',
                        type === 'note' ? 'border-warning/40 bg-warning/5' : 'border-input',
                    )}
                    placeholder={type === 'note' ? 'Internal note…' : 'Type a message…'}
                    value={message}
                    onChange={(e) => {
                        setMessage(e.target.value);
                        broadcastTyping();
                    }}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' && !e.shiftKey) {
                            e.preventDefault();
                            send();
                        }
                    }}
                    disabled={sending}
                />
                <Button type="button" size="sm" className="h-9 px-4 rounded-full" onClick={send} disabled={sending || !message.trim()}>
                    <SendIcon className="h-4 w-4" />
                </Button>
            </div>
        </div>
    );
}
