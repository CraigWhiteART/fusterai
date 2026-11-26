import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import {
    AlertTriangleIcon,
    BanIcon,
    BotIcon,
    CheckIcon,
    InfoIcon,
    LightbulbIcon,
    LockIcon,
    MessagesSquareIcon,
    PlusIcon,
    RotateCcwIcon,
    SendIcon,
    Trash2Icon,
    XIcon,
} from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Textarea } from '@/Components/ui/textarea';
import { DiffEntries, Disclosure, ErrorLine, ProgressBar, ProposalStatusBadge, Spinner } from '@/Components/agent/LoopParts';
import {
    AGENT_SCREEN_PATHS,
    TARGET_TYPE_LABELS,
    agentScreenUrl,
    asArray,
    asRecord,
    conversationUrl,
    loopApi,
    plural,
    proposalUrl,
    queryParam,
    setQueryParam,
    shortDate,
    toApiError,
    usd,
    usePolling,
    type ApiError,
} from '@/lib/agentLoop';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import type { DiffEntry, Proposal, ProposalChange } from '@/types/agentLoop';
import { AgentPage, EmptyState, FieldError, Notice } from '../AgentParts';

// Manager chat ("Ask the manager"), docs/agent-manager.md. Everything is
// loaded over JSON; the page takes no props so it can be rendered by its own
// route or hosted by the Overview under `?view=manager`.

// ── API shapes (docs/agent-manager.md "HTTP API") ────────────────────────────

type AreaTarget = 'voice' | 'playbook' | 'knowledge' | 'example';

interface PlaybookRef {
    id: number;
    slug: string | null;
}

interface RestrictionSource {
    from?: string | null;
    kind?: string | null;
    text?: string | null;
    matched?: boolean;
    playbook_ids?: number[];
    targets?: string[];
    at?: string | null;
}

interface RestrictionsPayload {
    exclude_playbook_ids?: number[];
    exclude_targets?: string[];
    only_playbook_ids?: number[] | null;
    only_targets?: string[] | null;
    sources?: RestrictionSource[];
    exclude_playbooks?: PlaybookRef[];
    only_playbooks?: PlaybookRef[] | null;
}

interface Restrictions {
    exclude_playbook_ids: number[];
    exclude_targets: AreaTarget[];
    only_playbook_ids: number[] | null;
    only_targets: AreaTarget[] | null;
}

interface ManagerChat {
    id: number;
    title: string | null;
    restrictions: RestrictionsPayload | unknown[] | null;
    message_count: number;
    running: boolean;
    tokens?: { in?: number; out?: number } | null;
    cost_usd: number | null;
    last_message_at: string | null;
    created_at: string | null;
    updated_at: string | null;
}

interface ManagerLimits {
    daily_cost_cap_usd: number | null;
    spent_today_usd: number | null;
    cap_reached: boolean;
    max_steps?: number;
    max_input_tokens?: number;
    max_proposals_per_turn?: number;
}

interface ToolCall {
    step: number;
    tool: string;
    summary?: string | null;
    ok?: boolean;
    refused?: boolean;
    arguments?: unknown;
}

interface Citation {
    type: string;
    id: number;
    label?: string | null;
    source?: string | null;
    /** Run citations: the conversation the run answered (links the chip). */
    conversation_id?: number | null;
}

interface ManagerSuggestion {
    type?: 'forbidden' | 'scope' | string;
    target_type?: string | null;
    target_id?: number | null;
    op?: Record<string, unknown> | string | unknown[] | null;
    reason?: string | null;
    proposal_id?: number | null;
}

interface TurnLimits {
    tool_calls?: number;
    step_cap?: number;
    step_cap_reached?: boolean;
    estimated_input_tokens?: number;
    token_budget?: number;
    token_budget_reached?: boolean;
    deadline_reached?: boolean;
    unknown_citations?: string[];
    prompt_version?: string;
}

type MessageStatus = 'pending' | 'running' | 'completed' | 'failed' | 'capped';

interface ManagerMessage {
    id: number;
    chat_id: number;
    role: 'user' | 'assistant';
    status: MessageStatus | string;
    content: string | null;
    reply_to_id: number | null;
    tool_calls?: ToolCall[] | null;
    citations?: Citation[] | null;
    proposal_ids?: number[] | null;
    suggestions?: ManagerSuggestion[] | null;
    restrictions?: RestrictionsPayload | unknown[] | null;
    limits?: TurnLimits | unknown[] | null;
    error?: string | null;
    model?: string | null;
    cost_usd?: number | null;
    latency_ms?: number | null;
    created_at: string | null;
    completed_at?: string | null;
}

interface ChatDetail {
    chat: ManagerChat;
    messages: ManagerMessage[];
}

interface StepEvent {
    chat_id: number;
    message_id: number;
    step: number;
    tool: string;
    summary?: string | null;
    ok?: boolean;
    refused?: boolean;
}

interface ChatEvent {
    chat_id: number;
    message_id: number;
    status?: string;
    proposal_ids?: number[];
}

interface PlaybookOption {
    id: number;
    label: string;
    slug: string | null;
    archived: boolean;
}

const OPEN_STATUSES = ['pending', 'running'];
const MIN_CHARS = 2;
const MAX_CHARS = 8000;
const POLL_MS = 4000;

// ── API ──────────────────────────────────────────────────────────────────────

const managerApi = {
    chats: () =>
        window.axios
            .get<{ data: ManagerChat[]; limits?: ManagerLimits }>('/agent/manager/chats', { params: { per_page: 100 } })
            .then((r) => r.data),
    chat: (id: number) =>
        window.axios
            .get<{ chat: ManagerChat; messages: ManagerMessage[]; limits?: ManagerLimits }>(`/agent/manager/chats/${id}`)
            .then((r) => r.data),
    create: () => window.axios.post<{ chat: ManagerChat }>('/agent/manager/chats', {}).then((r) => r.data.chat),
    update: (id: number, restrictions: Restrictions) =>
        window.axios.patch<{ chat: ManagerChat }>(`/agent/manager/chats/${id}`, { restrictions }).then((r) => r.data.chat),
    remove: (id: number) => window.axios.delete(`/agent/manager/chats/${id}`),
    send: (id: number, content: string) =>
        window.axios
            .post<{ message: ManagerMessage; reply: ManagerMessage; chat: ManagerChat }>(`/agent/manager/chats/${id}/messages`, { content })
            .then((r) => r.data),
};

/** GET /agent/playbooks/options (archived included, flagged). Falls back to [] on any problem. */
async function fetchPlaybookOptions(): Promise<PlaybookOption[]> {
    try {
        const { data } = await window.axios.get<{ data: unknown }>('/agent/playbooks/options', { params: { include_archived: 1 } });
        return asArray<Record<string, unknown>>(data?.data)
            .filter((p) => typeof p?.id === 'number')
            .map((p) => ({
                id: p.id as number,
                slug: typeof p.slug === 'string' ? p.slug : null,
                label: (typeof p.name === 'string' && p.name) || (typeof p.slug === 'string' && p.slug) || `Playbook #${p.id}`,
                archived: !!p.archived,
            }));
    } catch {
        return [];
    }
}

// ── Small helpers ────────────────────────────────────────────────────────────

function rec<T extends object>(value: unknown): Partial<T> {
    return asRecord<unknown>(value as Record<string, unknown> | null) as Partial<T>;
}

function validId(value: unknown): number | null {
    const n = Number(value);
    return Number.isInteger(n) && n > 0 ? n : null;
}

function ids(value: unknown): number[] {
    return asArray<unknown>(value)
        .map((v) => Number(v))
        .filter((n) => Number.isInteger(n) && n > 0);
}

const AREAS: AreaTarget[] = ['voice', 'playbook', 'knowledge', 'example'];

function areas(value: unknown): AreaTarget[] {
    return asArray<unknown>(value).filter((v): v is AreaTarget => AREAS.includes(v as AreaTarget));
}

function readRestrictions(value: unknown): Restrictions {
    const r = rec<RestrictionsPayload>(value);
    return {
        exclude_playbook_ids: ids(r.exclude_playbook_ids),
        exclude_targets: areas(r.exclude_targets),
        only_playbook_ids: Array.isArray(r.only_playbook_ids) ? ids(r.only_playbook_ids) : null,
        only_targets: Array.isArray(r.only_targets) ? areas(r.only_targets) : null,
    };
}

function placeCaret(el: HTMLTextAreaElement, [start, end]: [number, number]): void {
    const max = el.value.length;
    el.focus();
    el.setSelectionRange(Math.min(start, max), Math.min(end, max));
}

function humanize(key: string): string {
    const s = key.replace(/[_.]/g, ' ').trim();
    return s ? s[0].toUpperCase() + s.slice(1) : key;
}

function upsertChat(list: ManagerChat[] | null, chat: ManagerChat | null | undefined): ManagerChat[] | null {
    if (!chat || typeof chat.id !== 'number') return list;
    const current = list ?? [];
    if (current.some((c) => c.id === chat.id)) return current.map((c) => (c.id === chat.id ? chat : c));
    return [chat, ...current];
}

function isOpen(m: ManagerMessage): boolean {
    return m.role === 'assistant' && OPEN_STATUSES.includes(m.status);
}

const AREA_LABELS: Record<AreaTarget, string> = {
    voice: 'Brand voice (tone and wording)',
    playbook: 'All playbooks',
    knowledge: 'Knowledge (facts and policies)',
    example: 'Example replies',
};

type Selection = [number, number];

/** Starter prompts only fill the composer; the "…" part is selected so typing replaces it. */
const STARTERS: { label: string; text: string; select?: Selection }[] = [
    {
        label: 'We changed a policy — update what needs updating',
        text: 'We changed our policy: … Update whatever needs updating.',
        select: ['We changed our policy: '.length, 'We changed our policy: …'.length],
    },
    { label: 'Where did the agent sound robotic last week?', text: 'Where did the agent sound robotic last week?' },
    {
        label: 'Why did conversation #… get handed over?',
        text: 'Why did conversation #… get handed over?',
        select: ['Why did conversation #'.length, 'Why did conversation #…'.length],
    },
    {
        label: "Make the tone a bit less formal, but don't touch refund rules",
        text: "Make the tone a bit less formal, but don't touch refund rules.",
    },
];

// ── Page ─────────────────────────────────────────────────────────────────────

export default function ManagerIndex() {
    const page = usePage<PageProps>();
    const userId = page.props.auth?.user?.id ?? null;

    // Deep link: ?new=1&prompt=… starts a fresh chat with the composer filled
    // in (never sent automatically); ?chat=<id> opens a chat.
    const [initial] = useState(() => {
        const isNew = queryParam('new') === '1';
        return { isNew, prompt: (queryParam('prompt') ?? '').slice(0, MAX_CHARS), chat: isNew ? null : validId(queryParam('chat')) };
    });

    const [chats, setChats] = useState<ManagerChat[] | null>(null);
    const [limits, setLimits] = useState<ManagerLimits | null>(null);
    const [listError, setListError] = useState<ApiError | null>(null);
    const [selectedId, setSelectedId] = useState<number | null>(initial.chat);
    const [detail, setDetail] = useState<ChatDetail | null>(null);
    const [detailError, setDetailError] = useState<ApiError | null>(null);
    const [liveSteps, setLiveSteps] = useState<Record<number, StepEvent[]>>({});
    const [draft, setDraft] = useState(initial.prompt);
    const [sending, setSending] = useState(false);
    const [sendError, setSendError] = useState<ApiError | null>(null);
    const [waitNotice, setWaitNotice] = useState(false);
    const [playbooks, setPlaybooks] = useState<PlaybookOption[]>([]);
    const [scopeBusy, setScopeBusy] = useState(false);
    const [scopeError, setScopeError] = useState<ApiError | null>(null);
    const [deleting, setDeleting] = useState(false);

    const selectedRef = useRef<number | null>(initial.chat);
    // Bumped whenever the thread is replaced locally so an older GET in flight cannot overwrite it.
    const detailSeq = useRef(0);
    const composerRef = useRef<HTMLTextAreaElement | null>(null);
    const pendingCaret = useRef<Selection | null>(initial.prompt ? [initial.prompt.length, initial.prompt.length] : null);

    const loadChats = useCallback(async () => {
        try {
            const r = await managerApi.chats();
            setChats(asArray<ManagerChat>(r?.data));
            if (r?.limits) setLimits(r.limits);
            setListError(null);
        } catch (e) {
            setListError(toApiError(e));
        }
    }, []);

    const loadDetail = useCallback(async (id: number) => {
        const seq = ++detailSeq.current;
        try {
            const r = await managerApi.chat(id);
            if (seq !== detailSeq.current || selectedRef.current !== id || !r?.chat) return;
            setDetail({ chat: r.chat, messages: asArray<ManagerMessage>(r.messages) });
            if (r.limits) setLimits(r.limits);
            setChats((prev) => upsertChat(prev, r.chat));
            setDetailError(null);
        } catch (e) {
            if (seq !== detailSeq.current || selectedRef.current !== id) return;
            setDetailError(toApiError(e));
        }
    }, []);

    useEffect(() => {
        if (initial.isNew || initial.prompt) {
            setQueryParam('new', null);
            setQueryParam('prompt', null);
        }
        if (initial.isNew) setQueryParam('chat', null);
        void loadChats();
        if (initial.chat) void loadDetail(initial.chat);
    }, [initial, loadChats, loadDetail]);

    useEffect(() => {
        let alive = true;
        void fetchPlaybookOptions().then((list) => {
            if (alive) setPlaybooks(list);
        });
        return () => {
            alive = false;
        };
    }, []);

    // Put the cursor where a starter prompt / deep link wants it.
    useEffect(() => {
        const el = composerRef.current;
        if (pendingCaret.current === null || !el) return;
        placeCaret(el, pendingCaret.current);
        pendingCaret.current = null;
    }, [draft]);

    const selectChat = useCallback(
        (id: number | null) => {
            detailSeq.current++;
            selectedRef.current = id;
            setSelectedId(id);
            setQueryParam('chat', id);
            setDetail(null);
            setDetailError(null);
            setSendError(null);
            setWaitNotice(false);
            setScopeError(null);
            if (id !== null) void loadDetail(id);
        },
        [loadDetail],
    );

    // ── Realtime progress on the user's private channel ──────────────────────

    const handlers = useRef({
        started: (_e: ChatEvent) => {},
        step: (_e: StepEvent) => {},
        finished: (_e: ChatEvent) => {},
    });
    handlers.current = {
        started: (e) => {
            if (e.chat_id === selectedRef.current) void loadDetail(e.chat_id);
            else setChats((prev) => prev?.map((c) => (c.id === e.chat_id ? { ...c, running: true } : c)) ?? prev);
        },
        step: (e) => {
            if (typeof e.message_id !== 'number' || typeof e.step !== 'number') return;
            setLiveSteps((prev) => {
                const list = (prev[e.message_id] ?? []).filter((s) => s.step !== e.step);
                return { ...prev, [e.message_id]: [...list, e].sort((a, b) => a.step - b.step) };
            });
        },
        finished: (e) => {
            if (e.chat_id === selectedRef.current) void loadDetail(e.chat_id);
            void loadChats();
        },
    };

    useEffect(() => {
        if (!window.Echo || !userId) return;
        let channel: ReturnType<typeof window.Echo.private> | null = null;
        try {
            channel = window.Echo.private(`App.Models.User.${userId}`);
        } catch {
            return;
        }
        const onStarted = (e: unknown) => {
            if (e && typeof e === 'object') handlers.current.started(e as ChatEvent);
        };
        const onStep = (e: unknown) => {
            if (e && typeof e === 'object') handlers.current.step(e as StepEvent);
        };
        const onFinished = (e: unknown) => {
            if (e && typeof e === 'object') handlers.current.finished(e as ChatEvent);
        };
        channel.listen('.agent.manager.started', onStarted);
        channel.listen('.agent.manager.step', onStep);
        channel.listen('.agent.manager.finished', onFinished);
        // stopListening with the exact callbacks (never `leave`): other code shares this channel.
        return () => {
            channel?.stopListening('.agent.manager.started', onStarted);
            channel?.stopListening('.agent.manager.step', onStep);
            channel?.stopListening('.agent.manager.finished', onFinished);
        };
    }, [userId]);

    const messages = detail?.messages ?? [];
    const running = !!detail && (detail.chat.running || messages.some(isOpen));
    usePolling(
        () => {
            if (selectedRef.current !== null) void loadDetail(selectedRef.current);
        },
        POLL_MS,
        running && selectedId !== null,
    );

    // ── Actions ──────────────────────────────────────────────────────────────

    const capReached = !!limits?.cap_reached;
    const trimmed = draft.trim();
    const canSend = !sending && !running && !capReached && trimmed.length >= MIN_CHARS && trimmed.length <= MAX_CHARS;

    async function send() {
        if (!canSend) return;
        setSending(true);
        setSendError(null);
        setWaitNotice(false);
        let chatId = selectedId;
        try {
            if (chatId === null) {
                const created = await managerApi.create();
                chatId = created.id;
                detailSeq.current++;
                selectedRef.current = chatId;
                setSelectedId(chatId);
                setQueryParam('chat', chatId);
                setDetail({ chat: created, messages: [] });
                setChats((prev) => upsertChat(prev, created));
            }
            const r = await managerApi.send(chatId, trimmed);
            detailSeq.current++;
            const id = chatId;
            if (selectedRef.current === id) {
                setDetail((prev) => {
                    const chat = r.chat ?? prev?.chat;
                    if (!chat) return prev;
                    const earlier = prev && prev.chat.id === id ? prev.messages : [];
                    return { chat, messages: [...earlier, r.message, r.reply].filter((m): m is ManagerMessage => !!m) };
                });
            }
            setChats((prev) => upsertChat(prev, r.chat));
            setDraft('');
        } catch (e) {
            const err = toApiError(e);
            const body = rec<{ limits: ManagerLimits; reply: ManagerMessage }>(err.body);
            if (err.status === 409 && err.reason === 'turn_running') {
                setWaitNotice(true);
                if (chatId !== null) void loadDetail(chatId);
            } else if (err.status === 409 && err.reason === 'daily_cap') {
                setLimits((prev) => ({
                    daily_cost_cap_usd: null,
                    spent_today_usd: null,
                    ...(prev ?? {}),
                    ...(body.limits && typeof body.limits === 'object' ? body.limits : {}),
                    cap_reached: true,
                }));
            } else {
                setSendError(err);
            }
        } finally {
            setSending(false);
        }
    }

    async function deleteChat() {
        if (!detail) return;
        const id = detail.chat.id;
        if (
            !window.confirm(
                'Delete this chat? Its messages are removed for good. Proposals it drafted stay in the Proposals list until you review them.',
            )
        )
            return;
        setDeleting(true);
        try {
            await managerApi.remove(id);
            setChats((prev) => prev?.filter((c) => c.id !== id) ?? prev);
            selectChat(null);
        } catch (e) {
            setDetailError(toApiError(e));
        } finally {
            setDeleting(false);
        }
    }

    async function saveRestrictions(next: Restrictions) {
        if (!detail) return;
        const id = detail.chat.id;
        setScopeBusy(true);
        setScopeError(null);
        try {
            const chat = await managerApi.update(id, next);
            detailSeq.current++;
            if (selectedRef.current === id) setDetail((prev) => (prev && prev.chat.id === id ? { ...prev, chat } : prev));
            setChats((prev) => upsertChat(prev, chat));
        } catch (e) {
            setScopeError(toApiError(e));
        } finally {
            setScopeBusy(false);
        }
    }

    function fillComposer(text: string, select?: Selection) {
        const range: Selection = select ?? [text.length, text.length];
        setDraft(text);
        setSendError(null);
        // Same text twice does not re-render: place the cursor now.
        if (text === draft && composerRef.current) placeCaret(composerRef.current, range);
        else pendingCaret.current = range;
    }

    // ── Render ───────────────────────────────────────────────────────────────

    const header = {
        title: 'Ask the manager',
        headTitle: 'Manager chat',
        current: AGENT_SCREEN_PATHS.manager,
        width: 'max-w-6xl',
        description:
            'Ask in plain English to update what the agent knows or explain what it did. The manager only drafts proposals: nothing changes until you approve them.',
    };

    if (listError?.status === 403 || detailError?.status === 403) {
        return (
            <AgentPage {...header}>
                <EmptyState icon={<LockIcon className="h-5 w-5 text-muted-foreground" />} title="Only admins can use the manager chat">
                    Ask an admin of this workspace if you need a change to how the agent works.
                </EmptyState>
            </AgentPage>
        );
    }

    const playbookLabels = buildPlaybookLabels(playbooks, detail);

    return (
        <AgentPage {...header}>
            <div className="grid gap-6 md:grid-cols-[16rem_minmax(0,1fr)]">
                <ChatList
                    chats={chats}
                    selectedId={selectedId}
                    limits={limits}
                    error={listError}
                    onSelect={(id) => selectChat(id)}
                    onNew={() => selectChat(null)}
                />

                <section className="min-w-0 space-y-4 rounded-xl border border-border bg-card p-4 sm:p-5">
                    {selectedId !== null && !detail && !detailError && (
                        <p className="flex items-center gap-2 text-sm text-muted-foreground">
                            <Spinner /> Loading the chat…
                        </p>
                    )}
                    {detailError && (
                        <Notice tone="danger">
                            {detailError.status === 404 ? 'This chat no longer exists.' : detailError.message}{' '}
                            <button type="button" className="font-medium text-primary underline" onClick={() => selectChat(null)}>
                                Start a new chat
                            </button>
                        </Notice>
                    )}

                    {detail && (
                        <ChatHeader
                            chat={detail.chat}
                            deleting={deleting}
                            onDelete={() => void deleteChat()}
                            scope={
                                <ScopePanel
                                    chat={detail.chat}
                                    playbooks={playbooks}
                                    labels={playbookLabels}
                                    busy={scopeBusy}
                                    error={scopeError}
                                    onSave={(next) => void saveRestrictions(next)}
                                />
                            }
                        />
                    )}

                    {(selectedId === null || (detail && messages.length === 0)) && (
                        <Starters disabled={capReached} onPick={(s) => fillComposer(s.text, s.select)} />
                    )}

                    {detail && messages.length > 0 && (
                        <Thread
                            messages={messages}
                            liveSteps={liveSteps}
                            playbookLabels={playbookLabels}
                            onRetry={(text) => fillComposer(text)}
                        />
                    )}

                    <Composer
                        refEl={composerRef}
                        value={draft}
                        onChange={setDraft}
                        onSend={() => void send()}
                        canSend={canSend}
                        sending={sending}
                        running={running}
                        capReached={capReached}
                        limits={limits}
                        waitNotice={waitNotice && running}
                        error={sendError}
                    />
                </section>
            </div>
        </AgentPage>
    );
}

function buildPlaybookLabels(playbooks: PlaybookOption[], detail: ChatDetail | null): Map<number, string> {
    const labels = new Map<number, string>();
    if (detail) {
        const r = rec<RestrictionsPayload>(detail.chat.restrictions);
        for (const p of [...asArray<PlaybookRef>(r.exclude_playbooks), ...asArray<PlaybookRef>(r.only_playbooks)]) {
            if (typeof p?.id === 'number' && p.slug) labels.set(p.id, p.slug);
        }
        for (const m of detail.messages) {
            for (const c of asArray<Citation>(m.citations)) {
                if (c?.type === 'playbook' && typeof c.id === 'number' && c.label) labels.set(c.id, c.label);
            }
        }
    }
    for (const p of playbooks) labels.set(p.id, p.label);
    return labels;
}

function playbookName(labels: Map<number, string>, id: number): string {
    return labels.get(id) ?? `Playbook #${id}`;
}

// ── Chat list ────────────────────────────────────────────────────────────────

function ChatList({
    chats,
    selectedId,
    limits,
    error,
    onSelect,
    onNew,
}: {
    chats: ManagerChat[] | null;
    selectedId: number | null;
    limits: ManagerLimits | null;
    error: ApiError | null;
    onSelect: (id: number) => void;
    onNew: () => void;
}) {
    const cap = limits?.daily_cost_cap_usd ?? null;
    const spent = limits?.spent_today_usd ?? null;
    return (
        <aside className="min-w-0 space-y-3">
            <Button type="button" className="w-full" onClick={onNew}>
                <PlusIcon className="h-4 w-4" /> New chat
            </Button>

            {error && <ErrorLine error={error} />}
            {!chats && !error && (
                <p className="flex items-center gap-2 text-xs text-muted-foreground">
                    <Spinner className="h-3 w-3" /> Loading chats…
                </p>
            )}
            {chats && chats.length === 0 && <p className="text-xs text-muted-foreground">No chats yet. Your chats are private to you.</p>}
            {chats && chats.length > 0 && (
                <ul className="max-h-64 space-y-1 overflow-y-auto md:max-h-[60vh]" aria-label="Your chats">
                    {chats.map((c) => (
                        <li key={c.id}>
                            <button
                                type="button"
                                onClick={() => onSelect(c.id)}
                                aria-current={c.id === selectedId ? 'true' : undefined}
                                className={cn(
                                    'w-full rounded-lg border px-3 py-2 text-left transition-colors',
                                    c.id === selectedId
                                        ? 'border-primary/40 bg-primary/5'
                                        : 'border-transparent hover:border-border hover:bg-muted/40',
                                )}
                            >
                                <span className="flex items-center gap-1.5">
                                    <span className="min-w-0 flex-1 truncate text-sm font-medium">{c.title || 'Untitled chat'}</span>
                                    {c.running && <Spinner className="h-3 w-3 text-muted-foreground" />}
                                </span>
                                <span className="block truncate text-[11px] text-muted-foreground">
                                    {shortDate(c.last_message_at ?? c.created_at)}
                                    {typeof c.cost_usd === 'number' && c.cost_usd > 0 ? ` · ${usd(c.cost_usd)}` : ''}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            {limits && (
                <div className="space-y-1 rounded-lg border border-border bg-muted/20 px-3 py-2">
                    <p className="text-[11px] text-muted-foreground">
                        Spent today: <span className="tabular-nums text-foreground">{usd(spent)}</span>
                        {cap !== null ? (
                            <>
                                {' '}
                                of the <span className="tabular-nums">{usd(cap)}</span> daily limit
                            </>
                        ) : null}
                    </p>
                    {cap ? <ProgressBar value={(spent ?? 0) / cap} /> : null}
                    {limits.cap_reached && <p className="text-[11px] text-warning">Today's limit is used up.</p>}
                </div>
            )}
        </aside>
    );
}

// ── Chat header + scope restrictions ─────────────────────────────────────────

function ChatHeader({
    chat,
    deleting,
    onDelete,
    scope,
}: {
    chat: ManagerChat;
    deleting: boolean;
    onDelete: () => void;
    scope: React.ReactNode;
}) {
    return (
        <div className="space-y-3 border-b border-border pb-4">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 className="truncate text-sm font-semibold">{chat.title || 'Untitled chat'}</h2>
                    <p className="text-[11px] text-muted-foreground">
                        Started {shortDate(chat.created_at)}
                        {typeof chat.cost_usd === 'number' ? ` · this chat has cost ${usd(chat.cost_usd)} so far` : ''}
                    </p>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="shrink-0 text-muted-foreground hover:text-destructive"
                    onClick={onDelete}
                    disabled={deleting}
                >
                    {deleting ? <Spinner /> : <Trash2Icon className="h-3.5 w-3.5" />} Delete chat
                </Button>
            </div>
            {scope}
        </div>
    );
}

const selectClass =
    'h-8 min-w-0 rounded-md border border-input bg-background px-2 text-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 disabled:opacity-50';

function ScopePanel({
    chat,
    playbooks,
    labels,
    busy,
    error,
    onSave,
}: {
    chat: ManagerChat;
    playbooks: PlaybookOption[];
    labels: Map<number, string>;
    busy: boolean;
    error: ApiError | null;
    onSave: (next: Restrictions) => void;
}) {
    const r = readRestrictions(chat.restrictions);
    const sources = asArray<RestrictionSource>(rec<RestrictionsPayload>(chat.restrictions).sources);
    const unmatched = [
        ...new Set(sources.filter((s) => s?.matched === false && typeof s.text === 'string' && s.text.trim()).map((s) => s.text!.trim())),
    ];
    const hasOnly = r.only_playbook_ids !== null || r.only_targets !== null;
    const hasAny = r.exclude_playbook_ids.length > 0 || r.exclude_targets.length > 0 || hasOnly;

    // Every playbook we know a name for, active ones first.
    const known = new Map<number, PlaybookOption>();
    for (const p of playbooks) known.set(p.id, p);
    for (const [id, label] of labels) if (!known.has(id)) known.set(id, { id, label, slug: null, archived: false });
    const options = [...known.values()].filter((p) => !p.archived).sort((a, b) => a.label.localeCompare(b.label));

    function change(next: Partial<Restrictions>) {
        onSave({ ...r, ...next });
    }

    function onlyList<T>(list: T[] | null, remove: T): T[] | null {
        const next = (list ?? []).filter((x) => x !== remove);
        return next.length ? next : null;
    }

    function addFrom(value: string, kind: 'exclude' | 'only') {
        const [type, raw] = value.split(':');
        if (type === 'area' && AREAS.includes(raw as AreaTarget)) {
            const area = raw as AreaTarget;
            if (kind === 'exclude') change({ exclude_targets: [...new Set([...r.exclude_targets, area])] });
            else change({ only_targets: [...new Set([...(r.only_targets ?? []), area])] });
        } else if (type === 'playbook' && validId(raw)) {
            const id = validId(raw)!;
            if (kind === 'exclude') change({ exclude_playbook_ids: [...new Set([...r.exclude_playbook_ids, id])] });
            else change({ only_playbook_ids: [...new Set([...(r.only_playbook_ids ?? []), id])] });
        }
    }

    const excludeAreaOptions = AREAS.filter((a) => !r.exclude_targets.includes(a));
    const excludePlaybookOptions = options.filter((p) => !r.exclude_playbook_ids.includes(p.id));
    const onlyAreaOptions = AREAS.filter((a) => !(r.only_targets ?? []).includes(a));
    const onlyPlaybookOptions = options.filter((p) => !(r.only_playbook_ids ?? []).includes(p.id));

    return (
        <div className="space-y-2 rounded-lg bg-muted/20 px-3 py-2.5">
            <div className="flex flex-wrap items-center gap-2">
                <p className="text-xs font-medium">What the manager may change in this chat</p>
                {busy && <Spinner className="h-3 w-3 text-muted-foreground" />}
            </div>
            {!hasAny && <p className="text-xs text-muted-foreground">Anything it thinks needs changing (as drafts for you to review).</p>}

            {(r.exclude_targets.length > 0 || r.exclude_playbook_ids.length > 0) && (
                <ChipRow label="Leave alone">
                    {r.exclude_targets.map((a) => (
                        <Chip
                            key={`a-${a}`}
                            tone="exclude"
                            disabled={busy}
                            onRemove={() => change({ exclude_targets: r.exclude_targets.filter((x) => x !== a) })}
                        >
                            {AREA_LABELS[a]}
                        </Chip>
                    ))}
                    {r.exclude_playbook_ids.map((id) => (
                        <Chip
                            key={`p-${id}`}
                            tone="exclude"
                            disabled={busy}
                            onRemove={() => change({ exclude_playbook_ids: r.exclude_playbook_ids.filter((x) => x !== id) })}
                        >
                            {playbookName(labels, id)}
                        </Chip>
                    ))}
                </ChipRow>
            )}

            {hasOnly && (
                <ChipRow label="Only change">
                    {(r.only_targets ?? []).map((a) => (
                        <Chip
                            key={`a-${a}`}
                            tone="only"
                            disabled={busy}
                            onRemove={() => change({ only_targets: onlyList(r.only_targets, a) })}
                        >
                            {AREA_LABELS[a]}
                        </Chip>
                    ))}
                    {(r.only_playbook_ids ?? []).map((id) => (
                        <Chip
                            key={`p-${id}`}
                            tone="only"
                            disabled={busy}
                            onRemove={() => change({ only_playbook_ids: onlyList(r.only_playbook_ids, id) })}
                        >
                            {playbookName(labels, id)}
                        </Chip>
                    ))}
                </ChipRow>
            )}

            {unmatched.length > 0 && (
                <ul className="space-y-0.5">
                    {unmatched.map((text) => (
                        <li key={text} className="flex items-start gap-1.5 text-xs text-warning">
                            <AlertTriangleIcon className="mt-0.5 h-3 w-3 shrink-0" />
                            <span>I could not tell what “{text}” refers to — add it below if it matters.</span>
                        </li>
                    ))}
                </ul>
            )}

            <div className="flex flex-wrap gap-2">
                <select
                    className={selectClass}
                    value=""
                    disabled={busy}
                    aria-label="Leave something alone"
                    onChange={(e) => e.target.value && addFrom(e.target.value, 'exclude')}
                >
                    <option value="">+ Leave something alone…</option>
                    {excludeAreaOptions.length > 0 && (
                        <optgroup label="Areas">
                            {excludeAreaOptions.map((a) => (
                                <option key={a} value={`area:${a}`}>
                                    {AREA_LABELS[a]}
                                </option>
                            ))}
                        </optgroup>
                    )}
                    {excludePlaybookOptions.length > 0 && (
                        <optgroup label="Playbooks">
                            {excludePlaybookOptions.map((p) => (
                                <option key={p.id} value={`playbook:${p.id}`}>
                                    {p.label}
                                </option>
                            ))}
                        </optgroup>
                    )}
                </select>
                <select
                    className={selectClass}
                    value=""
                    disabled={busy}
                    aria-label="Only allow changes to"
                    onChange={(e) => e.target.value && addFrom(e.target.value, 'only')}
                >
                    <option value="">+ Only change…</option>
                    {onlyAreaOptions.length > 0 && (
                        <optgroup label="Areas">
                            {onlyAreaOptions.map((a) => (
                                <option key={a} value={`area:${a}`}>
                                    {AREA_LABELS[a]}
                                </option>
                            ))}
                        </optgroup>
                    )}
                    {onlyPlaybookOptions.length > 0 && (
                        <optgroup label="Playbooks">
                            {onlyPlaybookOptions.map((p) => (
                                <option key={p.id} value={`playbook:${p.id}`}>
                                    {p.label}
                                </option>
                            ))}
                        </optgroup>
                    )}
                </select>
            </div>
            {hasOnly && (
                <p className="text-[11px] text-muted-foreground">
                    “Only change” limits it to what is listed there; remove those to let it change anything again.
                </p>
            )}
            <ErrorLine error={error} />
        </div>
    );
}

function ChipRow({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex flex-wrap items-center gap-1.5">
            <span className="text-[11px] font-medium text-muted-foreground">{label}:</span>
            {children}
        </div>
    );
}

function Chip({
    children,
    tone,
    disabled,
    onRemove,
}: {
    children: React.ReactNode;
    tone: 'exclude' | 'only';
    disabled?: boolean;
    onRemove: () => void;
}) {
    return (
        <span
            className={cn(
                'inline-flex max-w-full items-center gap-1 rounded-full border py-0.5 pr-1 pl-2 text-xs',
                tone === 'exclude' ? 'border-warning/30 bg-warning/5' : 'border-primary/30 bg-primary/5',
            )}
        >
            {tone === 'exclude' ? (
                <BanIcon className="h-3 w-3 shrink-0 text-warning" />
            ) : (
                <CheckIcon className="h-3 w-3 shrink-0 text-primary" />
            )}
            <span className="min-w-0 truncate">{children}</span>
            <button
                type="button"
                className="rounded-full p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-50"
                onClick={onRemove}
                disabled={disabled}
                aria-label="Remove"
            >
                <XIcon className="h-3 w-3" />
            </button>
        </span>
    );
}

// ── Empty chat ───────────────────────────────────────────────────────────────

function Starters({ disabled, onPick }: { disabled: boolean; onPick: (s: (typeof STARTERS)[number]) => void }) {
    return (
        <div className="space-y-3 py-4 text-center">
            <div className="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-muted">
                <MessagesSquareIcon className="h-5 w-5 text-muted-foreground" />
            </div>
            <div className="space-y-1">
                <p className="text-sm font-semibold">What would you like to change or understand?</p>
                <p className="mx-auto max-w-lg text-xs text-muted-foreground">
                    The manager reads your playbooks, knowledge and past conversations, then drafts proposals. Nothing changes until you
                    approve them.
                </p>
            </div>
            <div className="mx-auto flex max-w-2xl flex-wrap justify-center gap-2">
                {STARTERS.map((s) => (
                    <button
                        key={s.label}
                        type="button"
                        disabled={disabled}
                        onClick={() => onPick(s)}
                        className="rounded-full border border-border bg-background px-3 py-1.5 text-xs hover:border-primary/40 hover:bg-primary/5 disabled:opacity-50"
                    >
                        {s.label}
                    </button>
                ))}
            </div>
        </div>
    );
}

// ── Thread ───────────────────────────────────────────────────────────────────

function Thread({
    messages,
    liveSteps,
    playbookLabels,
    onRetry,
}: {
    messages: ManagerMessage[];
    liveSteps: Record<number, StepEvent[]>;
    playbookLabels: Map<number, string>;
    onRetry: (text: string) => void;
}) {
    const boxRef = useRef<HTMLDivElement | null>(null);
    const byId = new Map(messages.map((m) => [m.id, m]));
    const last = messages[messages.length - 1];
    const liveCount = last ? (liveSteps[last.id]?.length ?? 0) + asArray(last.tool_calls).length : 0;

    // Keep the newest message in view as the thread grows.
    useEffect(() => {
        const el = boxRef.current;
        if (el) el.scrollTop = el.scrollHeight;
    }, [messages.length, last?.status, liveCount]);

    return (
        <div ref={boxRef} className="max-h-[65vh] min-h-48 space-y-4 overflow-y-auto pr-1">
            {messages.map((m) =>
                m.role === 'user' ? (
                    <UserMessage key={m.id} message={m} />
                ) : (
                    <AssistantMessage
                        key={m.id}
                        message={m}
                        live={liveSteps[m.id] ?? []}
                        playbookLabels={playbookLabels}
                        question={m.reply_to_id ? (byId.get(m.reply_to_id)?.content ?? null) : null}
                        onRetry={onRetry}
                    />
                ),
            )}
        </div>
    );
}

function UserMessage({ message }: { message: ManagerMessage }) {
    return (
        <div className="flex justify-end">
            <div className="max-w-[85%] rounded-2xl rounded-br-sm bg-primary/10 px-3.5 py-2">
                <p className="whitespace-pre-wrap break-words text-sm">{message.content}</p>
                <p className="mt-0.5 text-right text-[10px] text-muted-foreground">{shortDate(message.created_at)}</p>
            </div>
        </div>
    );
}

function AssistantMessage({
    message,
    live,
    playbookLabels,
    question,
    onRetry,
}: {
    message: ManagerMessage;
    live: StepEvent[];
    playbookLabels: Map<number, string>;
    question: string | null;
    onRetry: (text: string) => void;
}) {
    const citations = asArray<Citation>(message.citations).filter((c) => c && typeof c.type === 'string');
    const steps = mergeSteps(asArray<ToolCall>(message.tool_calls), live);
    const proposalIds = ids(message.proposal_ids);
    const suggestions = asArray<ManagerSuggestion>(message.suggestions).filter((s) => s && typeof s === 'object');
    const turn = rec<TurnLimits>(message.limits);
    const open = isOpen(message);
    const retry =
        question && !open ? (
            <Button type="button" size="sm" variant="outline" className="h-7 text-xs" onClick={() => onRetry(question)}>
                <RotateCcwIcon className="h-3 w-3" /> Ask again
            </Button>
        ) : null;

    return (
        <div className="flex gap-2.5">
            <div className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-muted">
                <BotIcon className="h-4 w-4 text-muted-foreground" />
            </div>
            <div className="min-w-0 flex-1 space-y-2.5">
                {open && <Progress steps={steps} pending={message.status === 'pending'} />}

                {message.status === 'capped' && (
                    <Notice tone="warning">
                        <p className="font-medium">Today's manager budget was used up before I could start.</p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            Nothing was looked at or changed. You can ask again tomorrow when the daily limit resets.
                        </p>
                        {retry && <div className="mt-2">{retry}</div>}
                    </Notice>
                )}

                {message.status === 'failed' && (
                    <Notice tone="danger">
                        <p className="font-medium">Something went wrong and I could not finish this answer.</p>
                        {message.error && <p className="mt-0.5 break-words text-xs text-muted-foreground">{message.error}</p>}
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {proposalIds.length > 0 ? 'The drafts below were saved before it stopped. ' : 'Nothing was changed. '}
                            Try sending your message again.
                        </p>
                        {retry && <div className="mt-2">{retry}</div>}
                    </Notice>
                )}

                {!open && message.content && <Prose text={message.content} citations={citations} />}

                {!open && (turn.step_cap_reached || turn.token_budget_reached || turn.deadline_reached) && (
                    <p className="flex items-start gap-1.5 text-xs text-warning">
                        <InfoIcon className="mt-0.5 h-3 w-3 shrink-0" />
                        <span>
                            I reached my {limitWords(turn)} before I had looked at everything, so this answer may be incomplete. Ask me to
                            carry on if you need more.
                        </span>
                    </p>
                )}

                {proposalIds.length > 0 && (
                    <div className="space-y-2">
                        {proposalIds.map((id) => (
                            <ProposalCard key={id} id={id} />
                        ))}
                    </div>
                )}

                {suggestions.length > 0 && <Suggestions suggestions={suggestions} playbookLabels={playbookLabels} />}

                {!open && (steps.length > 0 || citations.length > 0) && (
                    <Disclosure title={`What I looked at (${steps.length})`} className="text-muted-foreground">
                        <StepList steps={steps} />
                        {citations.length > 0 && (
                            <div className="mt-2 flex flex-wrap items-center gap-1">
                                <span className="text-[11px] text-muted-foreground">Referred to:</span>
                                {citations.map((c) => (
                                    <CitationChip key={`${c.type}:${c.id}`} type={c.type} id={c.id} citation={c} />
                                ))}
                            </div>
                        )}
                        <CostLine message={message} />
                    </Disclosure>
                )}

                {!open && asArray<string>(turn.unknown_citations).length > 0 && (
                    <p className="text-[11px] text-muted-foreground">
                        I mentioned {plural(asArray<string>(turn.unknown_citations).length, 'item')} I could not find, so{' '}
                        {asArray<string>(turn.unknown_citations).length === 1 ? 'it is' : 'they are'} shown as plain text.
                    </p>
                )}
            </div>
        </div>
    );
}

function limitWords(t: Partial<TurnLimits>): string {
    if (t.deadline_reached) return 'time limit for one answer';
    if (t.step_cap_reached) return `limit of ${t.step_cap ?? 'a few'} lookups for one answer`;
    return 'reading limit for one answer';
}

function CostLine({ message }: { message: ManagerMessage }) {
    const cost = typeof message.cost_usd === 'number' ? message.cost_usd : null;
    const seconds = typeof message.latency_ms === 'number' ? Math.round(message.latency_ms / 1000) : null;
    if (!cost && !seconds) return null;
    return (
        <p className="mt-2 text-[10px] text-muted-foreground">
            {[cost ? `Cost ${usd(cost)}` : null, seconds ? `took ${seconds}s` : null].filter(Boolean).join(' · ')}
        </p>
    );
}

function mergeSteps(toolCalls: ToolCall[], live: StepEvent[]): ToolCall[] {
    const byStep = new Map<number, ToolCall>();
    for (const s of live) byStep.set(s.step, { step: s.step, tool: s.tool, summary: s.summary, ok: s.ok, refused: s.refused });
    for (const t of toolCalls) if (t && typeof t.step === 'number') byStep.set(t.step, t);
    return [...byStep.values()].sort((a, b) => a.step - b.step);
}

const TOOL_LABELS: Record<string, string> = {
    list_playbooks: 'Looking at the list of playbooks',
    get_playbook: 'Reading a playbook',
    get_voice: 'Reading the brand voice',
    search_knowledge: 'Searching the knowledge',
    list_examples: 'Looking at example replies',
    list_runs: 'Looking at recent replies',
    get_run: 'Reading one reply in detail',
    get_conversation: 'Reading a conversation',
    feedback_stats: 'Checking how often drafts were edited',
    list_qa_reviews: 'Reading quality reviews',
    simulation_results: 'Reading test results',
    proposals: 'Looking at proposals',
    add_restriction: 'Noting what not to change',
    stage_proposal: 'Drafting a proposal',
};

function stepLabel(s: ToolCall): string {
    return (typeof s.summary === 'string' && s.summary.trim()) || TOOL_LABELS[s.tool] || humanize(String(s.tool ?? 'step'));
}

function StepList({ steps }: { steps: ToolCall[] }) {
    if (steps.length === 0) return <p className="text-xs text-muted-foreground">It answered without looking anything up.</p>;
    return (
        <ol className="space-y-0.5">
            {steps.map((s) => (
                <li key={s.step} className="flex items-start gap-1.5 text-xs">
                    {s.refused ? (
                        <BanIcon className="mt-0.5 h-3 w-3 shrink-0 text-muted-foreground" />
                    ) : s.ok === false ? (
                        <AlertTriangleIcon className="mt-0.5 h-3 w-3 shrink-0 text-warning" />
                    ) : (
                        <CheckIcon className="mt-0.5 h-3 w-3 shrink-0 text-success" />
                    )}
                    <span className={cn('min-w-0 text-foreground', s.refused && 'text-muted-foreground line-through')}>{stepLabel(s)}</span>
                    {s.refused && <span className="shrink-0 text-muted-foreground">skipped: limit reached</span>}
                    {!s.refused && s.ok === false && <span className="shrink-0 text-muted-foreground">did not work</span>}
                </li>
            ))}
        </ol>
    );
}

function Progress({ steps, pending }: { steps: ToolCall[]; pending: boolean }) {
    return (
        <div className="space-y-1.5 rounded-lg border border-border bg-muted/20 px-3 py-2">
            <p className="flex items-center gap-2 text-xs font-medium">
                <Spinner className="h-3 w-3" />
                {pending && steps.length === 0 ? 'Waiting to start…' : 'Working on it…'}
            </p>
            {steps.length > 0 && <StepList steps={steps} />}
            <p className="text-[11px] text-muted-foreground">This usually takes a minute or two. You can leave this page and come back.</p>
        </div>
    );
}

// ── Prose with citation chips ────────────────────────────────────────────────

type Block = { kind: 'p'; lines: string[] } | { kind: 'ul' | 'ol'; items: string[] } | { kind: 'h'; text: string };

/** Paragraphs, bullet / numbered lists and simple headings; everything else stays plain text. */
function parseBlocks(text: string): Block[] {
    const blocks: Block[] = [];
    let gap = true;
    for (const raw of text.replace(/\r\n?/g, '\n').split('\n')) {
        const line = raw.trimEnd();
        if (!line.trim()) {
            gap = true;
            continue;
        }
        const heading = /^\s*#{1,6}\s+(.*)$/.exec(line);
        const bullet = /^\s*[-*•]\s+(.*)$/.exec(line);
        const numbered = /^\s*\d+[.)]\s+(.*)$/.exec(line);
        const lastBlock = blocks[blocks.length - 1];
        if (heading) {
            blocks.push({ kind: 'h', text: heading[1] });
            gap = true;
        } else if (bullet || numbered) {
            const kind = bullet ? 'ul' : 'ol';
            const item = (bullet ?? numbered)![1];
            if (lastBlock && lastBlock.kind === kind) lastBlock.items.push(item);
            else blocks.push({ kind, items: [item] });
            gap = false;
        } else if (!gap && lastBlock && (lastBlock.kind === 'ul' || lastBlock.kind === 'ol') && /^\s{2,}\S/.test(raw)) {
            lastBlock.items[lastBlock.items.length - 1] += ` ${line.trim()}`;
        } else if (!gap && lastBlock && lastBlock.kind === 'p') {
            lastBlock.lines.push(line);
        } else {
            blocks.push({ kind: 'p', lines: [line] });
            gap = false;
        }
    }
    return blocks;
}

const INLINE = /\[(run|conversation|playbook|proposal|knowledge|example|qa|simulation|voice)(?::(\d+))?\]|\*\*([^*\n]+)\*\*/g;

function renderInline(text: string, cites: Map<string, Citation>, key: string): React.ReactNode[] {
    const out: React.ReactNode[] = [];
    let last = 0;
    let n = 0;
    for (const m of text.matchAll(INLINE)) {
        const at = m.index ?? 0;
        if (at > last) out.push(text.slice(last, at));
        if (m[3] !== undefined) {
            out.push(<strong key={`${key}-${n++}`}>{m[3]}</strong>);
        } else if (m[2] || m[1] === 'voice') {
            const type = m[1];
            const id = m[2] ? Number(m[2]) : null;
            const citation = id !== null ? cites.get(`${type}:${id}`) : ([...cites.values()].find((c) => c.type === 'voice') ?? undefined);
            out.push(<CitationChip key={`${key}-${n++}`} type={type} id={id ?? citation?.id ?? null} citation={citation} />);
        } else {
            out.push(m[0]);
        }
        last = at + m[0].length;
    }
    if (last < text.length) out.push(text.slice(last));
    return out;
}

function Prose({ text, citations }: { text: string; citations: Citation[] }) {
    const cites = new Map(citations.map((c) => [`${c.type}:${c.id}`, c]));
    const blocks = parseBlocks(text);
    return (
        <div className="space-y-2 text-sm leading-relaxed break-words">
            {blocks.map((b, i) => {
                const key = `b${i}`;
                if (b.kind === 'h') {
                    return (
                        <p key={key} className="font-semibold">
                            {renderInline(b.text, cites, key)}
                        </p>
                    );
                }
                if (b.kind === 'p') {
                    return (
                        <p key={key}>
                            {b.lines.map((line, j) => (
                                <React.Fragment key={j}>
                                    {j > 0 && <br />}
                                    {renderInline(line, cites, `${key}-${j}`)}
                                </React.Fragment>
                            ))}
                        </p>
                    );
                }
                const ListTag = b.kind;
                return (
                    <ListTag key={key} className={cn('space-y-1 pl-5', b.kind === 'ul' ? 'list-disc' : 'list-decimal')}>
                        {b.items.map((item, j) => (
                            <li key={j}>{renderInline(item, cites, `${key}-${j}`)}</li>
                        ))}
                    </ListTag>
                );
            })}
        </div>
    );
}

const CITE_NAMES: Record<string, string> = {
    run: 'Reply',
    conversation: 'Conversation',
    playbook: 'Playbook',
    proposal: 'Proposal',
    knowledge: 'Knowledge',
    example: 'Example reply',
    qa: 'Quality review',
    simulation: 'Test run',
    voice: 'Brand voice',
};

function citationHref(type: string, id: number | null, citation?: Citation): string | null {
    if (type === 'voice') return '/agent/voice';
    if (id === null) return null;
    switch (type) {
        case 'conversation':
            return conversationUrl(id);
        case 'playbook':
            return `/agent/playbooks/${id}`;
        case 'proposal':
            return proposalUrl(id);
        case 'knowledge':
            return `/agent/knowledge/${id}/edit`;
        case 'qa':
            return agentScreenUrl('qa', { review: id });
        case 'simulation':
            return agentScreenUrl('simulations', { id });
        case 'run': {
            // There is no page for a single run; link to its conversation when the server says which one.
            const conversationId = validId(citation?.conversation_id);
            return conversationId ? conversationUrl(conversationId) : null;
        }
        default:
            return null; // example replies have no page of their own
    }
}

function citationLabel(type: string, id: number | null, citation?: Citation): string {
    const label = typeof citation?.label === 'string' ? citation.label.trim() : '';
    if (type === 'run') return id !== null ? `run #${id}` : 'run';
    if (label) return label;
    if (type === 'voice') return 'Brand voice';
    return `${CITE_NAMES[type] ?? humanize(type)}${id !== null ? ` #${id}` : ''}`;
}

function CitationChip({ type, id, citation }: { type: string; id: number | null; citation?: Citation }) {
    const href = citationHref(type, id, citation);
    const label = citationLabel(type, id, citation);
    const title = `${CITE_NAMES[type] ?? humanize(type)}${id !== null && type !== 'voice' ? ` #${id}` : ''}${
        type === 'run' && href ? ' (opens its conversation)' : ''
    }`;
    const className = 'mx-0.5 inline-flex max-w-[16rem] items-center gap-1 rounded-md border px-1.5 py-px align-baseline text-xs leading-5';
    if (!href) {
        return (
            <span className={cn(className, 'border-border bg-muted/40 text-muted-foreground')} title={title}>
                <span className="truncate">{label}</span>
            </span>
        );
    }
    return (
        <Link href={href} className={cn(className, 'border-primary/20 bg-primary/5 text-primary hover:bg-primary/10')} title={title}>
            <span className="truncate">{label}</span>
        </Link>
    );
}

// ── Staged proposals ─────────────────────────────────────────────────────────

function ProposalCard({ id }: { id: number }) {
    const [proposal, setProposal] = useState<Proposal | null>(null);
    const [error, setError] = useState<ApiError | null>(null);

    useEffect(() => {
        let alive = true;
        loopApi
            .proposal(id)
            .then((p) => alive && setProposal(p))
            .catch((e) => alive && setError(toApiError(e)));
        return () => {
            alive = false;
        };
    }, [id]);

    const diff = asArray<DiffEntry>(proposal?.diff);
    const targets = [
        ...new Set(
            asArray<ProposalChange>(proposal?.changes).map(
                (c) => c?.target_label || TARGET_TYPE_LABELS[String(c?.target_type)] || humanize(String(c?.target_type ?? '')),
            ),
        ),
    ].filter(Boolean);

    return (
        <div className="space-y-2 rounded-lg border border-primary/20 bg-background px-3 py-2.5">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="text-[10px] font-semibold tracking-wide text-primary uppercase">Draft proposal #{id}</p>
                    <p className="text-sm font-medium">
                        {proposal ? proposal.title || 'Untitled proposal' : error ? 'Could not load this proposal' : 'Loading…'}
                    </p>
                    {targets.length > 0 && <p className="text-[11px] text-muted-foreground">Changes: {targets.join(', ')}</p>}
                </div>
                <div className="flex shrink-0 items-center gap-2">
                    {proposal && <ProposalStatusBadge status={proposal.status} />}
                    <Button asChild size="sm" variant="outline" className="h-7 text-xs">
                        <Link href={proposalUrl(id)}>Review</Link>
                    </Button>
                </div>
            </div>
            {!proposal && !error && <Spinner className="text-muted-foreground" />}
            {error && <ErrorLine error={error.status === 404 ? 'This proposal no longer exists.' : error} />}
            {proposal &&
                (diff.length <= 3 ? (
                    <DiffEntries entries={diff} />
                ) : (
                    <Disclosure title={`Show the ${diff.length} changes`}>
                        <DiffEntries entries={diff} />
                    </Disclosure>
                ))}
        </div>
    );
}

// ── Changes it was not allowed to make ───────────────────────────────────────

const FIELD_WORDS: Record<string, string> = {
    allowed_actions: 'which actions a playbook may take',
    mode: 'whether a playbook only drafts or sends replies by itself',
    rollout_percent: 'how much of the mail a playbook answers by itself',
    always_human: 'the “always hand to a person” setting',
    priority: 'playbook priority',
    caps: 'limits',
    limits: 'limits',
    kill_switch: 'the emergency stop',
    auto_send_delay_seconds: 'the delay before replies are sent by themselves',
    link_allowlist: 'which links replies may contain',
    action_policies: 'action permissions',
    action_policy: 'action permissions',
    policy: 'action permissions',
    status: 'whether it is switched on',
};

const OP_WORDS: Record<string, string> = {
    set_mode: FIELD_WORDS.mode,
    set_rollout: FIELD_WORDS.rollout_percent,
    set_rollout_percent: FIELD_WORDS.rollout_percent,
    allow_action: 'allowing an action',
    add_allowed_action: 'allowing an action',
    disallow_action: 'blocking an action',
    remove_allowed_action: 'blocking an action',
    set_allowed_actions: FIELD_WORDS.allowed_actions,
    set_action_policy: 'action permissions',
    set_policy: 'action permissions',
    set_caps: 'limits',
    set_cap: 'limits',
    set_limit: 'limits',
    set_kill_switch: FIELD_WORDS.kill_switch,
    set_always_human: FIELD_WORDS.always_human,
    set_priority: FIELD_WORDS.priority,
    set_auto_send: 'sending replies by itself',
};

function scalar(value: unknown): string | null {
    if (typeof value === 'string' && value.trim()) return value.trim();
    if (typeof value === 'number' || typeof value === 'boolean') return String(value);
    return null;
}

function describeOp(op: ManagerSuggestion['op']): { words: string; keys: string[] } {
    if (typeof op === 'string') return { words: OP_WORDS[op] ?? humanize(op).toLowerCase(), keys: [op] };
    const o = rec<Record<string, unknown>>(op) as Record<string, unknown>;
    const name = typeof o.op === 'string' ? o.op : null;
    if (name === 'set_field' && typeof o.field === 'string') {
        const value = scalar(o.value);
        return {
            words: `${FIELD_WORDS[o.field] ?? humanize(o.field).toLowerCase()}${value ? ` (to “${value}”)` : ''}`,
            keys: [o.field],
        };
    }
    if (name) {
        const action = scalar(o.action) ?? scalar(o.action_key);
        const words = OP_WORDS[name] ?? humanize(name).toLowerCase();
        return { words: action ? `${words} (“${humanize(action).toLowerCase()}”)` : words, keys: [name] };
    }
    // Settings stripped from a new playbook: the op is just the removed keys.
    const keys = Object.keys(o).filter((k) => FIELD_WORDS[k]);
    if (keys.length) return { words: [...new Set(keys.map((k) => FIELD_WORDS[k]))].join(', '), keys };
    return { words: 'a setting', keys: [] };
}

function settingsPlace(s: ManagerSuggestion, keys: string[], labels: Map<number, string>): { href: string; label: string } {
    if (keys.some((k) => /polic|cap|limit/.test(k))) return { href: agentScreenUrl('actionPolicies'), label: 'Action permissions' };
    const playbookId = s.target_type === 'playbook' ? validId(s.target_id) : null;
    if (playbookId) return { href: `/agent/playbooks/${playbookId}`, label: `the settings of “${playbookName(labels, playbookId)}”` };
    if (s.target_type === 'playbook') return { href: '/agent/playbooks', label: 'the playbook’s settings' };
    return { href: '/agent/settings', label: 'Agent settings' };
}

function Suggestions({ suggestions, playbookLabels }: { suggestions: ManagerSuggestion[]; playbookLabels: Map<number, string> }) {
    const forbidden = suggestions.filter((s) => s.type !== 'scope');
    // Reasons read like "Left out the change to the voice: you asked not to change the voice."
    // (older messages may still start with "changes.0: ", stripped below).
    const scope = [
        ...new Set(
            suggestions
                .filter((s) => s.type === 'scope')
                .map((s) =>
                    (s.reason ?? '')
                        .replace(/^Not staged:\s*/i, '')
                        .replace(/^changes\.\d+:\s*/i, '')
                        .trim(),
                ),
        ),
    ].map((reason) => ({ reason }));
    return (
        <div className="space-y-1.5">
            {forbidden.map((s, i) => {
                const { words, keys } = describeOp(s.op);
                const place = settingsPlace(s, keys, playbookLabels);
                const proposalId = validId(s.proposal_id);
                return (
                    <p
                        key={`f${i}`}
                        className="flex items-start gap-1.5 rounded-md bg-muted/30 px-2.5 py-1.5 text-xs"
                        title={s.reason ?? undefined}
                    >
                        <LightbulbIcon className="mt-0.5 h-3.5 w-3.5 shrink-0 text-warning" />
                        <span>
                            I can't change permissions, limits, modes or rollout, so I left out {words}. You can do that yourself in{' '}
                            <Link href={place.href} className="font-medium text-primary hover:underline">
                                {place.label}
                            </Link>
                            .
                            {proposalId ? (
                                <span className="text-muted-foreground"> The rest of that change is in draft proposal #{proposalId}.</span>
                            ) : null}
                        </span>
                    </p>
                );
            })}
            {scope.map((s, i) => (
                <p key={`s${i}`} className="flex items-start gap-1.5 rounded-md bg-muted/30 px-2.5 py-1.5 text-xs">
                    <BanIcon className="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                    <span>Not changed because you asked me not to{s.reason ? `: ${s.reason}` : '.'}</span>
                </p>
            ))}
        </div>
    );
}

// ── Composer ─────────────────────────────────────────────────────────────────

function Composer({
    refEl,
    value,
    onChange,
    onSend,
    canSend,
    sending,
    running,
    capReached,
    limits,
    waitNotice,
    error,
}: {
    refEl: React.RefObject<HTMLTextAreaElement | null>;
    value: string;
    onChange: (v: string) => void;
    onSend: () => void;
    canSend: boolean;
    sending: boolean;
    running: boolean;
    capReached: boolean;
    limits: ManagerLimits | null;
    waitNotice: boolean;
    error: ApiError | null;
}) {
    const length = value.trim().length;
    const tooLong = length > MAX_CHARS;
    const fieldError = error?.errors?.content?.[0];
    const cap = limits?.daily_cost_cap_usd;
    const isMac = typeof navigator !== 'undefined' && /mac/i.test(navigator.platform || navigator.userAgent);

    return (
        <form
            className="space-y-2 border-t border-border pt-4"
            onSubmit={(e) => {
                e.preventDefault();
                onSend();
            }}
        >
            {capReached && (
                <Notice tone="warning">
                    Today's manager budget{typeof cap === 'number' ? ` of ${usd(cap)}` : ''} is used up, so it cannot take new requests
                    until tomorrow. Your chats and drafts are still here.
                </Notice>
            )}
            {waitNotice && (
                <Notice>Still working on your last request. Wait for it to finish, then send this one (your text is kept).</Notice>
            )}
            <Textarea
                ref={refEl}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                        e.preventDefault();
                        onSend();
                    }
                }}
                rows={3}
                maxLength={MAX_CHARS + 500}
                disabled={capReached}
                placeholder="Ask the manager… e.g. “We now offer free returns within 60 days — update whatever needs updating.”"
                className="max-h-72 min-h-20"
                aria-label="Message to the manager"
                aria-invalid={tooLong || !!fieldError || undefined}
            />
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-[11px] text-muted-foreground">
                    {running
                        ? 'You can send another message once this answer is finished.'
                        : `${isMac ? '⌘' : 'Ctrl'}+Enter to send · it drafts proposals, nothing changes until you approve them`}
                    {length > MAX_CHARS - 1000 && (
                        <span className={cn('ml-2 tabular-nums', tooLong && 'text-destructive')}>
                            {length.toLocaleString()} / {MAX_CHARS.toLocaleString()}
                        </span>
                    )}
                </p>
                <Button type="submit" size="sm" disabled={!canSend}>
                    {sending || running ? <Spinner /> : <SendIcon className="h-3.5 w-3.5" />}
                    {sending ? 'Sending…' : running ? 'Working…' : 'Send'}
                </Button>
            </div>
            {tooLong && <FieldError message={`Please keep it under ${MAX_CHARS.toLocaleString()} characters.`} />}
            {fieldError ? <FieldError message={fieldError} /> : <ErrorLine error={error} />}
        </form>
    );
}
