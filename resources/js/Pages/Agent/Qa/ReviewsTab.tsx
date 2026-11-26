import { useEffect, useRef, useState } from 'react';
import { ClipboardCheckIcon, RefreshCwIcon } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { ErrorLine, Spinner } from '@/Components/agent/LoopParts';
import { asArray, asRecord, plural, queryParam, setQueryParam, shortDate, type ApiError } from '@/lib/agentLoop';
import {
    QA_AUTHOR_TYPE_LABELS,
    QA_FAILURE_CLASS_LABELS,
    QA_FAILURE_CLASS_ORDER,
    QA_SKIP_REASON_LABELS,
    QA_STATUS_LABELS,
    QA_VERDICT_LABELS,
    qaApi,
    qaHeadlineScore,
    qaReviewTitle,
    toQaApiError,
} from '@/lib/agentQa';
import { cn } from '@/lib/utils';
import type { PageMeta } from '@/types/agentLoop';
import type { QaPlaybookRef, QaReview } from '@/types/agentQa';
import { EmptyState, FieldError } from '../AgentParts';
import { FailureClassBadge, QA_SELECT, ScoreText, playbookName } from './qaParts';

interface Filters {
    failure_class: string;
    author_type: string;
    playbook_id: string;
    status: string;
    min: string;
    max: string;
    from: string;
    to: string;
    dismissed: boolean;
    page: number;
}

/** Filter → query-string name (kept short so shared links stay readable). */
const PARAMS: Record<keyof Filters, string> = {
    failure_class: 'class',
    author_type: 'author',
    playbook_id: 'playbook',
    status: 'status',
    min: 'min',
    max: 'max',
    from: 'from',
    to: 'to',
    dismissed: 'dismissed',
    page: 'page',
};

const EMPTY: Filters = {
    failure_class: '',
    author_type: '',
    playbook_id: '',
    status: '',
    min: '',
    max: '',
    from: '',
    to: '',
    dismissed: false,
    page: 1,
};

const STATUS_ORDER = ['reviewed', 'triaged', 'pending', 'failed', 'skipped'];

const SCORE_OPTIONS = ['1', '2', '3', '4', '5'];

function readFilters(): Filters {
    const page = parseInt(queryParam(PARAMS.page) ?? '', 10);
    const text = (key: keyof Filters) => queryParam(PARAMS[key]) ?? '';
    return {
        failure_class: text('failure_class'),
        author_type: text('author_type'),
        playbook_id: /^\d+$/.test(text('playbook_id')) ? text('playbook_id') : '',
        status: text('status'),
        min: SCORE_OPTIONS.includes(text('min')) ? text('min') : '',
        max: SCORE_OPTIONS.includes(text('max')) ? text('max') : '',
        from: text('from'),
        to: text('to'),
        dismissed: queryParam(PARAMS.dismissed) === '1',
        page: Number.isFinite(page) && page > 1 ? page : 1,
    };
}

function writeFilters(f: Filters) {
    (Object.keys(PARAMS) as (keyof Filters)[]).forEach((key) => {
        const value = f[key];
        if (key === 'dismissed') setQueryParam(PARAMS[key], value ? '1' : null);
        else if (key === 'page') setQueryParam(PARAMS[key], (value as number) > 1 ? (value as number) : null);
        else setQueryParam(PARAMS[key], (value as string) || null);
    });
}

function isFiltered(f: Filters): boolean {
    return (Object.keys(EMPTY) as (keyof Filters)[]).some((k) => k !== 'page' && f[k] !== EMPTY[k]);
}

export default function ReviewsTab({
    playbooks,
    onPlaybooks,
    onSelect,
    onError,
}: {
    playbooks: QaPlaybookRef[];
    onPlaybooks: (list: (QaPlaybookRef | null | undefined)[]) => void;
    onSelect: (id: number) => void;
    onError: (error: ApiError) => void;
}) {
    const [filters, setFilters] = useState<Filters>(readFilters);
    const [rows, setRows] = useState<QaReview[] | null>(null);
    const [meta, setMeta] = useState<PageMeta | null>(null);
    const [counts, setCounts] = useState<Record<string, number>>({});
    const [error, setError] = useState<ApiError | null>(null);
    const [loading, setLoading] = useState(false);
    const [reloadKey, setReloadKey] = useState(0);
    const requestId = useRef(0);
    const onPlaybooksRef = useRef(onPlaybooks);
    onPlaybooksRef.current = onPlaybooks;
    const onErrorRef = useRef(onError);
    onErrorRef.current = onError;

    const scoreRangeInvalid = filters.min !== '' && filters.max !== '' && Number(filters.min) > Number(filters.max);
    const dateRangeInvalid = filters.from !== '' && filters.to !== '' && filters.from > filters.to;

    useEffect(() => {
        if (scoreRangeInvalid || dateRangeInvalid) return;
        const id = ++requestId.current;
        setLoading(true);
        qaApi
            .reviews({
                failure_class: filters.failure_class || undefined,
                author_type: filters.author_type || undefined,
                playbook_id: filters.playbook_id ? Number(filters.playbook_id) : undefined,
                status: filters.status || undefined,
                min_overall: filters.min ? Number(filters.min) : undefined,
                max_overall: filters.max ? Number(filters.max) : undefined,
                from: filters.from || undefined,
                to: filters.to || undefined,
                include_dismissed: filters.dismissed,
                page: filters.page,
                per_page: 30,
            })
            .then((r) => {
                if (id !== requestId.current) return;
                const data = asArray<QaReview>(r?.data);
                setRows(data);
                setMeta(r?.meta ?? null);
                setCounts(asRecord<number>(r?.counts));
                setError(null);
                onPlaybooksRef.current(data.map((row) => row.playbook));
            })
            .catch((e) => {
                if (id !== requestId.current) return;
                const err = toQaApiError(e);
                setError(err);
                onErrorRef.current(err);
            })
            .finally(() => {
                if (id === requestId.current) setLoading(false);
            });
    }, [filters, reloadKey, scoreRangeInvalid, dateRangeInvalid]);

    function update(patch: Partial<Filters>) {
        setFilters((prev) => {
            const next = { ...prev, ...patch, page: patch.page ?? 1 };
            writeFilters(next);
            return next;
        });
    }

    const selectedPlaybookKnown = !filters.playbook_id || playbooks.some((p) => String(p.id) === filters.playbook_id);
    const total = meta?.total ?? rows?.length ?? 0;
    const statusEntries = STATUS_ORDER.filter((s) => (counts[s] ?? 0) > 0);

    return (
        <div className="space-y-4">
            {statusEntries.length > 0 && (
                <div className="flex flex-wrap items-center gap-1.5 text-xs">
                    <span className="text-muted-foreground">All replies so far:</span>
                    {statusEntries.map((s) => (
                        <button
                            key={s}
                            type="button"
                            onClick={() => update({ status: filters.status === s ? '' : s })}
                            aria-pressed={filters.status === s}
                            className={cn(
                                'rounded-full border px-2.5 py-0.5 transition-colors hover:border-primary/40',
                                filters.status === s ? 'border-primary/40 bg-primary/10 text-primary' : 'border-border text-foreground',
                            )}
                        >
                            {QA_STATUS_LABELS[s] ?? s} <span className="font-semibold tabular-nums">{counts[s]}</span>
                        </button>
                    ))}
                </div>
            )}

            <div className="grid gap-3 rounded-xl border border-border bg-card p-4 sm:grid-cols-2 lg:grid-cols-4">
                <label className="space-y-1 text-xs">
                    <span className="font-medium">What was missing</span>
                    <select
                        className={cn(QA_SELECT, 'w-full')}
                        value={filters.failure_class}
                        onChange={(e) => update({ failure_class: e.target.value })}
                    >
                        <option value="">Anything</option>
                        {QA_FAILURE_CLASS_ORDER.map((c) => (
                            <option key={c} value={c}>
                                {QA_FAILURE_CLASS_LABELS[c]}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="space-y-1 text-xs">
                    <span className="font-medium">Who wrote the reply</span>
                    <select
                        className={cn(QA_SELECT, 'w-full')}
                        value={filters.author_type}
                        onChange={(e) => update({ author_type: e.target.value })}
                    >
                        <option value="">Anyone</option>
                        {Object.entries(QA_AUTHOR_TYPE_LABELS).map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="space-y-1 text-xs">
                    <span className="font-medium">Playbook</span>
                    <select
                        className={cn(QA_SELECT, 'w-full')}
                        value={filters.playbook_id}
                        onChange={(e) => update({ playbook_id: e.target.value })}
                    >
                        <option value="">Any playbook</option>
                        {!selectedPlaybookKnown && <option value={filters.playbook_id}>Playbook #{filters.playbook_id}</option>}
                        {playbooks.map((p) => (
                            <option key={p.id} value={String(p.id)}>
                                {playbookName(p)}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="space-y-1 text-xs">
                    <span className="font-medium">Status</span>
                    <select className={cn(QA_SELECT, 'w-full')} value={filters.status} onChange={(e) => update({ status: e.target.value })}>
                        <option value="">Checked (quick check or full review)</option>
                        {STATUS_ORDER.map((s) => (
                            <option key={s} value={s}>
                                Only: {QA_STATUS_LABELS[s]}
                            </option>
                        ))}
                    </select>
                </label>
                <div className="space-y-1 text-xs">
                    <span className="font-medium">Score (1 = poor, 5 = great)</span>
                    <div className="flex items-center gap-1.5">
                        <select
                            className={cn(QA_SELECT, 'flex-1')}
                            value={filters.min}
                            aria-label="Lowest score"
                            onChange={(e) => update({ min: e.target.value })}
                        >
                            <option value="">From 1</option>
                            {SCORE_OPTIONS.map((n) => (
                                <option key={n} value={n}>
                                    From {n}
                                </option>
                            ))}
                        </select>
                        <select
                            className={cn(QA_SELECT, 'flex-1')}
                            value={filters.max}
                            aria-label="Highest score"
                            onChange={(e) => update({ max: e.target.value })}
                        >
                            <option value="">To 5</option>
                            {SCORE_OPTIONS.map((n) => (
                                <option key={n} value={n}>
                                    To {n}
                                </option>
                            ))}
                        </select>
                    </div>
                    <FieldError message={scoreRangeInvalid ? 'The lowest score is above the highest.' : undefined} />
                </div>
                <div className="space-y-1 text-xs sm:col-span-2">
                    <span className="font-medium">Reply sent between</span>
                    <div className="flex items-center gap-1.5">
                        <Input
                            type="date"
                            className="h-8 text-xs"
                            value={filters.from}
                            aria-label="From date"
                            onChange={(e) => update({ from: e.target.value })}
                        />
                        <span className="text-muted-foreground">and</span>
                        <Input
                            type="date"
                            className="h-8 text-xs"
                            value={filters.to}
                            aria-label="To date"
                            onChange={(e) => update({ to: e.target.value })}
                        />
                    </div>
                    <FieldError message={dateRangeInvalid ? 'The start date is after the end date.' : undefined} />
                </div>
                <div className="flex flex-col justify-end gap-2 text-xs">
                    <label className="flex items-center gap-2">
                        <input type="checkbox" checked={filters.dismissed} onChange={(e) => update({ dismissed: e.target.checked })} />
                        <span>Show reviews marked as wrong</span>
                    </label>
                    <div className="flex items-center gap-1.5">
                        {isFiltered(filters) && (
                            <Button size="xs" variant="ghost" onClick={() => update(EMPTY)}>
                                Clear filters
                            </Button>
                        )}
                        <Button
                            size="icon-sm"
                            variant="ghost"
                            onClick={() => setReloadKey((k) => k + 1)}
                            aria-label="Refresh"
                            title="Refresh"
                        >
                            {loading ? <Spinner /> : <RefreshCwIcon className="h-3.5 w-3.5" />}
                        </Button>
                    </div>
                </div>
            </div>

            <p className="text-xs text-muted-foreground">
                Reviews someone marked as wrong or not worth counting are hidden unless you tick the box, and they are left out of every
                average and gap count.
            </p>

            <ErrorLine error={error} />

            {rows === null ? (
                <p className="text-sm text-muted-foreground">{error ? '' : 'Loading…'}</p>
            ) : rows.length === 0 ? (
                <EmptyState icon={<ClipboardCheckIcon className="h-5 w-5 text-muted-foreground" />} title="No reviews here">
                    {isFiltered(filters)
                        ? 'Nothing matches these filters. Try clearing some of them.'
                        : 'Replies are checked a few minutes after a conversation is closed. Close a few conversations and check back here.'}
                </EmptyState>
            ) : (
                <>
                    <ul className="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card">
                        {rows.map((row) => (
                            <ReviewRow key={row.id} review={row} onSelect={onSelect} />
                        ))}
                    </ul>
                    <Pagination meta={meta} total={total} page={filters.page} onPage={(page) => update({ page })} loading={loading} />
                </>
            )}
        </div>
    );
}

function ReviewRow({ review, onSelect }: { review: QaReview; onSelect: (id: number) => void }) {
    const headline = qaHeadlineScore(review);
    const playbook = playbookName(review.playbook, review.playbook_id);
    const date = review.replied_at ?? review.created_at;
    return (
        <li>
            <button
                type="button"
                onClick={() => onSelect(review.id)}
                className="grid w-full gap-2 px-4 py-3 text-left transition-colors hover:bg-muted/50 sm:grid-cols-[minmax(0,1fr)_110px_170px_130px] sm:items-center"
            >
                <div className="min-w-0 space-y-0.5">
                    <p className="truncate text-sm font-medium">{qaReviewTitle(review)}</p>
                    <p className="truncate text-xs text-muted-foreground">
                        {QA_AUTHOR_TYPE_LABELS[review.author_type] ?? review.author_type}
                        {playbook ? ` · ${playbook}` : ' · No playbook'}
                    </p>
                </div>
                <div className="text-sm">
                    {headline.value === null ? (
                        <span className="text-muted-foreground">—</span>
                    ) : (
                        <span>
                            <ScoreText value={headline.value} />
                            <span className="text-xs text-muted-foreground"> / 5</span>
                            {headline.quick && <span className="block text-[11px] text-muted-foreground">quick check</span>}
                        </span>
                    )}
                </div>
                <div className="flex flex-wrap items-center gap-1">
                    {review.status === 'skipped' ? (
                        <span
                            className="text-xs text-muted-foreground"
                            title={(review.skip_reason && QA_SKIP_REASON_LABELS[review.skip_reason]) || ''}
                        >
                            Skipped
                        </span>
                    ) : review.status === 'failed' ? (
                        <Badge variant="destructive">{QA_STATUS_LABELS.failed}</Badge>
                    ) : review.status === 'pending' ? (
                        <Badge variant="outline">{QA_STATUS_LABELS.pending}</Badge>
                    ) : review.pass === 'full' ? (
                        <FailureClassBadge value={review.failure_class} />
                    ) : (
                        <span className="text-xs text-muted-foreground">No full review</span>
                    )}
                    {review.human_verdict && <Badge variant="outline">{QA_VERDICT_LABELS[review.human_verdict] ?? 'Marked wrong'}</Badge>}
                </div>
                <p className="text-xs text-muted-foreground sm:text-right">{shortDate(date)}</p>
            </button>
        </li>
    );
}

function Pagination({
    meta,
    total,
    page,
    onPage,
    loading,
}: {
    meta: PageMeta | null;
    total: number;
    page: number;
    onPage: (page: number) => void;
    loading: boolean;
}) {
    const current = meta?.current_page ?? page;
    const last = meta?.last_page ?? 1;
    return (
        <div className="flex items-center justify-between gap-3 text-xs text-muted-foreground">
            <span>
                {plural(total, 'review')}
                {last > 1 ? ` · page ${current} of ${last}` : ''}
            </span>
            {last > 1 && (
                <div className="flex items-center gap-1.5">
                    <Button size="sm" variant="outline" disabled={loading || current <= 1} onClick={() => onPage(current - 1)}>
                        Previous
                    </Button>
                    <Button size="sm" variant="outline" disabled={loading || current >= last} onClick={() => onPage(current + 1)}>
                        Next
                    </Button>
                </div>
            )}
        </div>
    );
}
