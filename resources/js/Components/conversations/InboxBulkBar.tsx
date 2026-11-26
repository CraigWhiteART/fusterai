import { ChevronDownIcon, XIcon } from 'lucide-react';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/Components/ui/dropdown-menu';

interface Props {
    count: number;
    loading: boolean;
    currentUserId: number;
    agents: { id: number; name: string }[];
    onAction: (action: string, extra?: Record<string, unknown>) => void;
    onClear: () => void;
}

/** Actions for the conversations ticked in the inbox list. */
export default function InboxBulkBar({ count, loading, currentUserId, agents, onAction, onClear }: Props) {
    if (count === 0) return null;
    return (
        <div className="flex items-center gap-2 px-3 py-2 bg-primary/5 border-b border-primary/20 shrink-0 flex-wrap">
            <span className="text-xs font-medium text-primary">{count} selected</span>
            <div className="h-3 w-px bg-border mx-1" />
            <button
                type="button"
                onClick={() => onAction('close')}
                disabled={loading}
                className="text-xs px-2.5 py-1 rounded-md bg-background border border-border hover:bg-muted transition-colors disabled:opacity-50"
            >
                Close
            </button>
            <button
                type="button"
                onClick={() => onAction('reopen')}
                disabled={loading}
                className="text-xs px-2.5 py-1 rounded-md bg-background border border-border hover:bg-muted transition-colors disabled:opacity-50"
            >
                Reopen
            </button>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        disabled={loading}
                        className="text-xs px-2.5 py-1 rounded-md bg-background border border-border hover:bg-muted transition-colors disabled:opacity-50 flex items-center gap-1"
                    >
                        Assign <ChevronDownIcon className="h-3 w-3 opacity-60" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" className="w-44">
                    <DropdownMenuItem onClick={() => onAction('assign', { assigned_to: currentUserId })}>Assign to me</DropdownMenuItem>
                    <DropdownMenuItem onClick={() => onAction('assign', { assigned_to: null })}>Unassign</DropdownMenuItem>
                    {agents
                        .filter((a) => a.id !== currentUserId)
                        .map((agent) => (
                            <DropdownMenuItem key={agent.id} onClick={() => onAction('assign', { assigned_to: agent.id })}>
                                {agent.name}
                            </DropdownMenuItem>
                        ))}
                </DropdownMenuContent>
            </DropdownMenu>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        disabled={loading}
                        className="text-xs px-2.5 py-1 rounded-md bg-background border border-border hover:bg-muted transition-colors disabled:opacity-50 flex items-center gap-1"
                    >
                        Priority <ChevronDownIcon className="h-3 w-3 opacity-60" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start">
                    {(['urgent', 'high', 'normal', 'low'] as const).map((p) => (
                        <DropdownMenuItem key={p} onClick={() => onAction('priority', { priority: p })}>
                            <span className="capitalize">{p}</span>
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>
            <button
                type="button"
                onClick={() => onAction('spam')}
                disabled={loading}
                className="text-xs px-2.5 py-1 rounded-md bg-background border border-border hover:bg-muted text-destructive hover:text-destructive transition-colors disabled:opacity-50"
            >
                Mark spam
            </button>
            <button
                type="button"
                onClick={() => onAction('mark_read')}
                disabled={loading}
                className="text-xs px-2.5 py-1 rounded-md bg-background border border-border hover:bg-muted transition-colors disabled:opacity-50"
            >
                Mark read
            </button>
            <button
                type="button"
                onClick={() => onAction('mark_unread')}
                disabled={loading}
                className="text-xs px-2.5 py-1 rounded-md bg-background border border-border hover:bg-muted transition-colors disabled:opacity-50"
            >
                Mark unread
            </button>
            <button type="button" onClick={onClear} className="ml-auto text-xs text-muted-foreground hover:text-foreground">
                <XIcon className="h-3.5 w-3.5" />
            </button>
        </div>
    );
}
