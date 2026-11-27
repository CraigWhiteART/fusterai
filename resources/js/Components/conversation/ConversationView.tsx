import { useCallback, useEffect, useMemo, useReducer, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import ConversationInspector from '@/Components/conversations/ConversationInspector';
import SlotRenderer from '@/Components/SlotRenderer';
import { useConversationShortcuts } from '@/hooks/useConversationShortcuts';
import { isAdminRole } from '@/lib/roles';
import type { Folder, PageProps, Tag } from '@/types';
import AiDraftPanel from './AiDraftPanel';
import Composer, { ChatComposer, type ComposerHandle } from './Composer';
import CommercePanel from './CommercePanel';
import ConversationActions, { useConversationActions } from './ConversationActions';
import { QaReviewNow, useConversationQa } from './ConversationQa';
import SaveTestCase from './SaveTestCase';
import TeachFromEdit from './TeachFromEdit';
import ConversationHeader from './ConversationHeader';
import { useDraftPreview } from './DraftFeedback';
import SidePanel from './SidePanel';
import ThreadList from './ThreadList';
import { draftToHtml, isEmptyHtml } from './html';
import type { AgentOption, ConversationDetail, DetailThread, MailboxOption, SurveyData } from './types';
import { useAgentRun } from './useAgentRun';
import { useAiDraft } from './useAiDraft';
import { useConversationChannel } from './useConversationChannel';

export interface ConversationViewProps {
    detail: ConversationDetail;
    agents: AgentOption[];
    tags: Tag[];
    folders: Folder[];
    mailboxes: MailboxOption[];
    /** 'split' = right pane of the inbox, 'page' = the deep-link page. */
    layout: 'split' | 'page';
    /** Name of the page prop that holds `detail` ('selected' on Index, 'detail' on Show). */
    detailProp: string;
    /** Other page props a mutation should refresh (the inbox list and counts). */
    listProps?: string[];
    /** Called after "mark unread" to leave the conversation. */
    onLeave: () => void;
}

const INSPECTOR_KEY = 'conversation.inspector.open';

function readInspectorPref(): boolean {
    try {
        return window.localStorage.getItem(INSPECTOR_KEY) !== '0';
    } catch {
        return true;
    }
}

/**
 * The whole conversation screen: header, threads, AI draft, composer and side
 * panel. Rendered by Conversations/Index (split pane) and Conversations/Show
 * (deep link). Mount with `key={conversation.id}` so per-conversation state and
 * real-time subscriptions reset when the selection changes.
 */
export default function ConversationView({
    detail,
    agents,
    tags,
    folders,
    mailboxes,
    layout,
    detailProp,
    listProps = [],
    onLeave,
}: ConversationViewProps) {
    const { auth, aiConfigured = false } = usePage<PageProps & { aiConfigured?: boolean }>().props;
    const { conversation, isFollowing, folderIds } = detail;
    const extra = useMemo(() => (detail.extra && !Array.isArray(detail.extra) ? detail.extra : {}), [detail.extra]);
    const commerceAssist = extra.commerceAssist;
    const survey = (extra.survey as SurveyData | null | undefined) ?? null;
    const isChat = conversation.channel_type === 'chat';
    const id = conversation.id;

    // Props to refresh: the detail itself plus top-level module props that some
    // module components read through usePage() (e.g. SlaManager's `sla`).
    const detailProps = useMemo(() => [detailProp, ...Object.keys(extra)], [detailProp, extra]);
    const mutationProps = useMemo(() => [...detailProps, ...listProps], [detailProps, listProps]);

    const composerRef = useRef<ComposerHandle>(null);
    const [inspectorOpen, setInspectorOpen] = useState(readInspectorPref);
    const [mobileInspectorOpen, setMobileInspectorOpen] = useState(false);
    const [liveThreads, setLiveThreads] = useState<DetailThread[]>([]);

    useEffect(() => {
        try {
            window.localStorage.setItem(INSPECTOR_KEY, inspectorOpen ? '1' : '0');
        } catch {
            // storage unavailable: the toggle still works for this page view
        }
    }, [inspectorOpen]);

    // ── Refreshing ────────────────────────────────────────────────────────────
    // Async so a reload triggered by a broadcast never cancels a send in flight.
    const reloadTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const reloadDetail = useCallback(
        (also: string[] = []) => router.reload({ only: [...detailProps, ...also], async: true }),
        [detailProps],
    );
    const scheduleReload = useCallback(() => {
        if (reloadTimer.current) clearTimeout(reloadTimer.current);
        reloadTimer.current = setTimeout(() => reloadDetail(), 400);
    }, [reloadDetail]);
    useEffect(
        () => () => {
            if (reloadTimer.current) clearTimeout(reloadTimer.current);
        },
        [],
    );

    // ── AI draft ──────────────────────────────────────────────────────────────
    // The run explains the draft or handover; it is fetched on open only when
    // there is something to explain, then again after each draft broadcast.
    const agentRun = useAgentRun(id, !!detail.aiSuggestion || !!conversation.handover_reason);
    const ai = useAiDraft(id, detail.aiSuggestion, { resolveMode: agentRun.resolveMode });
    const preview = useDraftPreview();
    const [commerceKey, bumpCommerce] = useReducer((n: number) => n + 1, 0);
    const isAdmin = isAdminRole(auth.user?.role);

    // ── Shopify actions the agent proposed (approval cards + reply coupling) ──
    const actions = useConversationActions(id, agentRun.run?.id);
    const gate = actions.gate;
    const sendWarning =
        gate && gate.state === 'waiting'
            ? `The agent’s reply here waits for a Shopify action${gate.waitingFor.length ? ` (${gate.waitingFor.join(', ')})` : ''}. If your reply says it is done, send it only after the action card shows Done.`
            : gate && gate.state === 'needs_rewrite'
              ? `A Shopify action the agent’s draft relied on did not happen${gate.failed.length ? ` (${gate.failed.join(', ')})` : ''}. Make sure your reply does not promise it.`
              : null;

    const qa = useConversationQa(id, isAdmin);

    // "Edit instead" on a pending auto-reply (AutoSendThread) hands its text to the composer.
    useEffect(() => {
        function onLoadReply(event: Event) {
            const detail = (event as CustomEvent<{ conversationId?: number; text?: string }>).detail;
            if (!detail || detail.conversationId !== id || typeof detail.text !== 'string') return;
            composerRef.current?.setReply(draftToHtml(detail.text));
            composerRef.current?.focus('message');
        }
        window.addEventListener('fusterai:load-reply', onLoadReply);
        return () => window.removeEventListener('fusterai:load-reply', onLoadReply);
    }, [id]);

    // ── Real time ─────────────────────────────────────────────────────────────
    const { viewers, visitorTyping, setVisitorTyping } = useConversationChannel(id, isChat, {
        onThread: (thread) => {
            setLiveThreads((prev) => (prev.some((t) => t.id === thread.id) ? prev : [...prev, thread]));
            if (thread.customer_id) setVisitorTyping(false);
            // The broadcast carries a trimmed thread (no meta/origin/send state); reload for the full one.
            scheduleReload();
            // A staff reply records how the agent's draft was used (sent as is / edited / replaced):
            // re-read the run so "Teach the agent from this edit?" can appear.
            if (thread.type === 'message' && !thread.customer_id && agentRun.run) {
                setTimeout(() => void agentRun.load(), 1500);
            }
        },
        onUpdated: scheduleReload,
        onDraftDelta: ai.onDelta,
        onDraftReady: (next) => {
            preview.clear();
            ai.onReady(next);
            void agentRun.reloadFor(next.runId);
            bumpCommerce();
        },
        onDraftFailed: (reason) => {
            ai.onFailed(reason);
            // A handover writes a run too (and a note thread); show why.
            void agentRun.load();
            bumpCommerce();
        },
        onActionUpdated: () => {
            void actions.reload();
        },
    });
    const otherViewers = viewers.filter((v) => v.id !== auth.user?.id);

    const threads = useMemo(() => {
        const server = conversation.threads ?? [];
        const known = new Set(server.map((t) => t.id));
        const all = [...server, ...liveThreads.filter((t) => !known.has(t.id))];
        return all.filter((t) => (isChat ? t.type === 'message' || t.type === 'note' : t.type !== 'ai_suggestion'));
    }, [conversation.threads, liveThreads, isChat]);

    // ── Mutations ─────────────────────────────────────────────────────────────
    const visit = { preserveState: true, preserveScroll: true, only: mutationProps } as const;
    const base = `/conversations/${id}`;

    function updateStatus(status: string) {
        router.patch(`${base}/status`, { status }, visit);
    }
    function updatePriority(priority: string) {
        router.patch(`${base}/priority`, { priority }, visit);
    }
    function assignTo(userId: string) {
        router.patch(`${base}/assign`, { user_id: userId || null }, visit);
    }
    function changeMailbox(mailboxId: number) {
        if (mailboxId === conversation.mailbox_id) return;
        router.patch(`${base}/mailbox`, { mailbox_id: mailboxId }, visit);
    }
    function addTag(tagId: number) {
        const current = conversation.tags?.map((tag) => tag.id) ?? [];
        if (current.includes(tagId)) return;
        router.post(`${base}/tags`, { tag_ids: [...current, tagId] }, visit);
    }
    function syncFolders(ids: number[]) {
        router.post(`${base}/folders`, { folder_ids: ids }, visit);
    }
    function toggleFollow() {
        if (isFollowing) router.delete(`${base}/follow`, visit);
        else router.post(`${base}/follow`, {}, visit);
    }
    function snooze(minutes: number) {
        const until = new Date(Date.now() + minutes * 60 * 1000).toISOString();
        router.patch(`${base}/snooze`, { until }, visit);
    }
    // These endpoints answer JSON, not an Inertia page, so call them with axios.
    function toggleStar() {
        window.axios.post(`${base}/star`).then(() => reloadDetail(listProps));
    }
    function cancelSchedule(threadId: number) {
        window.axios.delete(`${base}/threads/${threadId}/schedule`).then(() => reloadDetail());
    }
    function markUnread() {
        window.axios.post(`${base}/unread`).then(onLeave);
    }

    function acceptDraft(override?: string) {
        const draft = ai.draft;
        if (!draft) return;
        // What is shown is what goes in: a preview (DraftFeedback) replaces the draft text.
        const text = override ?? preview.text ?? draft.content;
        const current = composerRef.current?.getBody() ?? '';
        if (!isEmptyHtml(current) && !window.confirm('Replace what you have written with the AI draft?')) return;
        ai.accept();
        preview.clear();
        composerRef.current?.setReply(draftToHtml(text));
    }

    function dismissDraft() {
        preview.clear();
        ai.dismiss();
    }

    useConversationShortcuts({
        conversationId: id,
        status: conversation.status,
        onFocusReply: () => composerRef.current?.focus('message'),
        onFocusNote: () => composerRef.current?.focus('note'),
        onClose: () => updateStatus('closed'),
    });

    return (
        <div className={layout === 'split' ? 'flex flex-1 min-h-0 overflow-hidden' : 'flex h-full overflow-hidden'}>
            <div className="reading-light bg-background text-foreground flex-1 flex flex-col min-w-0 min-h-0 overflow-hidden">
                <ConversationHeader
                    conversation={conversation}
                    isChat={isChat}
                    viewers={otherViewers}
                    inspectorOpen={inspectorOpen}
                    showOpenLink={layout === 'split'}
                    onStatus={updateStatus}
                    onSnooze={snooze}
                    onMarkUnread={markUnread}
                    onToggleInspector={() => setInspectorOpen((v) => !v)}
                    onOpenMobileInspector={() => setMobileInspectorOpen(true)}
                />

                <ThreadList
                    conversation={conversation}
                    threads={threads}
                    isChat={isChat}
                    commerceAssist={commerceAssist}
                    visitorTyping={visitorTyping}
                    onCancelSchedule={cancelSchedule}
                    threadBadges={isAdmin ? qa.badges : undefined}
                    afterLabel={ai.generating ? 'Drafting reply…' : ai.draft ? 'AI draft ready' : null}
                    after={
                        <>
                            <ConversationActions
                                executions={actions.executions}
                                latestRunId={actions.latestRunId}
                                onChanged={() => void actions.reload()}
                            />
                            {!ai.draft && !ai.generating && (
                                <TeachFromEdit run={agentRun.run} threads={threads} conversationId={id} isAdmin={isAdmin} />
                            )}
                            <AiDraftPanel
                                draft={ai.draft}
                                streamingText={ai.streamingText}
                                generating={ai.generating}
                                error={ai.error}
                                handoverReason={conversation.handover_reason}
                                aiConfigured={aiConfigured}
                                onAccept={() => acceptDraft()}
                                onAcceptText={(text) => acceptDraft(text)}
                                conversationId={id}
                                onRetry={ai.request}
                                onDismiss={dismissDraft}
                                onClearError={ai.clearError}
                                run={agentRun.run}
                                isAdmin={isAdmin}
                                preview={preview}
                                actionGate={gate}
                            >
                                <SlotRenderer name="conversation.draft.after" props={{ conversation, draft: ai.draft }} />
                            </AiDraftPanel>
                        </>
                    }
                />

                {/* No overflow here: the saved-reply and Send Later popovers open upwards out of it. */}
                <div className="shrink-0 bg-background border-t border-border/60">
                    {isChat ? (
                        <ChatComposer ref={composerRef} conversationId={id} refreshProps={mutationProps} />
                    ) : (
                        <Composer
                            ref={composerRef}
                            conversationId={id}
                            mailboxId={conversation.mailbox_id ?? undefined}
                            agents={agents}
                            refreshProps={mutationProps}
                            defaultExpanded={layout === 'page'}
                            aiConfigured={aiConfigured}
                            aiBusy={ai.generating}
                            onRequestAi={ai.request}
                            sendWarning={sendWarning}
                            before={<SlotRenderer name="conversation.reply.before" props={{ conversation, commerceAssist }} />}
                        />
                    )}
                </div>
            </div>

            <SidePanel
                open={inspectorOpen}
                mobileOpen={mobileInspectorOpen}
                onCloseMobile={() => setMobileInspectorOpen(false)}
                aiConfigured={aiConfigured}
                aiSummary={conversation.ai_summary}
            >
                <ConversationInspector
                    conversation={conversation}
                    agents={agents}
                    tags={tags}
                    folders={folders}
                    mailboxes={mailboxes}
                    selectedFolderIds={folderIds}
                    isFollowing={isFollowing}
                    survey={survey}
                    onStatusChange={updateStatus}
                    onPriorityChange={updatePriority}
                    onAssignChange={assignTo}
                    onMailboxChange={changeMailbox}
                    onAddTag={addTag}
                    onAddFolder={(folderId) => syncFolders([...folderIds, folderId])}
                    onRemoveFolder={(folderId) => syncFolders(folderIds.filter((f) => f !== folderId))}
                    onToggleFollow={toggleFollow}
                    onToggleStar={toggleStar}
                />
                <CommercePanel conversationId={id} refreshKey={commerceKey} />
                {isAdmin && !isChat && (
                    <SaveTestCase conversationId={id} subject={conversation.subject} threads={threads} run={agentRun.run} />
                )}
                {isAdmin && !isChat && <QaReviewNow qa={qa} />}
                <SlotRenderer
                    name="conversation.sidebar.bottom"
                    props={{ conversation, survey, conversationStatus: conversation.status, commerceAssist }}
                />
            </SidePanel>
        </div>
    );
}
