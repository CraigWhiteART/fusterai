import React from 'react';
import { ClockIcon, StarIcon } from 'lucide-react';
import { relativeTime } from '@/Components/conversation/format';
import { Avatar, AvatarFallback, AvatarImage } from '@/Components/ui/avatar';
import { Checkbox } from '@/Components/ui/checkbox';
import { cn, getInitials } from '@/lib/utils';
import type { Conversation } from '@/types';

const priorityBar: Record<string, string> = {
    urgent: 'bg-destructive',
    high: 'bg-warning',
    normal: 'bg-transparent',
    low: 'bg-transparent',
};

export default function ConversationRow({
    conversation,
    isSelected,
    isChecked,
    onCheck,
    onClick,
    onStar,
}: {
    conversation: Conversation;
    isSelected: boolean;
    isChecked: boolean;
    onCheck: (e: React.MouseEvent) => void;
    onClick: () => void;
    onStar: (e: React.MouseEvent) => void;
}) {
    const isSnoozed = conversation.snoozed_until && new Date(conversation.snoozed_until) > new Date();

    return (
        <div
            className={cn(
                'group w-full text-left flex items-stretch transition-colors',
                isSelected ? 'bg-primary/[0.07]' : 'hover:bg-muted/40',
            )}
        >
            {/* Priority accent bar */}
            <div
                className={cn(
                    'w-[3px] shrink-0 transition-colors',
                    isSelected ? 'bg-primary' : (priorityBar[conversation.priority] ?? 'bg-transparent'),
                )}
            />

            {/* Checkbox — visible on hover or when checked */}
            <div className="flex items-center pl-2 pr-0 py-3.5" onClick={onCheck}>
                <Checkbox
                    checked={isChecked}
                    className={cn('h-3.5 w-3.5 transition-opacity', isChecked ? 'opacity-100' : 'opacity-0 group-hover:opacity-100')}
                    aria-label="Select conversation"
                />
            </div>

            <button type="button" onClick={onClick} className="flex-1 min-w-0 text-left">
                <div className="flex-1 px-3 py-3.5 min-w-0">
                    <div className="flex items-start gap-2.5">
                        <Avatar className="size-8 shrink-0 mt-0.5">
                            <AvatarImage src={conversation.customer?.avatar ?? undefined} alt={conversation.customer?.name ?? ''} />
                            <AvatarFallback className="bg-primary/10 text-primary text-xs font-semibold">
                                {getInitials(conversation.customer?.name)}
                            </AvatarFallback>
                        </Avatar>

                        <div className="flex-1 min-w-0">
                            {/* Name + time */}
                            <div className="flex items-center justify-between gap-1 mb-0.5">
                                <div className="flex items-center gap-1.5 min-w-0">
                                    {conversation.is_unread && !isSelected && (
                                        <span className="h-2 w-2 rounded-full bg-primary shrink-0" aria-label="Unread" />
                                    )}
                                    <span
                                        className={cn(
                                            'text-[13px] truncate leading-tight',
                                            conversation.is_unread && !isSelected
                                                ? 'font-bold text-foreground'
                                                : 'font-semibold text-foreground/90',
                                        )}
                                    >
                                        {conversation.customer?.name ?? 'Unknown'}
                                    </span>
                                </div>
                                <div className="flex items-center gap-1.5 shrink-0">
                                    <span className="text-[11px] text-muted-foreground/60 tabular-nums">
                                        {relativeTime(conversation.last_reply_at ?? conversation.created_at)}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={onStar}
                                        className={cn(
                                            'opacity-0 group-hover:opacity-100 transition-opacity p-0.5 rounded',
                                            conversation.starred && 'opacity-100',
                                        )}
                                        aria-label={conversation.starred ? 'Unstar' : 'Star'}
                                    >
                                        <StarIcon
                                            className={cn(
                                                'h-3.5 w-3.5 transition-colors',
                                                conversation.starred
                                                    ? 'fill-amber-400 text-amber-400'
                                                    : 'text-muted-foreground/50 hover:text-amber-400',
                                            )}
                                        />
                                    </button>
                                </div>
                            </div>

                            {/* Subject */}
                            <p
                                className={cn(
                                    'text-[12.5px] truncate mb-2 leading-snug',
                                    conversation.is_unread && !isSelected ? 'text-foreground/80 font-medium' : 'text-muted-foreground',
                                )}
                            >
                                {conversation.subject}
                            </p>

                            {/* Metadata row */}
                            <div className="flex items-center gap-1.5 flex-wrap">
                                {isSnoozed && (
                                    <span className="inline-flex items-center gap-0.5 text-[11px] text-warning font-medium px-1.5 py-0.5 rounded-md bg-warning/10">
                                        <ClockIcon className="h-2.5 w-2.5" />
                                        Snoozed
                                    </span>
                                )}

                                {(conversation.tags ?? []).slice(0, 2).map((tag) => (
                                    <span
                                        key={tag.id}
                                        className="text-[11px] px-1.5 py-0.5 rounded-md font-medium"
                                        style={{ backgroundColor: tag.color + '1f', color: tag.color }}
                                    >
                                        {tag.name}
                                    </span>
                                ))}

                                {conversation.assigned_user && (
                                    <div className="ml-auto flex items-center gap-1">
                                        <Avatar className="size-[18px]">
                                            <AvatarImage
                                                src={conversation.assigned_user.avatar ?? undefined}
                                                alt={conversation.assigned_user.name}
                                            />
                                            <AvatarFallback className="bg-muted text-muted-foreground text-[9px] font-semibold">
                                                {getInitials(conversation.assigned_user.name)}
                                            </AvatarFallback>
                                        </Avatar>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </button>
        </div>
    );
}
