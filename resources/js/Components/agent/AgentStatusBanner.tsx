import { Link, usePage } from '@inertiajs/react';
import { OctagonXIcon, PauseCircleIcon } from 'lucide-react';
import { isAdminRole } from '@/lib/roles';
import type { PageProps } from '@/types';

/**
 * Shared Inertia prop `agentStatus` (HandleInertiaRequests, admins only):
 * `{mode, kill_switch, paused_until, paused_reason} | null`.
 */
export type { SharedAgentStatus } from '@/types';

/** "AI agent paused / kill switch on" across the inbox, for admins. */
export default function AgentStatusBanner() {
    const { auth, agentStatus } = usePage<PageProps>().props;
    if (!isAdminRole(auth?.user?.role) || !agentStatus || typeof agentStatus !== 'object') return null;

    const killed = !!agentStatus.kill_switch;
    const until = agentStatus.paused_until ? new Date(agentStatus.paused_until) : null;
    const paused = !!until && !Number.isNaN(until.getTime()) && until.getTime() > Date.now();
    if (!killed && !paused) return null;

    return (
        <div
            role="status"
            className={
                killed
                    ? 'flex items-center gap-2 border-b border-destructive/30 bg-destructive/10 px-4 py-2 text-xs'
                    : 'flex items-center gap-2 border-b border-warning/30 bg-warning/10 px-4 py-2 text-xs'
            }
        >
            {killed ? (
                <OctagonXIcon className="h-4 w-4 shrink-0 text-destructive" />
            ) : (
                <PauseCircleIcon className="h-4 w-4 shrink-0 text-warning" />
            )}
            <span className="flex-1">
                {killed ? (
                    <>
                        <span className="font-semibold">The AI agent’s kill switch is on.</span> It is not drafting, sending or taking any
                        actions.
                    </>
                ) : (
                    <>
                        <span className="font-semibold">AI auto-sending is paused</span>
                        {until && until.getFullYear() < 2100
                            ? ` until ${until.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })}`
                            : ''}
                        {agentStatus.paused_reason ? ` (${agentStatus.paused_reason})` : ''}. Replies stay as drafts for a person.
                    </>
                )}
            </span>
            <Link href="/agent/settings" className="shrink-0 font-medium text-primary hover:underline">
                Agent settings
            </Link>
        </div>
    );
}
