import { useMemo, useState } from 'react';
import { CheckCircle2Icon, FlaskConicalIcon } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import { Textarea } from '@/Components/ui/textarea';
import { ErrorLine, Spinner } from '@/Components/agent/LoopParts';
import { loopApi, toApiError, type ApiError } from '@/lib/agentLoop';
import type { TestCase } from '@/types/agentLoop';
import type { AgentRun, DetailThread } from './types';

/** "a, b" or one per line → list. */
export function splitList(value: string): string[] {
    return value
        .split(/\n|,/)
        .map((s) => s.trim())
        .filter(Boolean);
}

function preview(thread: DetailThread): string {
    const text = thread.body_plain || (thread.body ?? '').replace(/<[^>]+>/g, ' ');
    return text.replace(/\s+/g, ' ').trim().slice(0, 70);
}

/**
 * Admins: freeze this conversation at a customer message and keep it as a
 * test case with expectations (POST /agent/test-cases). Saved tests re-run
 * whenever the playbook (or, when pinned, the brand voice) changes.
 */
export default function SaveTestCase({
    conversationId,
    subject,
    threads,
    run,
}: {
    conversationId: number;
    subject: string | null | undefined;
    threads: DetailThread[];
    run: AgentRun | null;
}) {
    const customerMessages = useMemo(() => threads.filter((t) => t.type === 'message' && !!t.customer_id), [threads]);
    const defaultCut = run?.customer_thread_id ?? customerMessages[customerMessages.length - 1]?.id ?? null;

    const [open, setOpen] = useState(false);
    const [cut, setCut] = useState<number | null>(defaultCut);
    const [name, setName] = useState(subject ?? '');
    const [usePlaybook, setUsePlaybook] = useState(true);
    const [pinned, setPinned] = useState(false);
    const [mustInclude, setMustInclude] = useState('');
    const [mustNot, setMustNot] = useState('');
    const [outcome, setOutcome] = useState<'draft' | 'handover' | ''>('draft');
    const [notes, setNotes] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const [saved, setSaved] = useState<TestCase | null>(null);

    if (customerMessages.length === 0) return null;
    const playbook = run?.playbook ?? null;
    const cutId = cut ?? defaultCut;

    async function save() {
        if (!cutId || !name.trim()) return;
        setBusy(true);
        setError(null);
        try {
            setSaved(
                await loopApi.createTestCase({
                    conversation_id: conversationId,
                    cut_thread_id: cutId,
                    name: name.trim(),
                    playbook_id: usePlaybook && playbook ? playbook.id : null,
                    pinned,
                    expectation: {
                        must_include: splitList(mustInclude),
                        must_not_include: splitList(mustNot),
                        expected_outcome: outcome || null,
                        notes: notes.trim() || null,
                    },
                }),
            );
            setOpen(false);
        } catch (e) {
            setError(toApiError(e));
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="border-b border-border p-4">
            {saved && !open ? (
                <div className="space-y-1 text-xs">
                    <p className="flex items-center gap-1.5 font-medium text-success">
                        <CheckCircle2Icon className="h-3.5 w-3.5" /> Saved as a test case
                    </p>
                    <p className="text-muted-foreground">
                        “{saved.name}” re-runs whenever {saved.playbook ? `the ${saved.playbook.name} playbook` : 'the instructions'} change
                        {saved.pinned ? ' and on every brand voice change' : ''}.
                    </p>
                    {saved.playbook_id && (
                        <a href={`/agent/playbooks/${saved.playbook_id}#tests`} className="text-primary hover:underline">
                            See the playbook's tests
                        </a>
                    )}
                </div>
            ) : !open ? (
                <button
                    type="button"
                    onClick={() => setOpen(true)}
                    className="flex w-full items-center gap-2 text-left text-xs text-muted-foreground hover:text-foreground"
                >
                    <FlaskConicalIcon className="h-3.5 w-3.5" />
                    <span>Save as an agent test case</span>
                </button>
            ) : (
                <form
                    className="space-y-2.5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        void save();
                    }}
                >
                    <p className="flex items-center gap-1.5 text-xs font-semibold">
                        <FlaskConicalIcon className="h-3.5 w-3.5" /> New test case
                    </p>
                    <p className="text-[11px] text-muted-foreground">
                        The agent will be re-tested on this conversation, as it stood at the message below, whenever its instructions
                        change.
                    </p>
                    <div className="space-y-1">
                        <Label htmlFor="tc-cut" className="text-xs">
                            Answer this customer message
                        </Label>
                        <select
                            id="tc-cut"
                            value={cutId ?? ''}
                            onChange={(e) => setCut(Number(e.target.value))}
                            className="h-8 w-full rounded-md border border-input bg-background px-2 text-xs"
                        >
                            {customerMessages.map((t) => (
                                <option key={t.id} value={t.id}>
                                    {new Date(t.created_at).toLocaleDateString()} · {preview(t) || `Message ${t.id}`}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="tc-name" className="text-xs">
                            Name
                        </Label>
                        <Input
                            id="tc-name"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            maxLength={255}
                            className="h-8 text-xs"
                            required
                        />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="tc-in" className="text-xs">
                            The reply must mention
                        </Label>
                        <Textarea
                            id="tc-in"
                            value={mustInclude}
                            onChange={(e) => setMustInclude(e.target.value)}
                            rows={2}
                            placeholder="One per line, e.g. Australia Post"
                            className="text-xs"
                        />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="tc-out" className="text-xs">
                            The reply must never say
                        </Label>
                        <Textarea
                            id="tc-out"
                            value={mustNot}
                            onChange={(e) => setMustNot(e.target.value)}
                            rows={2}
                            placeholder="One per line, e.g. guaranteed delivery"
                            className="text-xs"
                        />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="tc-outcome" className="text-xs">
                            The agent should
                        </Label>
                        <select
                            id="tc-outcome"
                            value={outcome}
                            onChange={(e) => setOutcome(e.target.value as 'draft' | 'handover' | '')}
                            className="h-8 w-full rounded-md border border-input bg-background px-2 text-xs"
                        >
                            <option value="draft">Write a reply</option>
                            <option value="handover">Hand it to a person</option>
                            <option value="">Either is fine</option>
                        </select>
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="tc-notes" className="text-xs">
                            Notes for the judge (optional)
                        </Label>
                        <Input
                            id="tc-notes"
                            value={notes}
                            onChange={(e) => setNotes(e.target.value)}
                            placeholder="e.g. Never promise a delivery date"
                            className="h-8 text-xs"
                        />
                    </div>
                    {playbook && (
                        <label className="flex items-center justify-between gap-2 text-xs">
                            <span>Belongs to the {playbook.name} playbook</span>
                            <Switch checked={usePlaybook} onCheckedChange={setUsePlaybook} />
                        </label>
                    )}
                    <label className="flex items-center justify-between gap-2 text-xs">
                        <span>
                            Core test
                            <span className="block text-[10px] text-muted-foreground">Also run on every brand voice change</span>
                        </span>
                        <Switch checked={pinned} onCheckedChange={setPinned} />
                    </label>
                    <div className="flex gap-1.5">
                        <Button type="submit" size="sm" className="h-7 text-xs" disabled={busy || !name.trim() || !cutId}>
                            {busy ? <Spinner className="h-3 w-3" /> : null} Save test case
                        </Button>
                        <Button type="button" size="sm" variant="ghost" className="h-7 text-xs" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                    </div>
                    <ErrorLine error={error} />
                </form>
            )}
        </div>
    );
}
