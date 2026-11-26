// The whole app's navigation in one place. AppLayout renders it as the left
// sidebar (full, icon rail, and the mobile drawer), so every screen is always
// reachable from the same spot instead of hiding behind top-bar dropdowns.

import {
    BarChartIcon,
    BookOpenIcon,
    BotIcon,
    BrainIcon,
    ClipboardListIcon,
    ClockIcon,
    FolderIcon,
    GlobeIcon,
    InboxIcon,
    KeyIcon,
    LayoutDashboardIcon,
    MailIcon,
    MailboxIcon,
    MessageSquareIcon,
    MessageSquareTextIcon,
    MonitorIcon,
    PuzzleIcon,
    SettingsIcon,
    ShuffleIcon,
    SparklesIcon,
    TagIcon,
    ThumbsUpIcon,
    UsersIcon,
    type LucideIcon,
} from 'lucide-react';
import { AGENT_NAV_GROUPS, type AgentNavBadge } from '@/Components/agent/agentNav';
import { AGENT_SCREEN_PATHS } from '@/lib/agentLoop';

export interface NavItem {
    href: string;
    label: string;
    icon?: LucideIcon;
    /** Active only on this exact path (not on sub-paths). */
    exact?: boolean;
    /** Extra path prefix that also marks this item active. */
    prefix?: string;
    badge?: AgentNavBadge;
}

export interface NavGroup {
    label: string | null;
    items: NavItem[];
}

export interface NavSection {
    key: string;
    label: string;
    icon: LucideIcon;
    /** Where clicking the section goes. */
    href: string;
    groups?: NavGroup[];
    /** Path prefixes that make the section active (besides its items). */
    match?: string[];
    /** Sum these badges on the section row. */
    badges?: AgentNavBadge[];
}

function cleanPath(path: string): string {
    return path.split('?')[0].replace(/\/+$/, '') || '/';
}

function matchesPrefix(path: string, prefix: string): boolean {
    const p = prefix.replace(/\/+$/, '');
    return path === p || path.startsWith(`${p}/`);
}

export function isItemActive(item: NavItem, path: string): boolean {
    const clean = cleanPath(path);
    if (item.exact) return clean === item.href;
    return matchesPrefix(clean, item.href) || (!!item.prefix && matchesPrefix(clean, item.prefix));
}

export function isSectionActive(section: NavSection, path: string): boolean {
    const clean = cleanPath(path);
    if (section.match?.some((m) => matchesPrefix(clean, m))) return true;
    if (section.groups) return section.groups.some((g) => g.items.some((i) => isItemActive(i, path)));
    return matchesPrefix(clean, section.href);
}

export function activeItem(section: NavSection, path: string): NavItem | null {
    for (const group of section.groups ?? []) {
        const hit = group.items.find((i) => isItemActive(i, path));
        if (hit) return hit;
    }
    return null;
}

export function buildNav({
    isAdmin,
    modules,
    hasPendingActions,
}: {
    isAdmin: boolean;
    modules: string[];
    hasPendingActions: boolean;
}): NavSection[] {
    const has = (m: string) => modules.includes(m);
    const sections: NavSection[] = [
        { key: 'dashboard', label: 'Dashboard', icon: LayoutDashboardIcon, href: '/dashboard' },
        { key: 'inbox', label: 'Inbox', icon: InboxIcon, href: '/conversations', match: ['/conversations'] },
        { key: 'live-chat', label: 'Live Chat', icon: MessageSquareIcon, href: '/live-chat' },
        { key: 'customers', label: 'Customers', icon: UsersIcon, href: '/customers' },
        { key: 'analytics', label: 'Analytics', icon: BarChartIcon, href: '/reports' },
    ];

    if (isAdmin) {
        sections.push({
            key: 'agent',
            label: 'AI Agent',
            icon: SparklesIcon,
            href: '/agent',
            match: ['/agent'],
            badges: ['actions', 'proposals'],
            groups: AGENT_NAV_GROUPS.map((g) => ({
                label: g.label,
                items: g.links.map((l) => ({ href: l.href, label: l.label, icon: l.icon, exact: l.exact, badge: l.badge })),
            })),
        });
    } else if (hasPendingActions) {
        sections.push({
            key: 'approvals',
            label: 'Approvals',
            icon: SparklesIcon,
            href: AGENT_SCREEN_PATHS.actions,
            badges: ['actions'],
        });
    }

    sections.push({
        key: 'ai',
        label: 'AI & Automation',
        icon: BrainIcon,
        href: '/settings/ai',
        groups: [
            {
                label: null,
                items: [
                    { href: '/settings/ai', label: 'AI Config', icon: BrainIcon },
                    { href: '/ai/knowledge-base', label: 'Knowledge Base', icon: BookOpenIcon, prefix: '/ai' },
                    { href: '/automation', label: 'Automation', icon: BotIcon },
                ],
            },
        ],
    });

    const inboxSetup: NavItem[] = [
        { href: '/tags', label: 'Tags', icon: TagIcon },
        { href: '/settings/folders', label: 'Folders', icon: FolderIcon },
        { href: '/settings/canned-responses', label: 'Canned Responses', icon: MessageSquareTextIcon },
    ];
    if (has('ConversationRouting')) inboxSetup.push({ href: '/settings/routing', label: 'Routing', icon: ShuffleIcon });
    if (has('SlaManager')) {
        inboxSetup.push(
            { href: '/settings/sla', label: 'SLA Policies', icon: ClockIcon, exact: true },
            { href: '/settings/sla/report', label: 'SLA Report', icon: BarChartIcon },
        );
    }
    if (has('SatisfactionSurvey')) {
        inboxSetup.push(
            { href: '/settings/survey', label: 'CSAT Survey', icon: ThumbsUpIcon, exact: true },
            { href: '/settings/survey/report', label: 'CSAT Report', icon: BarChartIcon },
        );
    }

    const customerFacing: NavItem[] = [
        { href: '/settings/appearance', label: 'Appearance', icon: MonitorIcon },
        { href: '/settings/live-chat', label: 'Live Chat Widget', icon: MessageSquareIcon },
    ];
    if (has('CustomerPortal')) customerFacing.push({ href: '/settings/portal', label: 'Customer Portal', icon: GlobeIcon });

    sections.push({
        key: 'settings',
        label: 'Settings',
        icon: SettingsIcon,
        href: '/settings',
        groups: [
            {
                label: 'Workspace',
                items: [
                    { href: '/settings', label: 'General', icon: SettingsIcon, exact: true },
                    { href: '/settings/users', label: 'Team', icon: UsersIcon },
                    { href: '/mailboxes', label: 'Mailboxes', icon: MailboxIcon },
                    { href: '/settings/email', label: 'Email', icon: MailIcon },
                    { href: '/settings/modules', label: 'Modules', icon: PuzzleIcon },
                    { href: '/settings/api-keys', label: 'API Keys', icon: KeyIcon },
                    { href: '/settings/audit-log', label: 'Audit Log', icon: ClipboardListIcon },
                ],
            },
            { label: 'Inbox setup', items: inboxSetup },
            { label: 'Customer-facing', items: customerFacing },
        ],
    });

    return sections;
}
