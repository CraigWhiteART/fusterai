import React, { useEffect, useRef, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { BookmarkIcon, InboxIcon, KeyboardIcon, PencilIcon, PlusIcon, Trash2Icon } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import AgentStatusBanner from '@/Components/agent/AgentStatusBanner';
import ConversationView from '@/Components/conversation/ConversationView';
import type { AgentOption, ConversationDetail } from '@/Components/conversation/types';
import ConversationRow from '@/Components/conversations/ConversationRow';
import InboxBulkBar from '@/Components/conversations/InboxBulkBar';
import InboxFilters, { type InboxFilterValues } from '@/Components/conversations/InboxFilters';
import NewConversationModal from '@/Components/conversations/NewConversationModal';
import ShortcutsModal from '@/Components/conversations/ShortcutsModal';
import ViewBuilderModal from '@/Components/ViewBuilderModal';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { useConversationListShortcuts } from '@/hooks/useConversationShortcuts';
import { cn } from '@/lib/utils';
import type { Conversation, CustomView, Folder, Mailbox, PageProps, Paginated, Tag } from '@/types';

interface Props {
    conversations: Paginated<Conversation>;
    mailboxes: Mailbox[];
    tags: Tag[];
    folders: Folder[];
    counts: Record<string, number>;
    filters: InboxFilterValues;
    /** The conversation open in the right pane (same shape as Show's `detail`). */
    selected?: ConversationDetail | null;
    agents: AgentOption[];
    activeView?: { id: number; name: string; color: string; filters: CustomView['filters'] } | null;
}

const LIST_PROPS = ['conversations', 'counts'];

const headerIconButton =
    'h-8 w-8 flex items-center justify-center rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted/60 transition-colors';

export default function ConversationsIndex({
    conversations,
    mailboxes,
    tags,
    folders,
    counts,
    filters,
    selected,
    agents,
    activeView,
}: Props) {
    const { auth } = usePage<PageProps>().props;
    const isAgent = auth.user?.role === 'agent';
    const activeStatus = filters.status ?? 'open';
    const [showNewConv, setShowNewConv] = useState(false);
    const [showShortcuts, setShowShortcuts] = useState(false);
    const [showViewBuilder, setShowViewBuilder] = useState(false);
    const [editingView, setEditingView] = useState<CustomView | null>(null);
    const [saveAsFilters, setSaveAsFilters] = useState<Record<string, string | undefined> | undefined>(undefined);
    const [mobileShowDetail, setMobileShowDetail] = useState(!!selected);
    const appliedAgentDefaultRef = useRef(false);
    const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
    const [bulkLoading, setBulkLoading] = useState(false);
    const [starredOverrides, setStarredOverrides] = useState<Record<number, boolean>>({});
    // Optimistic stars only bridge the gap until the list reloads with server state.
    const [overridesFor, setOverridesFor] = useState(conversations);
    if (overridesFor !== conversations) {
        setOverridesFor(conversations);
        setStarredOverrides({});
    }

    const allIds = conversations.data.map((c) => c.id);
    const allSelected = allIds.length > 0 && allIds.every((id) => selectedIds.has(id));
    const currentSelectedId = selected?.conversation.id ?? null;

    useConversationListShortcuts({
        conversationIds: allIds,
        currentId: currentSelectedId,
        onSelect: (id) => selectConversation(id),
    });

    // Global shortcut: ? opens shortcuts modal
    useEffect(() => {
        function handler(e: KeyboardEvent) {
            const target = e.target as HTMLElement;
            if (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable) return;
            if (e.key === '?') {
                e.preventDefault();
                setShowShortcuts((v) => !v);
            }
        }
        document.addEventListener('keydown', handler);
        return () => document.removeEventListener('keydown', handler);
    }, []);

    function handleStar(conv: Conversation, e: React.MouseEvent) {
        e.stopPropagation();
        const current = starredOverrides[conv.id] ?? conv.starred ?? false;
        setStarredOverrides((prev) => ({ ...prev, [conv.id]: !current }));
        window.axios.post(`/conversations/${conv.id}/star`).catch(() => {
            // Revert on failure
            setStarredOverrides((prev) => ({ ...prev, [conv.id]: current }));
        });
    }

    function toggleSelect(id: number, e: React.MouseEvent) {
        e.stopPropagation();
        setSelectedIds((prev) => {
            const next = new Set(prev);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }
            return next;
        });
    }

    function toggleSelectAll() {
        setSelectedIds(allSelected ? new Set() : new Set(allIds));
    }

    async function bulkAction(action: string, extra: Record<string, unknown> = {}) {
        if (selectedIds.size === 0) return;
        setBulkLoading(true);
        try {
            await fetch('/conversations/bulk', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ ids: [...selectedIds], action, ...extra }),
            });
            setSelectedIds(new Set());
            router.reload({ only: LIST_PROPS });
        } finally {
            setBulkLoading(false);
        }
    }

    function go(params: Record<string, string | undefined>) {
        router.get('/conversations', { ...filters, ...params }, { preserveScroll: true, replace: true });
    }

    // A full (not partial) visit: the detail, the list (now showing it read), counts
    // and module props such as `sla` all come back for the newly selected conversation.
    function selectConversation(id: number | null) {
        setMobileShowDetail(id !== null);
        router.get(
            '/conversations',
            { ...filters, conversation: id === null ? undefined : String(id) },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    // Agent default: open list scoped to self unless an explicit assignment filter is provided.
    useEffect(() => {
        if (!isAgent || appliedAgentDefaultRef.current || filters.assigned !== undefined) return;

        appliedAgentDefaultRef.current = true;
        router.get('/conversations', { ...filters, assigned: 'me' }, { preserveScroll: true, replace: true });
    }, [isAgent, filters]);

    // Real-time: reload list when a conversation is updated in this workspace
    useEffect(() => {
        const ch = window.Echo?.private(`workspace.${auth.user?.workspace_id}`);
        const reloadList = () => router.reload({ only: LIST_PROPS, async: true });
        ch?.listen('.conversation.updated', reloadList);
        return () => {
            ch?.stopListening('.conversation.updated', reloadList);
        };
    }, [auth.user?.workspace_id]);

    return (
        <AppLayout
            fullHeight
            onCreateView={() => {
                setEditingView(null);
                setSaveAsFilters(undefined);
                setShowViewBuilder(true);
            }}
        >
            <Head title="Conversations" />

            <div className="flex flex-1 min-h-0 flex-col overflow-hidden">
                <AgentStatusBanner />
                <div className="flex flex-1 min-h-0 overflow-hidden">
                    {/* ── Panel 1: Conversation List ── */}
                    <div
                        className={cn(
                            'shrink-0 flex flex-col border-r border-border bg-background overflow-hidden',
                            // Mobile: full width, hide when viewing detail
                            'w-full md:w-[20rem] xl:w-[21rem]',
                            mobileShowDetail ? 'hidden md:flex' : 'flex',
                        )}
                    >
                        {/* Header */}
                        <div className="flex items-center justify-between px-4 pt-4 pb-3 shrink-0">
                            <div className="flex flex-col gap-0.5 min-w-0 flex-1">
                                {activeView ? (
                                    <div className="flex items-center gap-2 min-w-0">
                                        <span className="h-2.5 w-2.5 rounded-full shrink-0" style={{ backgroundColor: activeView.color }} />
                                        <h1 className="font-semibold text-lg tracking-tight truncate">{activeView.name}</h1>
                                    </div>
                                ) : (
                                    <h1 className="font-semibold text-lg tracking-tight">Inbox</h1>
                                )}
                            </div>
                            <div className="flex items-center gap-1.5 shrink-0">
                                {activeView ? (
                                    <>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setEditingView(activeView as unknown as CustomView);
                                                setSaveAsFilters(undefined);
                                                setShowViewBuilder(true);
                                            }}
                                            title="Edit view"
                                            className={headerIconButton}
                                        >
                                            <PencilIcon className="h-3.5 w-3.5" />
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                if (confirm(`Delete view "${activeView.name}"?`)) {
                                                    router.delete(`/views/${activeView.id}`, {
                                                        onSuccess: () => router.visit('/conversations'),
                                                    });
                                                }
                                            }}
                                            title="Delete view"
                                            className="h-8 w-8 flex items-center justify-center rounded-lg text-muted-foreground hover:text-destructive hover:bg-destructive/10 transition-colors"
                                        >
                                            <Trash2Icon className="h-3.5 w-3.5" />
                                        </button>
                                    </>
                                ) : (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setEditingView(null);
                                            setSaveAsFilters(filters);
                                            setShowViewBuilder(true);
                                        }}
                                        title="Save current filters as a view"
                                        className={headerIconButton}
                                    >
                                        <BookmarkIcon className="h-3.5 w-3.5" />
                                    </button>
                                )}
                                <button
                                    type="button"
                                    onClick={() => setShowShortcuts(true)}
                                    title="Keyboard shortcuts (?)"
                                    className={headerIconButton}
                                >
                                    <KeyboardIcon className="h-3.5 w-3.5" />
                                </button>
                                <Button size="sm" className="h-8 gap-1.5 text-xs rounded-lg px-2.5" onClick={() => setShowNewConv(true)}>
                                    <PlusIcon className="h-3.5 w-3.5" />
                                    New
                                </Button>
                            </div>
                        </div>

                        <InboxFilters
                            filters={filters}
                            counts={counts}
                            isAgent={isAgent}
                            mailboxes={mailboxes}
                            tags={tags}
                            go={go}
                            leading={
                                conversations.data.length > 0 && (
                                    <Checkbox
                                        checked={allSelected}
                                        onCheckedChange={toggleSelectAll}
                                        className="h-3.5 w-3.5 shrink-0"
                                        aria-label={allSelected ? 'Deselect all' : 'Select all'}
                                        title={allSelected ? 'Deselect all' : 'Select all on this page'}
                                    />
                                )
                            }
                        />

                        <InboxBulkBar
                            count={selectedIds.size}
                            loading={bulkLoading}
                            currentUserId={auth.user.id}
                            agents={agents}
                            onAction={bulkAction}
                            onClear={() => setSelectedIds(new Set())}
                        />

                        {/* List */}
                        <div className="flex-1 overflow-y-auto divide-y divide-border/60">
                            {conversations.data.length === 0 ? (
                                <div className="flex flex-col items-center justify-center py-16 px-6 text-center gap-2">
                                    <div className="w-12 h-12 rounded-2xl bg-muted/60 flex items-center justify-center mb-1">
                                        <InboxIcon className="h-5 w-5 text-muted-foreground/60" />
                                    </div>
                                    <p className="text-sm font-medium text-foreground/60">All clear</p>
                                    <p className="text-xs text-muted-foreground/60 leading-relaxed max-w-[14rem]">
                                        No {activeStatus} conversations match your current filters.
                                    </p>
                                </div>
                            ) : (
                                conversations.data.map((conv) => {
                                    const displayConv =
                                        starredOverrides[conv.id] !== undefined ? { ...conv, starred: starredOverrides[conv.id] } : conv;
                                    return (
                                        <ConversationRow
                                            key={conv.id}
                                            conversation={displayConv}
                                            isSelected={currentSelectedId === conv.id}
                                            isChecked={selectedIds.has(conv.id)}
                                            onCheck={(e) => toggleSelect(conv.id, e)}
                                            onClick={() => selectConversation(conv.id)}
                                            onStar={(e) => handleStar(conv, e)}
                                        />
                                    );
                                })
                            )}
                        </div>

                        {/* Pagination */}
                        {conversations.last_page > 1 && (
                            <div className="px-3 py-2 border-t border-border flex justify-between text-xs text-muted-foreground shrink-0">
                                <span>
                                    {conversations.from}–{conversations.to} of {conversations.total}
                                </span>
                                <div className="flex gap-2">
                                    {conversations.current_page > 1 && (
                                        <button
                                            onClick={() => go({ page: String(conversations.current_page - 1) })}
                                            className="hover:text-foreground"
                                        >
                                            Prev
                                        </button>
                                    )}
                                    {conversations.current_page < conversations.last_page && (
                                        <button
                                            onClick={() => go({ page: String(conversations.current_page + 1) })}
                                            className="hover:text-foreground"
                                        >
                                            Next
                                        </button>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>

                    {/* ── Panel 2: Conversation Detail ── */}
                    <div
                        className={cn(
                            'flex-1 min-w-0 overflow-hidden flex flex-col',
                            // Mobile: only show when a conversation is selected
                            mobileShowDetail ? 'flex' : 'hidden md:flex',
                        )}
                    >
                        {/* Mobile back button */}
                        {mobileShowDetail && (
                            <div className="flex md:hidden items-center border-b border-border px-3 py-2 shrink-0 bg-background">
                                <button
                                    type="button"
                                    onClick={() => setMobileShowDetail(false)}
                                    className="flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground transition-colors"
                                >
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        className="h-4 w-4"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                        strokeWidth={2}
                                    >
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7" />
                                    </svg>
                                    Back to inbox
                                </button>
                            </div>
                        )}
                        {!selected ? (
                            <div className="flex h-full items-center justify-center bg-muted/10 relative">
                                {/* Subtle background pattern */}
                                <div
                                    className="pointer-events-none absolute inset-0"
                                    style={{
                                        backgroundImage: 'radial-gradient(circle, var(--border) 1px, transparent 1px)',
                                        backgroundSize: '24px 24px',
                                        opacity: 0.45,
                                    }}
                                />
                                <div className="relative text-center px-8">
                                    <div className="w-14 h-14 rounded-2xl bg-background border border-border shadow-sm flex items-center justify-center mx-auto mb-4">
                                        <InboxIcon className="h-6 w-6 text-muted-foreground/50" />
                                    </div>
                                    <p className="text-sm font-medium text-foreground/60 mb-1">No conversation selected</p>
                                    <p className="text-xs text-muted-foreground/50">Pick one from the list to get started</p>
                                </div>
                            </div>
                        ) : (
                            <ConversationView
                                key={selected.conversation.id}
                                detail={selected}
                                agents={agents}
                                tags={tags}
                                folders={folders}
                                mailboxes={mailboxes}
                                layout="split"
                                detailProp="selected"
                                listProps={LIST_PROPS}
                                onLeave={() => selectConversation(null)}
                            />
                        )}
                    </div>
                </div>
            </div>
            {showNewConv && <NewConversationModal mailboxes={mailboxes} onClose={() => setShowNewConv(false)} />}
            {showShortcuts && <ShortcutsModal onClose={() => setShowShortcuts(false)} />}
            {showViewBuilder && (
                <ViewBuilderModal
                    onClose={() => {
                        setShowViewBuilder(false);
                        setEditingView(null);
                        setSaveAsFilters(undefined);
                    }}
                    mailboxes={mailboxes}
                    tags={tags}
                    agents={agents}
                    editingView={editingView}
                    currentFilters={saveAsFilters}
                    userRole={auth.user.role}
                />
            )}
        </AppLayout>
    );
}
