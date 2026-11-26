import React from 'react';
import { Link } from '@inertiajs/react';
import { BookOpenIcon, PlusIcon } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';
import { AgentPage, EmptyState } from '../AgentParts';
import type { PlaybookMode, PlaybookSummary } from '@/types/agent';

interface Props {
    playbooks: PlaybookSummary[];
}

const MODE_LABEL: Record<PlaybookMode, { label: string; className: string }> = {
    draft: { label: 'Drafts', className: 'bg-success/10 text-success' },
    off: { label: 'Off', className: 'bg-muted text-muted-foreground' },
    auto: { label: 'Auto', className: 'bg-primary/10 text-primary' },
};

export default function PlaybooksIndex({ playbooks }: Props) {
    // Live playbooks first, archived ones (kept for history) at the end.
    const active = [...playbooks.filter((p) => !p.archived_at), ...playbooks.filter((p) => p.archived_at)];
    const newButton = (
        <Link href="/agent/playbooks/create">
            <Button>
                <PlusIcon className="mr-1 h-4 w-4" /> New playbook
            </Button>
        </Link>
    );

    return (
        <AgentPage
            title="Playbooks"
            current="/agent/playbooks"
            width="max-w-5xl"
            description="Plain-English instructions for each kind of email. The agent only drafts when one of these fits; anything else goes to a person."
            actions={active.length > 0 ? newButton : undefined}
        >
            {active.length === 0 ? (
                <EmptyState icon={<BookOpenIcon className="h-5 w-5 text-muted-foreground" />} title="No playbooks yet" action={newButton}>
                    Start with your most common email, such as “Where is my order?”, and describe when it applies and how to answer.
                </EmptyState>
            ) : (
                <div className="overflow-hidden rounded-xl border border-border bg-card">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/40 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th className="px-4 py-3">Playbook</th>
                                <th className="px-4 py-3">Applies when</th>
                                <th className="px-4 py-3">Status</th>
                                <th className="px-4 py-3 text-right">Rules</th>
                                <th className="px-4 py-3 text-right">Version</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border">
                            {active.map((playbook) => {
                                const mode = MODE_LABEL[playbook.mode] ?? MODE_LABEL.off;
                                return (
                                    <tr
                                        key={playbook.id}
                                        className={cn('align-top hover:bg-muted/30', playbook.archived_at && 'opacity-60')}
                                    >
                                        <td className="px-4 py-3">
                                            <Link
                                                href={`/agent/playbooks/${playbook.id}`}
                                                className="font-medium text-primary hover:underline"
                                            >
                                                {playbook.name}
                                            </Link>
                                            <p className="text-xs text-muted-foreground">{playbook.slug}</p>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            <span className="line-clamp-2">{playbook.when_text || '—'}</span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className={cn('rounded-full px-2 py-0.5 text-[11px] font-semibold', mode.className)}>
                                                {playbook.archived_at ? 'Archived' : playbook.always_human ? 'Always a person' : mode.label}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">{playbook.rule_count ?? 0}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">{playbook.live_version ?? '—'}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
        </AgentPage>
    );
}
