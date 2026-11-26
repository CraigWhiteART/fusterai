import { useState } from 'react';
import { DatabaseIcon, PackageSearchIcon } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import { Disclosure, ErrorLine, EvidenceBadge, Spinner } from '@/Components/agent/LoopParts';
import { EVIDENCE_HELP, loopApi, plural, useAction, useWorkspaceEvents } from '@/lib/agentLoop';
import type { CasesPreparedEvent, EvidenceQuality } from '@/types/agentLoop';
import type { FrozenCaseIndex } from './simulationShared';

const QUALITIES: EvidenceQuality[] = ['recorded', 'reconstructed', 'none'];

function describePrepared(e: CasesPreparedEvent): string {
    const bits: string[] = [];
    if (typeof e.cases === 'number') bits.push(`${plural(e.cases, 'case')} saved`);
    if (typeof e.conversations === 'number') bits.push(`from ${plural(e.conversations, 'conversation')}`);
    if (typeof e.recorded === 'number' && e.recorded > 0) bits.push(`${e.recorded} recorded`);
    if (typeof e.reconstructed === 'number' && e.reconstructed > 0) bits.push(`${e.reconstructed} reconstructed`);
    if (typeof e.none === 'number' && e.none > 0) bits.push(`${e.none} with no order data found`);
    return bits.length ? `Finished: ${bits.join(', ')}.` : 'Finished.';
}

/**
 * Practice cases ("frozen cases"): past conversations cut at a customer
 * message, with the human reply as the answer key. Build (database only)
 * and prepare (rate-limited Shopify / carrier look-ups) run in the background.
 */
export default function CasesPanel({ index }: { index: FrozenCaseIndex }) {
    const [build, setBuild] = useState({ date_from: '', date_to: '', imported_only: true, limit: '200', per_conversation: '3' });
    const [message, setMessage] = useState<string | null>(null);
    const [finished, setFinished] = useState<string | null>(null);
    const { busy, error, run } = useAction();

    useWorkspaceEvents({
        casesPrepared: (e) => {
            if (e.finished === false) return;
            setFinished(describePrepared(e));
            setMessage(null);
            void index.reload();
        },
    });

    async function doBuild() {
        setFinished(null);
        const r = await run('build', () =>
            loopApi.buildFrozenCases({
                date_from: build.date_from || undefined,
                date_to: build.date_to || undefined,
                imported_only: build.imported_only,
                limit: parseInt(build.limit, 10) || undefined,
                per_conversation: parseInt(build.per_conversation, 10) || undefined,
            }),
        );
        if (r) setMessage('Building practice cases in the background. This page updates when it is done.');
    }

    async function doPrepare() {
        setFinished(null);
        const r = await run('prepare', () => loopApi.prepareFrozenCases());
        if (r) {
            const cases = typeof r.cases === 'number' ? r.cases : index.unprepared;
            setMessage(
                cases > 0
                    ? `Preparing ${plural(cases, 'case')}${r.per_minute ? ` at ${r.per_minute} per minute` : ''}. This page updates when it is done.`
                    : 'Nothing to prepare: every case already has its order details (or was already tried).',
            );
        }
    }

    const counts = index.counts;
    return (
        <div className="space-y-3">
            <div className="flex flex-wrap gap-2">
                {QUALITIES.map((q) => (
                    <div
                        key={q}
                        className="flex items-center gap-1.5 rounded-md border border-border px-2 py-1 text-xs"
                        title={EVIDENCE_HELP[q]}
                    >
                        <EvidenceBadge quality={q} />
                        <span className="font-semibold tabular-nums">{counts[q] ?? 0}</span>
                    </div>
                ))}
                {index.loading && <Spinner className="text-muted-foreground" />}
            </div>
            <p className="text-xs text-muted-foreground">
                {index.total > 0 ? `${plural(index.total, 'practice case')} in total. ` : 'No practice cases yet. '}
                {index.unprepared > 0 ? `${index.unprepared} still need their order details looked up.` : ''}
            </p>

            <div className="space-y-1.5">
                <div className="flex flex-wrap gap-2">
                    <Button size="sm" variant="outline" onClick={() => void doPrepare()} disabled={busy !== null || index.unprepared === 0}>
                        {busy === 'prepare' ? <Spinner /> : <PackageSearchIcon className="h-3.5 w-3.5" />}
                        Prepare evidence{index.unprepared > 0 ? ` (${index.unprepared})` : ''}
                    </Button>
                </div>
                <p className="text-[11px] text-muted-foreground">
                    Looks each old order up in Shopify and the carrier and rebuilds it as it was on the day, so replies can be fact-checked.
                    It runs slowly on purpose so the store is not overloaded.
                </p>
            </div>

            <Disclosure title="Build more practice cases from history">
                <form
                    className="space-y-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        void doBuild();
                    }}
                >
                    <p className="text-[11px] text-muted-foreground">
                        Picks past conversations a person answered and saves them as practice cases. It only reads your own database: free
                        and does not contact Shopify.
                    </p>
                    <div className="grid gap-2 sm:grid-cols-2">
                        <div className="space-y-1">
                            <Label htmlFor="build-from">From</Label>
                            <Input
                                id="build-from"
                                type="date"
                                value={build.date_from}
                                onChange={(e) => setBuild({ ...build, date_from: e.target.value })}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="build-to">To</Label>
                            <Input
                                id="build-to"
                                type="date"
                                value={build.date_to}
                                onChange={(e) => setBuild({ ...build, date_to: e.target.value })}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="build-limit">Conversations (max)</Label>
                            <Input
                                id="build-limit"
                                type="number"
                                min={1}
                                max={5000}
                                value={build.limit}
                                onChange={(e) => setBuild({ ...build, limit: e.target.value })}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="build-per">Cases per conversation</Label>
                            <Input
                                id="build-per"
                                type="number"
                                min={1}
                                max={20}
                                value={build.per_conversation}
                                onChange={(e) => setBuild({ ...build, per_conversation: e.target.value })}
                            />
                        </div>
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <Switch checked={build.imported_only} onCheckedChange={(v) => setBuild({ ...build, imported_only: v })} />
                        Only imported history
                    </label>
                    <Button type="submit" size="sm" variant="outline" disabled={busy !== null}>
                        {busy === 'build' ? <Spinner /> : <DatabaseIcon className="h-3.5 w-3.5" />}
                        Build cases
                    </Button>
                </form>
            </Disclosure>

            {message && <p className="text-xs text-info">{message}</p>}
            {finished && <p className="text-xs text-success">{finished}</p>}
            <ErrorLine error={error} />
        </div>
    );
}
