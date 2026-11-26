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

/**
 * What's on each page, so the sidebar search finds a setting without knowing
 * which page it lives on. Keep these in step with the page's headings/labels.
 */
const KEYWORDS: Record<string, string[]> = {
    '/dashboard': ['overview', 'stats', 'home'],
    '/conversations': ['tickets', 'email', 'mine', 'unassigned', 'starred', 'pending', 'snoozed', 'closed'],
    '/live-chat': ['chat', 'visitors'],
    '/customers': ['contacts', 'people'],
    '/reports': ['reports', 'metrics', 'response time', 'volume'],
    '/agent': ['agent overview', 'status'],
    '/agent/voice': ['tone', 'style', 'length guidance', 'maximum words', 'version history'],
    '/agent/playbooks': ['scenarios', 'auto', 'rules for replies'],
    '/agent/knowledge': ['facts', 'policies', 'answers'],
    '/agent/proposals': ['suggestions', 'review'],
    '/agent/manager': ['chat', 'ask', 'questions'],
    '/agent/mining': ['learn', 'past conversations'],
    '/agent/import': ['import', 'legacy', 'history'],
    '/agent/simulations': ['test cases', 'tests'],
    '/agent/qa': ['quality', 'review', 'grading'],
    '/agent/shadow': ['shadow mode', 'compare', 'go live'],
    '/agent/reports': ['metrics', 'performance'],
    '/agent/settings': [
        'mode',
        'off',
        'shadow',
        'draft for review',
        'live',
        'auto-send',
        'automatic sending',
        'kill switch',
        'pause ai agent',
        'disclosure line',
        'allowed domains',
        'links',
        'conversation status after send',
    ],
    '/agent/actions': ['shopify actions', 'approve', 'pending'],
    '/agent/actions/policies': ['permissions', 'limits', 'refund', 'who can approve'],
    '/agent/actions/audit': ['log', 'refunds', 'shopify history'],
    '/agent/connections': [
        'shopify',
        'shop domain',
        'api key',
        'client id',
        'client secret',
        'tracking',
        'parcel',
        'stuck',
        'pre-order tags',
        'integrations',
    ],
    '/settings/ai': ['provider', 'anthropic', 'openai', 'claude', 'models', 'api key', 'embeddings', 'rag', 'reasoning', 'feature toggles'],
    '/ai/knowledge-base': ['documents', 'articles', 'kb', 'help center'],
    '/automation': ['rules', 'triggers', 'workflows', 'auto assign'],
    '/settings': ['workspace name', 'slug', 'timezone', 'company name', 'company logo', 'website', 'branding'],
    '/settings/users': ['users', 'members', 'invite', 'roles', 'admin', 'agents', 'staff'],
    '/mailboxes': [
        'imap',
        'smtp',
        'incoming mail',
        'outgoing mail',
        'gmail',
        'auto-reply',
        'office hours',
        'auto-close',
        'whatsapp',
        'signature',
    ],
    '/settings/email': ['dns', 'spf', 'dkim', 'dmarc', 'deliverability'],
    '/settings/modules': ['plugins', 'extensions', 'enable', 'features'],
    '/settings/api-keys': ['tokens', 'api', 'integrations', 'mcp'],
    '/settings/audit-log': ['activity', 'history', 'who changed'],
    '/tags': ['labels', 'colors'],
    '/settings/folders': ['organize'],
    '/settings/canned-responses': ['templates', 'macros', 'saved replies', 'snippets'],
    '/settings/routing': ['assignment', 'round robin', 'distribute'],
    '/settings/sla': ['response time', 'deadlines', 'targets'],
    '/settings/sla/report': ['breaches'],
    '/settings/survey': ['csat', 'satisfaction', 'feedback', 'send delay'],
    '/settings/survey/report': ['csat', 'satisfaction', 'ratings', 'responses'],
    '/settings/appearance': ['theme', 'dark mode', 'light mode', 'colour', 'color', 'font', 'radius', 'contrast'],
    '/settings/live-chat': ['widget', 'embed snippet', 'greeting', 'launcher', 'brand colour', 'position'],
    '/settings/portal': ['help center', 'self-service', 'welcome message'],
    '/profile': ['my profile', 'password', 'email signature', 'name', 'avatar'],
};

export interface NavSearchResult {
    href: string;
    label: string;
    /** Where it lives in the sidebar, e.g. "Settings › Inbox setup". */
    where: string;
    /** The keyword that matched, when the label itself didn't. */
    matched: string | null;
}

/** Every page in the nav (plus My Profile) containing all words of the query. */
export function searchNav(sections: NavSection[], query: string): NavSearchResult[] {
    const words = query.toLowerCase().split(/\s+/).filter(Boolean);
    if (!words.length) return [];

    const pages: { href: string; label: string; where: string }[] = [];
    for (const section of sections) {
        if (!section.groups) {
            pages.push({ href: section.href, label: section.label, where: '' });
            continue;
        }
        for (const group of section.groups) {
            for (const item of group.items) {
                pages.push({ href: item.href, label: item.label, where: [section.label, group.label].filter(Boolean).join(' › ') });
            }
        }
    }
    pages.push({ href: '/profile', label: 'My Profile', where: 'Account' });

    const scored: (NavSearchResult & { score: number })[] = [];
    for (const page of pages) {
        const label = page.label.toLowerCase();
        const keywords = KEYWORDS[page.href] ?? [];
        const haystack = [label, page.where.toLowerCase(), ...keywords].join(' | ');
        if (!words.every((w) => haystack.includes(w))) continue;

        const labelHit = words.every((w) => label.includes(w));
        const matched = labelHit ? null : (keywords.find((k) => words.some((w) => k.includes(w))) ?? null);
        const score = label.startsWith(words[0]) ? 0 : labelHit ? 1 : matched ? 2 : 3;
        scored.push({ href: page.href, label: page.label, where: page.where, matched, score });
    }

    return scored.sort((a, b) => a.score - b.score);
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
