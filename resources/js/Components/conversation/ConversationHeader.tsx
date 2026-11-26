import {
    CheckCircleIcon,
    ChevronDownIcon,
    ClockIcon,
    ExternalLinkIcon,
    EyeIcon,
    MailIcon,
    PanelRightCloseIcon,
    PanelRightOpenIcon,
} from 'lucide-react';
import SlotRenderer from '@/Components/SlotRenderer';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/Components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type { DetailConversation } from './types';
import type { Viewer } from './useConversationChannel';

const priorityConfig = {
    low: { label: 'Low', color: 'bg-muted text-muted-foreground' },
    normal: { label: 'Normal', color: 'bg-info/10 text-info' },
    high: { label: 'High', color: 'bg-warning/15 text-warning' },
    urgent: { label: 'Urgent', color: 'bg-destructive/10 text-destructive' },
} as const;

const SNOOZE_OPTIONS = [
    { label: '1 hour', mins: 60 },
    { label: '3 hours', mins: 180 },
    { label: 'Tomorrow', mins: 60 * 24 },
    { label: '3 days', mins: 60 * 24 * 3 },
    { label: '1 week', mins: 60 * 24 * 7 },
];

const iconButton =
    'inline-flex h-8 w-8 items-center justify-center rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted/60 transition-colors';

interface Props {
    conversation: DetailConversation;
    isChat: boolean;
    /** Other people with this conversation open (presence). */
    viewers: Viewer[];
    inspectorOpen: boolean;
    /** Show the "open in new tab" link (split inbox only). */
    showOpenLink: boolean;
    onStatus: (status: string) => void;
    onSnooze: (minutes: number) => void;
    onMarkUnread: () => void;
    onToggleInspector: () => void;
    onOpenMobileInspector: () => void;
}

export default function ConversationHeader({
    conversation,
    isChat,
    viewers,
    inspectorOpen,
    showOpenLink,
    onStatus,
    onSnooze,
    onMarkUnread,
    onToggleInspector,
    onOpenMobileInspector,
}: Props) {
    const snoozed = conversation.snoozed_until && new Date(conversation.snoozed_until) > new Date();
    const title = isChat ? (conversation.customer?.name ?? 'Unknown') : conversation.subject;

    return (
        <div className="flex items-start gap-3 px-5 py-3 border-b border-border bg-background shrink-0">
            <div className="flex-1 min-w-0 pt-0.5">
                <div className="flex items-center gap-2">
                    <span className="text-[11px] font-mono text-muted-foreground/50 bg-muted/60 px-1.5 py-0.5 rounded shrink-0">
                        #{conversation.id}
                    </span>
                    <h2 className="text-sm font-semibold leading-snug line-clamp-2">{title}</h2>
                </div>
                <div className="flex items-center gap-1.5 flex-wrap mt-1">
                    <span className="text-[11px] text-muted-foreground/70">{conversation.mailbox?.name}</span>
                    <span className="text-muted-foreground/30 text-[10px]">·</span>
                    <span className={cn('text-[11px] px-1.5 py-0.5 rounded-md font-medium', priorityConfig[conversation.priority]?.color)}>
                        {priorityConfig[conversation.priority]?.label}
                    </span>
                    <Badge variant={conversation.status === 'open' ? 'default' : 'secondary'} className="capitalize text-[11px] h-5 px-1.5">
                        {conversation.status}
                    </Badge>
                    {snoozed && (
                        <span className="inline-flex items-center gap-1 text-[11px] text-warning bg-warning/10 px-1.5 py-0.5 rounded-md">
                            <ClockIcon className="h-2.5 w-2.5" />
                            Snoozed until{' '}
                            {new Date(conversation.snoozed_until!).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' })}
                        </span>
                    )}
                    {viewers.length > 0 && (
                        <span className="flex items-center gap-1 text-[11px] text-warning bg-warning/10 px-1.5 py-0.5 rounded-md">
                            <EyeIcon className="h-2.5 w-2.5" />
                            {viewers.map((v) => v.name).join(', ')} viewing
                        </span>
                    )}
                </div>
            </div>

            <div className="flex items-center gap-1 md:gap-1.5 shrink-0 mt-0.5 flex-wrap justify-end">
                <SlotRenderer name="conversation.header.actions" props={{ conversation }} />
                {conversation.status !== 'closed' && (
                    <Button size="sm" variant="outline" className="h-8 text-xs" onClick={() => onStatus('closed')}>
                        <CheckCircleIcon className="h-3.5 w-3.5 mr-1" /> Close
                    </Button>
                )}
                {conversation.status !== 'open' && (
                    <Button size="sm" variant="outline" className="h-8 text-xs" onClick={() => onStatus('open')}>
                        Reopen
                    </Button>
                )}
                {conversation.status !== 'pending' && (
                    <Button
                        size="sm"
                        variant="ghost"
                        className="h-8 text-xs text-muted-foreground hover:text-foreground"
                        onClick={() => onStatus('pending')}
                    >
                        <ClockIcon className="h-3.5 w-3.5 mr-1" /> Pending
                    </Button>
                )}
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button size="sm" variant="ghost" className="h-8 text-xs text-muted-foreground hover:text-foreground">
                            <ClockIcon className="h-3.5 w-3.5 mr-1" /> Snooze
                            <ChevronDownIcon className="h-3 w-3 ml-0.5" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-40">
                        {SNOOZE_OPTIONS.map(({ label, mins }) => (
                            <DropdownMenuItem key={label} onClick={() => onSnooze(mins)} className="text-xs">
                                {label}
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
                <button type="button" onClick={onMarkUnread} title="Mark unread and close" className={iconButton}>
                    <MailIcon className="h-4 w-4" />
                </button>
                <button
                    type="button"
                    onClick={onToggleInspector}
                    title={inspectorOpen ? 'Hide details panel' : 'Show details panel'}
                    className={cn(iconButton, 'hidden md:inline-flex')}
                >
                    {inspectorOpen ? <PanelRightCloseIcon className="h-4 w-4" /> : <PanelRightOpenIcon className="h-4 w-4" />}
                </button>
                <button type="button" onClick={onOpenMobileInspector} title="Open details" className={cn(iconButton, 'md:hidden')}>
                    <PanelRightOpenIcon className="h-4 w-4" />
                </button>
                {showOpenLink && (
                    <a
                        href={`/conversations/${conversation.id}`}
                        target="_blank"
                        rel="noreferrer"
                        title="Open conversation in new tab"
                        className={iconButton}
                    >
                        <ExternalLinkIcon className="h-4 w-4" />
                    </a>
                )}
            </div>
        </div>
    );
}
