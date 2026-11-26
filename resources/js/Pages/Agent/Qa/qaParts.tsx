import { ExternalLinkIcon } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { conversationUrl, plural } from '@/lib/agentLoop';
import { QA_FAILURE_CLASS_HELP, formatScore, qaFailureClassLabel, qaFailureClassVariant, scoreTone } from '@/lib/agentQa';
import { cn } from '@/lib/utils';
import type { QaPlaybookRef } from '@/types/agentQa';

/** Native <select> styled like the shadcn inputs. */
export const QA_SELECT = 'h-8 rounded-md border border-input bg-background px-2 text-xs';

export function FailureClassBadge({ value }: { value: string | null | undefined }) {
    return (
        <Badge variant={qaFailureClassVariant(value)} title={(value && QA_FAILURE_CLASS_HELP[value]) || undefined}>
            {qaFailureClassLabel(value)}
        </Badge>
    );
}

const TONE_TEXT = {
    good: 'text-success',
    ok: 'text-warning',
    bad: 'text-destructive',
    none: 'text-muted-foreground',
} as const;

const TONE_DOT = {
    good: 'bg-success',
    ok: 'bg-warning',
    bad: 'bg-destructive',
    none: 'bg-muted-foreground',
} as const;

/** A score out of 5 as text, coloured by how good it is. */
export function ScoreText({ value, className }: { value: number | null | undefined; className?: string }) {
    return <span className={cn('font-semibold tabular-nums', TONE_TEXT[scoreTone(value)], className)}>{formatScore(value)}</span>;
}

/** Five dots, filled up to the score. null → "not judged". */
export function ScoreDots({ value, label }: { value: number | null | undefined; label: string }) {
    if (typeof value !== 'number') {
        return <span className="text-xs italic text-muted-foreground">not judged</span>;
    }
    const filled = Math.max(0, Math.min(5, Math.round(value)));
    const tone = scoreTone(value);
    return (
        <span className="inline-flex items-center gap-2" role="img" aria-label={`${label}: ${formatScore(value)} out of 5`}>
            <span className="inline-flex gap-1">
                {[1, 2, 3, 4, 5].map((n) => (
                    <span key={n} className={cn('h-2.5 w-2.5 rounded-full', n <= filled ? TONE_DOT[tone] : 'bg-muted')} />
                ))}
            </span>
            <span className="w-6 text-xs tabular-nums text-muted-foreground">{formatScore(value)}</span>
        </span>
    );
}

export function playbookName(playbook: QaPlaybookRef | null | undefined, id?: number | null): string | null {
    if (playbook) return playbook.name?.trim() || playbook.slug.replace(/_/g, ' ');
    return typeof id === 'number' ? `Playbook #${id}` : null;
}

export function ConversationLinks({ ids, label = 'Conversations', max = 6 }: { ids: number[]; label?: string; max?: number }) {
    const list = ids.filter((id) => typeof id === 'number' && Number.isFinite(id));
    if (list.length === 0) return null;
    const shown = list.slice(0, max);
    return (
        <p className="flex flex-wrap items-center gap-1 text-[11px] text-muted-foreground">
            <span>
                {label} ({plural(list.length, 'conversation')}):
            </span>
            {shown.map((id) => (
                <a
                    key={id}
                    href={conversationUrl(id)}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-0.5 rounded border border-border px-1 hover:border-primary/40 hover:text-primary"
                >
                    #{id} <ExternalLinkIcon className="h-2.5 w-2.5" />
                </a>
            ))}
            {list.length > shown.length && <span>+{list.length - shown.length} more</span>}
        </p>
    );
}
