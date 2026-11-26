// The AI agent screens, grouped the way the owner works: teach it (Train),
// check it (Check), run it (Run). Used by the AI Agent menu (AppLayout) and
// the sub-nav on every /agent page (AgentParts.AgentNav).

import {
    BarChart3Icon,
    BookMarkedIcon,
    ClipboardCheckIcon,
    FlaskConicalIcon,
    HistoryIcon,
    LayoutDashboardIcon,
    LibraryIcon,
    ListChecksIcon,
    MessagesSquareIcon,
    MicIcon,
    PickaxeIcon,
    PlugIcon,
    ScrollTextIcon,
    ShieldCheckIcon,
    ShoppingBagIcon,
    SlidersHorizontalIcon,
    TelescopeIcon,
    type LucideIcon,
} from 'lucide-react';
import { AGENT_SCREEN_PATHS } from '@/lib/agentLoop';

export type AgentNavBadge = 'proposals' | 'actions';

export interface AgentNavLink {
    href: string;
    label: string;
    icon: LucideIcon;
    /** Active only on this exact path (not on sub-paths). */
    exact?: boolean;
    badge?: AgentNavBadge;
    /** Visible to non-admins who may approve actions. */
    approvers?: boolean;
}

export interface AgentNavGroup {
    label: string | null;
    links: AgentNavLink[];
}

export const AGENT_NAV_GROUPS: AgentNavGroup[] = [
    { label: null, links: [{ href: '/agent', label: 'Overview', icon: LayoutDashboardIcon, exact: true }] },
    {
        label: 'Train',
        links: [
            { href: '/agent/voice', label: 'Voice', icon: MicIcon },
            { href: '/agent/playbooks', label: 'Playbooks', icon: BookMarkedIcon },
            { href: '/agent/knowledge', label: 'Knowledge', icon: LibraryIcon },
            { href: AGENT_SCREEN_PATHS.proposals, label: 'Proposals', icon: ListChecksIcon, badge: 'proposals' },
            { href: AGENT_SCREEN_PATHS.manager, label: 'Ask the manager', icon: MessagesSquareIcon },
            { href: AGENT_SCREEN_PATHS.mining, label: 'Learn from history', icon: PickaxeIcon },
            { href: '/agent/import', label: 'Import history', icon: HistoryIcon },
        ],
    },
    {
        label: 'Check',
        links: [
            { href: AGENT_SCREEN_PATHS.simulations, label: 'Simulations', icon: FlaskConicalIcon },
            { href: AGENT_SCREEN_PATHS.qa, label: 'Quality checks', icon: ClipboardCheckIcon },
            { href: AGENT_SCREEN_PATHS.shadow, label: 'Shadow report', icon: TelescopeIcon },
            { href: AGENT_SCREEN_PATHS.reports, label: 'Reports', icon: BarChart3Icon },
        ],
    },
    {
        label: 'Run',
        links: [
            { href: '/agent/settings', label: 'Settings', icon: SlidersHorizontalIcon },
            { href: AGENT_SCREEN_PATHS.actions, label: 'Approvals', icon: ShoppingBagIcon, exact: true, badge: 'actions', approvers: true },
            { href: AGENT_SCREEN_PATHS.actionPolicies, label: 'Action rules', icon: ShieldCheckIcon },
            { href: AGENT_SCREEN_PATHS.actionAudit, label: 'Action history', icon: ScrollTextIcon },
            { href: '/agent/connections', label: 'Connections', icon: PlugIcon },
        ],
    },
];

export function isAgentLinkActive(link: AgentNavLink, path: string): boolean {
    const clean = path.split('?')[0].replace(/\/+$/, '') || '/';
    return link.exact ? clean === link.href : clean === link.href || clean.startsWith(`${link.href}/`);
}
