import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { ClipboardCheckIcon } from 'lucide-react';
import { ErrorLine, Spinner } from '@/Components/agent/LoopParts';
import { Button } from '@/Components/ui/button';
import { agentScreenUrl, usePolling } from '@/lib/agentLoop';
import { qaApi, qaFailureClassLabel, qaHeadlineScore, requestConversationReview } from '@/lib/agentQa';
import { cn } from '@/lib/utils';
import type { QaReview } from '@/types/agentQa';

/**
 * QA in the conversation view (admins): "Review this conversation now" and a
 * compact badge on the reviewed reply. Existing reviews of the conversation
 * are loaded on mount (GET /agent/qa/reviews?conversation_id=, admins only:
 * pass `enabled = false` for everyone else).
 */
export function useConversationQa(conversationId: number, enabled = true) {
    const [reviews, setReviews] = useState<QaReview[]>([]);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const alive = useRef(true);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);

    useEffect(() => {
        if (!enabled) return;
        qaApi
            .reviews({ conversation_id: conversationId, include_dismissed: true, per_page: 20 })
            .then(
                (list) =>
                    alive.current &&
                    setReviews((prev) =>
                        [...prev, ...list.data.filter((r) => !prev.some((p) => p.id === r.id))].sort((x, y) => y.id - x.id),
                    ),
            )
            .catch(() => {});
    }, [conversationId, enabled]);

    const requestNow = useCallback(async () => {
        setBusy(true);
        setError(null);
        setMessage(null);
        const result = await requestConversationReview(conversationId);
        if (!alive.current) return;
        setBusy(false);
        if (result.ok) {
            setMessage(result.message);
            setReviews((prev) => [result.review, ...prev.filter((r) => r.id !== result.review.id)]);
        } else {
            setError(result.message);
        }
    }, [conversationId]);

    const pending = reviews.filter((r) => r.status === 'pending');
    usePolling(
        () => {
            pending.forEach((r) =>
                qaApi
                    .review(r.id)
                    .then((next) => alive.current && setReviews((prev) => prev.map((p) => (p.id === next.id ? next : p))))
                    .catch(() => {}),
            );
        },
        10_000,
        pending.length > 0,
    );

    const badges = useMemo(() => {
        const out: Record<number, ReactNode> = {};
        for (const r of reviews) {
            if (!r.reviewed_thread_id || (r.status !== 'reviewed' && r.status !== 'triaged')) continue;
            out[r.reviewed_thread_id] = <QaBadge review={r} />;
        }
        return out;
    }, [reviews]);

    return { reviews, busy, message, error, requestNow, badges };
}

/** "QA 3.2 · Missing instruction" linking to the review. */
export function QaBadge({ review }: { review: QaReview }) {
    const { value, quick } = qaHeadlineScore(review);
    const low = value !== null && value < 3.5;
    const failure = review.failure_class && review.failure_class !== 'none' ? qaFailureClassLabel(review.failure_class) : null;
    return (
        <Link
            href={agentScreenUrl('qa', { review: review.id })}
            title={quick ? 'Quick quality check score (1-5). Open the review.' : 'Quality check score (1-5). Open the review.'}
            className={cn(
                'inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[10px] font-semibold tracking-wide',
                low ? 'bg-warning/15 text-warning' : 'bg-success/10 text-success',
            )}
        >
            <ClipboardCheckIcon className="h-3 w-3" />
            QA {value !== null ? value.toFixed(1) : '—'}
            {quick ? ' (quick)' : ''}
            {failure ? ` · ${failure}` : ''}
        </Link>
    );
}

/** Side-panel block: start a forced review of the latest staff reply. */
export function QaReviewNow({ qa }: { qa: ReturnType<typeof useConversationQa> }) {
    const latest = qa.reviews[0] ?? null;
    return (
        <div className="space-y-2 border-t border-border px-4 py-3 text-xs">
            <p className="font-semibold">Quality check</p>
            <p className="text-muted-foreground">
                Score the latest reply here (AI or human) against your voice and playbooks. Usually this happens when the conversation is
                closed.
            </p>
            <Button type="button" size="sm" variant="outline" disabled={qa.busy} onClick={() => void qa.requestNow()}>
                {qa.busy ? 'Starting…' : 'Review this conversation now'}
            </Button>
            {qa.message && !qa.error && <p className="text-muted-foreground">{qa.message}</p>}
            <ErrorLine error={qa.error} />
            {latest && (
                <p className="flex items-center gap-1.5">
                    {latest.status === 'pending' ? (
                        <>
                            <Spinner /> Reviewing…
                        </>
                    ) : latest.status === 'reviewed' || latest.status === 'triaged' ? (
                        <QaBadge review={latest} />
                    ) : (
                        <span className="text-muted-foreground">Review {latest.status}.</span>
                    )}
                    <Link href={agentScreenUrl('qa', { review: latest.id })} className="text-primary hover:underline">
                        Open review
                    </Link>
                </p>
            )}
        </div>
    );
}
