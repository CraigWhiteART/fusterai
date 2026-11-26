import { CheckCircle2Icon, CircleAlertIcon, CircleIcon, ShieldAlertIcon } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { cn } from '@/lib/utils';
import { formatDate } from '../AgentParts';
import type { PlaybookAutoSend } from '@/types/agent';

/** "About N of the last 100 matching conversations would have been in the rollout." */
export function rolloutPreviewText(preview: PlaybookAutoSend['rollout_preview'] | undefined, percent: number): string {
    if (!preview || preview.sample === 0) return 'No conversations have matched this playbook yet, so there is nothing to preview.';
    const inside = preview.buckets.filter((b) => b < Math.max(0, Math.min(100, percent))).length;
    return `About ${inside} of the last ${preview.sample} matching conversations would have been in the rollout.`;
}

const HISTORY_TEXT: Record<string, string> = {
    playbook_auto_enabled: 'Auto enabled',
    playbook_auto_disabled: 'Auto switched off',
    playbook_demoted: 'Switched back to draft',
    playbook_rollout_changed: 'Rollout changed',
    playbook_retest_started: 'Re-test started',
    playbook_retest_passed: 'Re-test passed',
};

/** Precondition checklist, circuit-breaker readings and history for automatic sending. */
export function AutoSendPanel({ autoSend, mode }: { autoSend: PlaybookAutoSend; mode: string }) {
    const { preconditions, breakers } = autoSend;

    return (
        <div className="space-y-4 rounded-lg border border-border p-3">
            {autoSend.demoted_at && mode !== 'auto' && (
                <div className="flex items-start gap-2 rounded-md border border-warning/40 bg-warning/10 p-2 text-xs">
                    <ShieldAlertIcon className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    <span>
                        Automatic sending was switched off on {formatDate(autoSend.demoted_at)}: {autoSend.demotion_reason}. It stays off
                        until someone switches it back on here.
                    </span>
                </div>
            )}
            {autoSend.auto_hold === 'retesting' && (
                <div className="flex items-start gap-2 rounded-md border border-info/40 bg-info/10 p-2 text-xs">
                    <CircleAlertIcon className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    <span>
                        The instructions changed, so the tests are running again. Until they pass, replies are drafts. If they get worse,
                        the playbook goes back to Draft.
                    </span>
                </div>
            )}

            <div className="space-y-1.5">
                <p className="text-sm font-medium">Before automatic sending can be switched on</p>
                <ul className="space-y-1">
                    {preconditions.items.map((item) => (
                        <li key={item.key} className="flex items-start gap-2 text-xs">
                            {item.ok ? (
                                <CheckCircle2Icon className="mt-0.5 h-3.5 w-3.5 shrink-0 text-success" />
                            ) : item.blocking ? (
                                <CircleIcon className="mt-0.5 h-3.5 w-3.5 shrink-0 text-destructive" />
                            ) : (
                                <CircleAlertIcon className="mt-0.5 h-3.5 w-3.5 shrink-0 text-warning" />
                            )}
                            <span>
                                <span className="font-medium">{item.label}</span>
                                <span className="text-muted-foreground"> · {item.detail}</span>
                                {!item.blocking && <span className="text-muted-foreground"> (not required to switch on)</span>}
                            </span>
                        </li>
                    ))}
                </ul>
            </div>

            <div className="space-y-1.5">
                <p className="text-sm font-medium">
                    Safety switches{' '}
                    <span className="text-xs font-normal text-muted-foreground">
                        (last {breakers.window_hours} hours, {breakers.auto_sent} sent automatically)
                    </span>
                </p>
                <p className="text-xs text-muted-foreground">
                    If any of these goes over its limit, the playbook is switched back to Draft and admins are told. It is never switched
                    back on automatically.
                </p>
                <ul className="space-y-1">
                    {Object.entries(breakers.breakers)
                        .filter(([, b]) => b.available !== false)
                        .map(([key, b]) => (
                            <li key={key} className={cn('text-xs', b.tripped && 'text-destructive')}>
                                <span className="font-medium">{b.label}:</span>{' '}
                                <span className="text-muted-foreground">{b.explanation}</span>
                                {b.sample < b.min_sample && <span className="text-muted-foreground"> Not enough data yet.</span>}
                            </li>
                        ))}
                </ul>
            </div>

            {autoSend.history.length > 0 && (
                <details>
                    <summary className="cursor-pointer text-sm font-medium">History</summary>
                    <ul className="mt-1.5 space-y-1">
                        {autoSend.history.map((h) => (
                            <li key={h.id} className="text-xs">
                                <span className="text-muted-foreground">{formatDate(h.created_at)}</span>{' '}
                                <span className="font-medium">{(h.event && HISTORY_TEXT[h.event]) ?? h.event}</span>
                                <span className="text-muted-foreground"> · {h.description}</span>
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </div>
    );
}

/** Explicit confirmation before a playbook may send without review. */
export function ConfirmAutoDialog({
    open,
    name,
    percent,
    preview,
    processing,
    onConfirm,
    onCancel,
}: {
    open: boolean;
    name: string;
    percent: number;
    preview: PlaybookAutoSend['rollout_preview'] | undefined;
    processing: boolean;
    onConfirm: () => void;
    onCancel: () => void;
}) {
    return (
        <Dialog open={open} onOpenChange={(v) => !v && onCancel()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Send “{name}” replies automatically?</DialogTitle>
                    <DialogDescription>
                        For {percent}% of matching conversations, a reply that passes every check will be emailed to the customer without a
                        person reading it first, after the waiting period set on the Agent settings page. An email that has gone out cannot
                        be recalled.
                    </DialogDescription>
                </DialogHeader>
                <p className="text-sm text-muted-foreground">{rolloutPreviewText(preview, percent)}</p>
                <p className="text-sm text-muted-foreground">
                    Anything the agent is unsure about, angry or risky emails, and replies that need a Shopify action stay drafts. Start low
                    (around 10%) and raise it once you have watched the results.
                </p>
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onCancel}>
                        Keep as draft
                    </Button>
                    <Button type="button" onClick={onConfirm} disabled={processing}>
                        {processing ? 'Switching on…' : 'Yes, send automatically'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
