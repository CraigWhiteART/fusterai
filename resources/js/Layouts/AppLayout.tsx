import React from 'react';
import { usePage, Link, router } from '@inertiajs/react';
import SlotRenderer from '@/Components/SlotRenderer';
import NotificationBell from '@/Components/NotificationBell';
import StatusDot from '@/Components/StatusDot';
import { type PageProps, type Folder } from '@/types';
import { type AgentStatus, AGENT_STATUS_COLORS, AGENT_STATUS_LABELS } from '@/lib/agentStatus';
import { Avatar, AvatarFallback, AvatarImage } from '@/Components/ui/avatar';
import GlobalSearch from '@/Components/GlobalSearch';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { cn, getInitials } from '@/lib/utils';
import { isAdminRole } from '@/lib/roles';
import { applyAppearance, persistAppearance, readStoredAppearance } from '@/lib/appearance';
import { toast } from 'sonner';
import {
    PanelLeftIcon,
    ZapIcon,
    LogOutIcon,
    SunIcon,
    MoonIcon,
    MonitorIcon,
    ChevronDownIcon,
    ChevronRightIcon,
    PlusIcon,
    UserIcon,
    MenuIcon,
    XIcon,
    SearchIcon,
} from 'lucide-react';
import { useOpenProposalCount } from '@/lib/agentLoop';
import { usePendingActionCount } from '@/lib/agentActions';
import { type AgentNavBadge } from '@/Components/agent/agentNav';
import { activeItem, buildNav, isItemActive, isSectionActive, searchNav, type NavSection } from '@/lib/appNav';

interface AppLayoutProps {
    children: React.ReactNode;
    title?: string;
    /** Set true for full-height fixed panels (conversations view). Disables main scroll. */
    fullHeight?: boolean;
    /** Callback to open the ViewBuilderModal in the parent page. */
    onCreateView?: () => void;
}

interface SidebarMailbox {
    id: number;
    name: string;
}
interface SidebarTag {
    id: number;
    name: string;
    color: string;
}

const COLLAPSED_KEY = 'fusterai-sidebar-collapsed';

function readCollapsed(): boolean {
    try {
        return window.localStorage.getItem(COLLAPSED_KEY) === '1';
    } catch {
        return false;
    }
}

function CountBadge({ value, strong }: { value: number | null | undefined; strong?: boolean }) {
    if (!value) return null;
    return (
        <span
            className={cn(
                'ml-auto shrink-0 rounded-full px-1.5 text-[10px] font-semibold leading-4 tabular-nums',
                strong ? 'bg-sidebar-primary text-sidebar-primary-foreground' : 'text-sidebar-foreground/55',
            )}
        >
            {value}
        </span>
    );
}

/** Small collapsible group inside the Inbox section (tags, folders, views). */
function SubGroup({
    label,
    open,
    onToggle,
    action,
    children,
}: {
    label: string;
    open: boolean;
    onToggle: () => void;
    action?: React.ReactNode;
    children: React.ReactNode;
}) {
    return (
        <div>
            <div className="flex items-center gap-1 pr-1">
                <button
                    type="button"
                    onClick={onToggle}
                    aria-expanded={open}
                    className="flex flex-1 items-center gap-1 rounded-md px-2 pt-2.5 pb-1 text-left text-[10px] font-semibold uppercase tracking-wider text-sidebar-foreground/50 hover:text-sidebar-foreground/80"
                >
                    {open ? <ChevronDownIcon className="h-3 w-3" /> : <ChevronRightIcon className="h-3 w-3" />}
                    {label}
                </button>
                {action}
            </div>
            {open && <div className="space-y-0.5">{children}</div>}
        </div>
    );
}

export default function AppLayout({ children, fullHeight, onCreateView }: AppLayoutProps) {
    const {
        auth,
        flash,
        counts,
        mailboxes,
        tags,
        folders,
        customViews,
        appearance: appearanceDefaults,
        branding,
        agentStatuses: initialAgentStatuses,
        activeModules,
    } = usePage<
        PageProps & {
            counts?: Record<string, number>;
            mailboxes?: SidebarMailbox[];
            tags?: SidebarTag[];
            folders?: Folder[];
            customViews?: import('@/types').CustomView[];
            activeModules?: string[];
            [key: string]: unknown;
        }
    >().props;
    const [agentStatuses, setAgentStatuses] = React.useState<Record<number, string>>(initialAgentStatuses ?? {});
    const [collapsed, setCollapsed] = React.useState(readCollapsed);
    const [mobileOpen, setMobileOpen] = React.useState(false);
    // Sections the user opened/closed by hand; otherwise only the current section is open.
    const [toggled, setToggled] = React.useState<Record<string, boolean>>({});
    const [tagsOpen, setTagsOpen] = React.useState(false);
    const [foldersOpen, setFoldersOpen] = React.useState(true);
    const [viewsOpen, setViewsOpen] = React.useState(true);
    const toastShown = React.useRef<string | null>(null);
    const [appearance, setAppearance] = React.useState(() => readStoredAppearance(appearanceDefaults));
    const path = window.location.pathname;
    const currentUserStatus = (agentStatuses[auth.user?.id] ?? auth.user?.status ?? 'offline') as AgentStatus;
    const fullPath = path + window.location.search;
    const searchParams = new URLSearchParams(window.location.search);
    const activeMailbox = searchParams.get('mailbox') ?? 'all';
    const isAdmin = isAdminRole(auth.user?.role);
    // AI Agent badges: proposals waiting for review (admins) and Shopify actions waiting
    // for approval (anyone whose role may approve some action; 403 → null).
    const openProposals = useOpenProposalCount(isAdmin);
    const pendingActions = usePendingActionCount(!!auth.user);
    const badgeValues: Record<AgentNavBadge, number | null> = { actions: pendingActions, proposals: openProposals };

    const sections = React.useMemo(
        () => buildNav({ isAdmin, modules: activeModules ?? [], hasPendingActions: !!pendingActions }),
        [isAdmin, activeModules, pendingActions],
    );
    const currentSection = sections.find((s) => isSectionActive(s, path)) ?? null;
    const currentItem = currentSection ? activeItem(currentSection, path) : null;

    const isCommunicationWorkspace = path.startsWith('/conversations') || path.startsWith('/live-chat');
    // The mobile drawer is always the full sidebar, even if the desktop one is collapsed to icons.
    const rail = collapsed && !mobileOpen;
    const navRef = React.useRef<HTMLElement>(null);
    const searchRef = React.useRef<HTMLInputElement>(null);
    const [navQuery, setNavQuery] = React.useState('');
    const [navSel, setNavSel] = React.useState(0);
    const [wantSearchFocus, setWantSearchFocus] = React.useState(false);
    const navResults = React.useMemo(() => searchNav(sections, navQuery), [sections, navQuery]);

    // Each page mounts a fresh sidebar scrolled to the top; bring the current page's link into view.
    React.useEffect(() => {
        navRef.current?.querySelector('[aria-current="page"]')?.scrollIntoView({ block: 'nearest' });
    }, [path]);

    // Ctrl/Cmd+K: jump to the page search, opening the sidebar first if it is hidden or collapsed.
    React.useEffect(() => {
        function onKey(e: KeyboardEvent) {
            if (e.key.toLowerCase() !== 'k' || !(e.ctrlKey || e.metaKey) || e.altKey || e.shiftKey) return;
            e.preventDefault();
            if (window.matchMedia('(max-width: 767px)').matches) setMobileOpen(true);
            else setCollapsed(false);
            setWantSearchFocus(true);
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    React.useEffect(() => {
        if (!wantSearchFocus || !searchRef.current) return;
        searchRef.current.focus();
        searchRef.current.select();
        setWantSearchFocus(false);
    }, [wantSearchFocus, rail, mobileOpen]);

    function goToResult(index: number) {
        const hit = navResults[index];
        if (!hit) return;
        setNavQuery('');
        searchRef.current?.blur();
        router.visit(hit.href);
    }

    function onSearchKey(e: React.KeyboardEvent<HTMLInputElement>) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setNavSel((i) => Math.min(i + 1, navResults.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setNavSel((i) => Math.max(i - 1, 0));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            goToResult(navSel);
        } else if (e.key === 'Escape') {
            setNavQuery('');
            e.currentTarget.blur();
        }
    }

    React.useEffect(() => {
        applyAppearance(appearance);
        persistAppearance(appearance);

        if (appearance.mode !== 'system') return;

        const media = window.matchMedia('(prefers-color-scheme: dark)');
        const listener = () => applyAppearance(appearance);
        media.addEventListener('change', listener);
        return () => media.removeEventListener('change', listener);
    }, [appearance]);

    React.useEffect(() => {
        try {
            window.localStorage.setItem(COLLAPSED_KEY, collapsed ? '1' : '0');
        } catch {
            // storage unavailable — the toggle still works for this page view
        }
    }, [collapsed]);

    React.useEffect(() => {
        const message = flash?.success ?? flash?.error ?? null;
        if (!message || toastShown.current === message) return;

        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
        toastShown.current = message;
    }, [flash?.success, flash?.error]);

    // Real-time agent status updates via Reverb
    React.useEffect(() => {
        if (!auth.user?.workspace_id) return;
        const channel = window.Echo.private(`workspace.${auth.user.workspace_id}`);
        channel.listen('.agent.status.changed', (e: { user_id: number; status: string }) => {
            setAgentStatuses((prev) => ({ ...prev, [e.user_id]: e.status }));
        });
        return () => {
            channel.stopListening('.agent.status.changed');
        };
    }, [auth.user?.workspace_id]);

    // Close mobile sidebar on navigation
    React.useEffect(() => {
        setMobileOpen(false);
    }, [path]);

    function cycleTheme() {
        setAppearance((current) => ({
            ...current,
            mode: current.mode === 'light' ? 'dark' : current.mode === 'dark' ? 'system' : 'light',
        }));
    }

    function switchMailbox(value: string) {
        const status = searchParams.get('status') ?? 'open';
        const assigned = searchParams.get('assigned') ?? (auth.user?.role === 'agent' ? 'me' : null);

        router.get(
            '/conversations',
            {
                status,
                mailbox: value === 'all' ? undefined : value,
                assigned: assigned ?? undefined,
                page: undefined,
                conversation: undefined,
                tag: undefined,
            },
            { preserveScroll: true, replace: true },
        );
    }

    function sectionBadge(section: NavSection): number {
        return (section.badges ?? []).reduce((sum, b) => sum + (badgeValues[b] ?? 0), 0);
    }

    function isOpen(section: NavSection): boolean {
        if (section.key in toggled) return toggled[section.key];
        return section === currentSection;
    }

    const inboxFilters = [
        { label: 'All Conversations', href: '/conversations', count: undefined },
        { label: 'All Open', href: '/conversations?status=open', count: counts?.open },
        { label: 'Mine', href: '/conversations?assigned=me&status=open', count: counts?.mine },
        { label: 'Unassigned', href: '/conversations?assigned=none&status=open', count: undefined },
        { label: 'Starred', href: '/conversations?starred=1', count: counts?.starred },
        { label: 'Pending', href: '/conversations?status=pending', count: counts?.pending },
        { label: 'Snoozed', href: '/conversations?status=snoozed', count: counts?.snoozed },
        { label: 'Closed', href: '/conversations?status=closed', count: undefined },
    ];

    const childClass = (active: boolean) =>
        cn(
            'flex items-center gap-2 rounded-md px-2.5 py-1.5 text-[13px] transition-colors',
            active
                ? 'bg-sidebar-accent text-sidebar-accent-foreground font-semibold shadow-[inset_2px_0_0_var(--sidebar-primary)]'
                : 'text-sidebar-foreground/70 hover:bg-sidebar-accent/45 hover:text-sidebar-foreground',
        );

    const manageLink = (href: string, title: string) => (
        <Link
            href={href}
            title={title}
            className="rounded p-1 text-sidebar-foreground/50 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground"
        >
            <PlusIcon className="h-3 w-3" />
        </Link>
    );

    function renderInboxChildren() {
        return (
            <>
                {inboxFilters.map(({ label, href, count }) => (
                    <Link key={href} href={href} className={childClass(fullPath === href)}>
                        <span className="flex-1 truncate">{label}</span>
                        <CountBadge value={count} />
                    </Link>
                ))}

                {isCommunicationWorkspace && (
                    <>
                        <SubGroup
                            label="Tags"
                            open={tagsOpen}
                            onToggle={() => setTagsOpen((v) => !v)}
                            action={manageLink('/tags', 'Manage tags')}
                        >
                            {tags && tags.length > 0 ? (
                                tags.map((tag) => {
                                    const href = `/conversations?tag=${tag.id}&status=open`;
                                    return (
                                        <Link key={tag.id} href={href} className={childClass(fullPath === href)}>
                                            <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: tag.color }} />
                                            <span className="truncate">{tag.name}</span>
                                        </Link>
                                    );
                                })
                            ) : (
                                <Link
                                    href="/tags"
                                    className="block px-2.5 py-1 text-xs italic text-sidebar-foreground/50 hover:text-sidebar-foreground/75"
                                >
                                    + Create your first tag
                                </Link>
                            )}
                        </SubGroup>

                        <SubGroup
                            label="My Folders"
                            open={foldersOpen}
                            onToggle={() => setFoldersOpen((v) => !v)}
                            action={manageLink('/settings/folders', 'Manage folders')}
                        >
                            {folders && folders.length > 0 ? (
                                folders.map((folder) => {
                                    const href = `/conversations?folder=${folder.id}`;
                                    return (
                                        <Link key={folder.id} href={href} className={childClass(fullPath === href)}>
                                            <span className="h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: folder.color }} />
                                            <span className="flex-1 truncate">{folder.name}</span>
                                            <CountBadge value={folder.open_count} />
                                        </Link>
                                    );
                                })
                            ) : (
                                <Link
                                    href="/settings/folders"
                                    className="block px-2.5 py-1 text-xs italic text-sidebar-foreground/50 hover:text-sidebar-foreground/75"
                                >
                                    + Create your first folder
                                </Link>
                            )}
                        </SubGroup>

                        <SubGroup
                            label="My Views"
                            open={viewsOpen}
                            onToggle={() => setViewsOpen((v) => !v)}
                            action={
                                onCreateView ? (
                                    <button
                                        type="button"
                                        onClick={onCreateView}
                                        title="New view"
                                        className="rounded p-1 text-sidebar-foreground/50 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground"
                                    >
                                        <PlusIcon className="h-3 w-3" />
                                    </button>
                                ) : undefined
                            }
                        >
                            {customViews && customViews.length > 0 ? (
                                customViews.map((view) => {
                                    const href = `/conversations?view=${view.id}`;
                                    return (
                                        <Link key={view.id} href={href} className={childClass(fullPath === href)}>
                                            <span className="h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: view.color }} />
                                            <span className="flex-1 truncate">{view.name}</span>
                                            {view.is_shared && (
                                                <span className="shrink-0 text-[9px] uppercase tracking-wide text-sidebar-foreground/40">
                                                    shared
                                                </span>
                                            )}
                                        </Link>
                                    );
                                })
                            ) : onCreateView ? (
                                <button
                                    type="button"
                                    onClick={onCreateView}
                                    className="block w-full px-2.5 py-1 text-left text-xs italic text-sidebar-foreground/50 hover:text-sidebar-foreground/75"
                                >
                                    + Create your first view
                                </button>
                            ) : null}
                        </SubGroup>
                    </>
                )}
            </>
        );
    }

    function renderSection(section: NavSection) {
        const Icon = section.icon;
        const sectionActive = section === currentSection;
        const expandable = !!section.groups || section.key === 'inbox';
        const open = expandable && isOpen(section);
        // A section that has sub-pages highlights the sub-page, not itself.
        const highlightRow = sectionActive && (!expandable || (!currentItem && section.key !== 'inbox'));
        const badge = sectionBadge(section);

        if (rail) {
            return (
                <Link
                    key={section.key}
                    href={section.href}
                    title={section.label}
                    aria-current={sectionActive ? 'page' : undefined}
                    className={cn(
                        'relative flex h-10 items-center justify-center rounded-lg transition-colors',
                        sectionActive
                            ? 'bg-sidebar-accent text-sidebar-accent-foreground'
                            : 'text-sidebar-foreground/70 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground',
                    )}
                >
                    <Icon className="size-4.5" />
                    {badge > 0 && <span className="absolute right-1.5 top-1.5 size-2 rounded-full bg-sidebar-primary" />}
                </Link>
            );
        }

        return (
            <div key={section.key}>
                <div
                    className={cn(
                        'group flex items-center rounded-lg transition-colors',
                        highlightRow
                            ? 'bg-sidebar-accent text-sidebar-accent-foreground'
                            : sectionActive
                              ? 'text-sidebar-foreground'
                              : 'text-sidebar-foreground/75 hover:bg-sidebar-accent/45 hover:text-sidebar-foreground',
                    )}
                >
                    <Link
                        href={section.href}
                        aria-current={highlightRow ? 'page' : undefined}
                        className={cn(
                            'flex min-w-0 flex-1 items-center gap-2.5 py-2 pl-3 text-[13.5px]',
                            sectionActive ? 'font-semibold' : 'font-medium',
                            !expandable && 'pr-3',
                        )}
                    >
                        <Icon className="h-4 w-4 shrink-0" />
                        <span className="truncate">{section.label}</span>
                        {section.key === 'inbox' ? <CountBadge value={counts?.open} /> : <CountBadge value={badge} strong />}
                    </Link>
                    {expandable && (
                        <button
                            type="button"
                            onClick={() => setToggled((t) => ({ ...t, [section.key]: !open }))}
                            aria-expanded={open}
                            aria-label={`${open ? 'Collapse' : 'Expand'} ${section.label}`}
                            className="mr-1 rounded-md p-1.5 opacity-60 hover:bg-sidebar-accent/60 hover:opacity-100"
                        >
                            <ChevronDownIcon className={cn('h-3.5 w-3.5 transition-transform', !open && '-rotate-90')} />
                        </button>
                    )}
                </div>

                {open && (
                    <div className="mb-1.5 ml-[1.15rem] mt-0.5 space-y-0.5 border-l border-sidebar-foreground/10 pl-2">
                        {section.key === 'inbox'
                            ? renderInboxChildren()
                            : section.groups?.map((group) => (
                                  <div key={group.label ?? 'top'} className="space-y-0.5">
                                      {group.label && (
                                          <p className="px-2.5 pb-0.5 pt-2.5 text-[10px] font-semibold uppercase tracking-wider text-sidebar-foreground/45">
                                              {group.label}
                                          </p>
                                      )}
                                      {group.items.map((item) => {
                                          const active = isItemActive(item, path);
                                          return (
                                              <Link
                                                  key={item.href}
                                                  href={item.href}
                                                  aria-current={active ? 'page' : undefined}
                                                  className={childClass(active)}
                                              >
                                                  <span className="flex-1 truncate">{item.label}</span>
                                                  <CountBadge value={item.badge ? badgeValues[item.badge] : null} strong />
                                              </Link>
                                          );
                                      })}
                                  </div>
                              ))}
                    </div>
                )}
            </div>
        );
    }

    return (
        <div className="flex h-full bg-muted/40 md:p-3 gap-0">
            {/* Mobile sidebar backdrop */}
            {mobileOpen && <div className="fixed inset-0 z-40 bg-black/50 md:hidden" onClick={() => setMobileOpen(false)} />}

            <div className="flex min-w-0 flex-1 rounded-none md:rounded-xl shadow-[0_2px_24px_rgba(0,0,0,0.08)] dark:shadow-[0_2px_24px_rgba(0,0,0,0.28)] overflow-hidden">
                <aside
                    className={cn(
                        'relative flex flex-col bg-sidebar text-sidebar-foreground transition-all duration-200 shrink-0 backdrop-blur-md',
                        // Desktop: collapsible width
                        'hidden md:flex',
                        rail ? 'md:w-16' : 'md:w-64',
                        // Mobile: fixed overlay
                        mobileOpen && 'flex fixed inset-y-0 left-0 z-50 w-72 md:relative md:inset-auto md:z-auto',
                    )}
                >
                    <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_10%_0%,color-mix(in_oklch,var(--primary)_20%,transparent),transparent_40%)]" />
                    <div className="pointer-events-none absolute inset-y-0 right-0 w-px bg-white/20 dark:bg-black/20" />
                    {/* Logo + toggle */}
                    <div className="relative z-10 flex h-16 items-center justify-between px-3.5 border-b border-border/60 shrink-0">
                        {!rail && (
                            <Link href="/dashboard" className="flex items-center gap-2.5 min-w-0">
                                {branding?.logo_url ? (
                                    <img
                                        src={branding.logo_url}
                                        alt={branding.name ?? 'Logo'}
                                        className="h-8 w-8 rounded-xl object-contain"
                                    />
                                ) : (
                                    <div className="inline-flex size-8 shrink-0 items-center justify-center rounded-xl bg-sidebar-primary/15 ring-1 ring-sidebar-primary/25">
                                        <ZapIcon className="h-4 w-4 text-sidebar-primary" />
                                    </div>
                                )}
                                <span className="font-bold text-[15px] leading-none tracking-tight truncate">
                                    {branding?.name || 'FusterAI'}
                                </span>
                            </Link>
                        )}
                        {/* Desktop collapse toggle */}
                        <button
                            type="button"
                            onClick={() => setCollapsed(!collapsed)}
                            title={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
                            className={cn(
                                'hidden md:flex rounded-lg p-2 hover:bg-sidebar-accent/80 transition-colors',
                                rail ? 'mx-auto' : 'ml-auto',
                            )}
                        >
                            <PanelLeftIcon className="h-4 w-4" />
                        </button>
                        {/* Mobile close button */}
                        <button
                            type="button"
                            onClick={() => setMobileOpen(false)}
                            className="ml-auto flex md:hidden rounded-lg p-2 hover:bg-sidebar-accent/80 transition-colors"
                        >
                            <XIcon className="h-4 w-4" />
                        </button>
                    </div>

                    {/* Mailbox switcher */}
                    {!rail && isCommunicationWorkspace && (mailboxes?.length ?? 0) > 0 && (
                        <div className="relative z-10 px-3 py-2.5 border-b border-sidebar-border/70">
                            <Select value={activeMailbox} onValueChange={switchMailbox}>
                                <SelectTrigger className="w-full bg-transparent border-transparent hover:bg-sidebar-accent/40 text-sm font-medium shadow-none">
                                    <SelectValue placeholder="Select mailbox" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All mailboxes</SelectItem>
                                    {mailboxes?.map((mb) => (
                                        <SelectItem key={mb.id} value={String(mb.id)}>
                                            {mb.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}

                    {/* Search */}
                    {!rail && isCommunicationWorkspace && (
                        <div className="relative z-10 px-3 py-2.5 border-b border-sidebar-border/70">
                            <GlobalSearch />
                        </div>
                    )}

                    {/* Page & settings search */}
                    {rail ? (
                        <div className="relative z-10 px-2.5 pt-3">
                            <button
                                type="button"
                                onClick={() => {
                                    setCollapsed(false);
                                    setWantSearchFocus(true);
                                }}
                                title="Search pages & settings (Ctrl+K)"
                                className="flex h-10 w-full items-center justify-center rounded-lg text-sidebar-foreground/70 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground"
                            >
                                <SearchIcon className="size-4.5" />
                            </button>
                        </div>
                    ) : (
                        <div className="relative z-10 px-2.5 pt-3">
                            <div className="relative">
                                <SearchIcon className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-sidebar-foreground/45" />
                                <input
                                    ref={searchRef}
                                    type="search"
                                    value={navQuery}
                                    onChange={(e) => {
                                        setNavQuery(e.target.value);
                                        setNavSel(0);
                                    }}
                                    onKeyDown={onSearchKey}
                                    placeholder="Search pages & settings"
                                    aria-label="Search pages and settings"
                                    aria-controls="nav-search-results"
                                    className="h-8 w-full rounded-lg border border-sidebar-foreground/10 bg-sidebar-foreground/4 pl-8 pr-12 text-[13px] text-sidebar-foreground placeholder:text-sidebar-foreground/45 focus:border-sidebar-primary/60 focus:outline-none [&::-webkit-search-cancel-button]:hidden"
                                />
                                {navQuery ? (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setNavQuery('');
                                            searchRef.current?.focus();
                                        }}
                                        aria-label="Clear search"
                                        className="absolute right-1.5 top-1/2 -translate-y-1/2 rounded p-1 text-sidebar-foreground/50 hover:text-sidebar-foreground"
                                    >
                                        <XIcon className="h-3.5 w-3.5" />
                                    </button>
                                ) : (
                                    <kbd className="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 rounded border border-sidebar-foreground/15 px-1 text-[10px] text-sidebar-foreground/45">
                                        Ctrl K
                                    </kbd>
                                )}
                            </div>
                        </div>
                    )}

                    <nav
                        ref={navRef}
                        aria-label="Main"
                        className="sidebar-scroll relative z-10 flex-1 overflow-y-auto py-3 px-2.5 space-y-0.5"
                    >
                        {!rail && navQuery.trim() ? (
                            <div id="nav-search-results" role="listbox" aria-label="Matching pages" className="space-y-0.5">
                                {navResults.length === 0 ? (
                                    <p className="px-2.5 py-2 text-xs text-sidebar-foreground/55">No page matches “{navQuery.trim()}”.</p>
                                ) : (
                                    navResults.map((r, i) => (
                                        <Link
                                            key={r.href}
                                            href={r.href}
                                            role="option"
                                            aria-selected={i === navSel}
                                            onClick={() => setNavQuery('')}
                                            onMouseEnter={() => setNavSel(i)}
                                            className={cn(
                                                'block rounded-md px-2.5 py-1.5 transition-colors',
                                                i === navSel
                                                    ? 'bg-sidebar-accent text-sidebar-accent-foreground'
                                                    : 'text-sidebar-foreground/80 hover:bg-sidebar-accent/45',
                                            )}
                                        >
                                            <span className="block truncate text-[13px] font-medium">{r.label}</span>
                                            {(r.where || r.matched) && (
                                                <span className="block truncate text-[11px] opacity-60">
                                                    {[r.where, r.matched && `“${r.matched}”`].filter(Boolean).join(' · ')}
                                                </span>
                                            )}
                                        </Link>
                                    ))
                                )}
                            </div>
                        ) : (
                            sections.map(renderSection)
                        )}
                    </nav>

                    {/* Module slot: inject extra sidebar content below nav */}
                    <SlotRenderer name="app.sidebar.bottom" />
                </aside>

                {/* Main content */}
                <div className={cn('flex-1 flex flex-col min-w-0 bg-background', fullHeight ? 'overflow-hidden' : 'overflow-y-auto')}>
                    <header className="sticky top-0 z-20 flex items-center border-b border-border/60 bg-background/95 h-16 px-4 md:px-5 backdrop-blur-sm shrink-0">
                        <div className="flex w-full items-center justify-between gap-4">
                            {/* Mobile hamburger */}
                            <button
                                type="button"
                                onClick={() => setMobileOpen(true)}
                                aria-label="Open menu"
                                className="flex md:hidden items-center justify-center h-9 w-9 rounded-lg hover:bg-muted/70 transition-colors"
                            >
                                <MenuIcon className="h-5 w-5" />
                            </button>

                            {/* Where am I: section › page */}
                            {currentSection && (
                                <nav aria-label="Breadcrumb" className="flex min-w-0 items-center gap-1.5 text-sm">
                                    {currentItem ? (
                                        <>
                                            <Link
                                                href={currentSection.href}
                                                className="truncate text-muted-foreground hover:text-foreground"
                                            >
                                                {currentSection.label}
                                            </Link>
                                            <ChevronRightIcon className="h-3.5 w-3.5 shrink-0 text-muted-foreground/60" />
                                            <span className="truncate font-medium">{currentItem.label}</span>
                                        </>
                                    ) : (
                                        <span className="truncate font-medium">{currentSection.label}</span>
                                    )}
                                </nav>
                            )}

                            <div className="ml-auto flex items-center gap-2">
                                <button
                                    type="button"
                                    onClick={cycleTheme}
                                    className="inline-flex h-10 w-10 items-center justify-center rounded-xl text-foreground transition-colors hover:bg-muted/70"
                                    title={`Theme: ${appearance.mode}`}
                                >
                                    {appearance.mode === 'light' && <SunIcon className="h-4 w-4" />}
                                    {appearance.mode === 'dark' && <MoonIcon className="h-4 w-4" />}
                                    {appearance.mode === 'system' && <MonitorIcon className="h-4 w-4" />}
                                </button>

                                <NotificationBell />

                                {/* Avatar — profile + status + sign out */}
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <button
                                            type="button"
                                            className="inline-flex h-10 items-center gap-2 rounded-xl px-2.5 text-left transition-colors hover:bg-muted/70"
                                        >
                                            <div className="relative shrink-0">
                                                <Avatar className="size-8">
                                                    <AvatarImage src={auth.user?.avatar ?? undefined} alt={auth.user?.name ?? 'User'} />
                                                    <AvatarFallback>{getInitials(auth.user?.name)}</AvatarFallback>
                                                </Avatar>
                                                <StatusDot status={currentUserStatus} />
                                            </div>
                                            <div className="hidden min-w-0 sm:block">
                                                <p className="truncate text-sm font-semibold">{auth.user?.name}</p>
                                                <p className="truncate text-xs text-muted-foreground capitalize">{auth.user?.role}</p>
                                            </div>
                                            <ChevronDownIcon className="h-4 w-4 text-muted-foreground" />
                                        </button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end" className="w-52 border-0 bg-popover/95 shadow-xl backdrop-blur">
                                        <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
                                            {auth.user?.name} · <span className="capitalize">{auth.user?.role}</span>
                                        </DropdownMenuLabel>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuGroup>
                                            {(Object.keys(AGENT_STATUS_LABELS) as AgentStatus[]).map((s) => (
                                                <DropdownMenuItem
                                                    key={s}
                                                    onSelect={() => router.patch('/profile/status', { status: s })}
                                                    className="gap-2"
                                                >
                                                    <span className={cn('size-2 rounded-full shrink-0', AGENT_STATUS_COLORS[s])} />
                                                    <span>{AGENT_STATUS_LABELS[s]}</span>
                                                    {currentUserStatus === s && (
                                                        <span className="ml-auto text-[10px] text-primary font-medium">✓</span>
                                                    )}
                                                </DropdownMenuItem>
                                            ))}
                                        </DropdownMenuGroup>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem asChild>
                                            <Link href="/profile">
                                                <UserIcon className="h-4 w-4" />
                                                <span>My Profile</span>
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            onSelect={(e) => {
                                                e.preventDefault();
                                                router.post('/logout');
                                            }}
                                            className="text-destructive focus:bg-destructive/10 focus:text-destructive"
                                        >
                                            <LogOutIcon className="h-4 w-4" />
                                            <span>Sign out</span>
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </div>
                    </header>

                    <div className={cn('min-h-0 flex-1', fullHeight ? 'flex overflow-hidden' : '')}>{children}</div>
                </div>
            </div>
        </div>
    );
}
