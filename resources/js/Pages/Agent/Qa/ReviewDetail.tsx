import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowLeftIcon, ExternalLinkIcon, RefreshCwIcon } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Textarea } from '@/Components/ui/textarea';
import { Disclosure, ErrorLine, ProgressBar, Spinner } from '@/Components/agent/LoopParts';
import { asArray, conversationUrl, microToUsd, proposalUrl, shortDate, usePolling, usd, useAction, type ApiError } from '@/lib/agentLoop';
import {
    QA_AUTHOR_TYPE_LABELS,
    QA_EVIDENCE_SOURCE_HELP,
    QA_EVIDENCE_SOURCE_LABELS,
    QA_FAILURE_CLASS_HELP,
    QA_GAP_STATUS_HELP,
    QA_GAP_STATUS_LABELS,
    QA_SCORE_LABELS,
    QA_SCORE_ORDER,
    QA_SKIP_REASON_LABELS,
    QA_STATUS_LABELS,
    QA_VERDICT_HELP,
    QA_VERDICT_LABELS,
    gapProgressText,
    qaApi,
    qaReviewTitle,
    qaScores,
    qaSelectionText,
    qaTriage,
    toQaApiError,
    withQaReason,
} from '@/lib/agentQa';
import { cn } from '@/lib/utils';
import type { QaHumanVerdict, QaReview, QaReviewDetail } from '@/types/agentQa';
import { Notice, Section } from '../AgentParts';
import { FailureClassBadge, ScoreDots, ScoreText, playbookName } from './qaParts';

const VERDICT_FIELDS = ['human_verdict', 'human_note', 'human_by', 'human_at', 'gap_id', 'proposal_id'] as const;

export default function ReviewDetail({
    reviewId,
    threshold,
    onBack,
    onOpenTab,
    onError,
}: {
    reviewId: number;
    threshold: number;
    onBack: () => void;
    onOpenTab: (tab: 'gaps' | 'capabilities') => void;
    onError: (error: ApiError) => void;
}) {
    const [review, setReview] = useState<QaReviewDetail | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [loading, setLoading] = useState(false);
    const onErrorRef = useRef(onError);
    onErrorRef.current = onError;

    const load = useCallback(async () => {
        setLoading(true);
        try {
            setReview(await qaApi.review(reviewId));
            setError(null);
        } catch (e) {
            const err = toQaApiError(e);
            setError(err);
            onErrorRef.current(err);
        } finally {
            setLoading(false);
        }
    }, [reviewId]);

    useEffect(() => {
        void load();
    }, [load]);

    // A queued review finishes in the background: check again every 15 seconds.
    usePolling(() => void load(), 15_000, review?.status === 'pending');

    /** The verdict endpoint answers without the detail fields: merge what changed, then reload in the background. */
    function verdictSaved(updated: QaReview) {
        setReview((prev) => {
            if (!prev) return prev;
            const next: QaReviewDetail = { ...prev };
            VERDICT_FIELDS.forEach((key) => {
                (next as unknown as Record<string, unknown>)[key] = updated[key];
            });
            return next;
        });
        void load();
    }

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between gap-2">
                <Button variant="ghost" size="sm" onClick={onBack}>
                    <ArrowLeftIcon className="mr-1 h-3.5 w-3.5" /> All reviews
                </Button>
                <Button size="icon-sm" variant="ghost" onClick={() => void load()} aria-label="Refresh" title="Refresh">
                    {loading ? <Spinner /> : <RefreshCwIcon className="h-3.5 w-3.5" />}
                </Button>
            </div>

            <ErrorLine error={error} />
            {review === null ? (
                !error && <p className="text-sm text-muted-foreground">Loading…</p>
            ) : (
                <ReviewBody review={review} threshold={threshold} onOpenTab={onOpenTab} onVerdict={verdictSaved} />
            )}
        </div>
    );
}

function ReviewBody({
    review,
    threshold,
    onOpenTab,
    onVerdict,
}: {
    review: QaReviewDetail;
    threshold: number;
    onOpenTab: (tab: 'gaps' | 'capabilities') => void;
    onVerdict: (review: QaReview) => void;
}) {
    const scores = qaScores(review);
    const triage = qaTriage(review);
    const playbook = playbookName(review.playbook, review.playbook_id);
    const authorLabel = QA_AUTHOR_TYPE_LABELS[review.author_type] ?? review.author_type;
    const proposalId = review.proposal_id ?? review.gap_group?.proposal_id ?? null;
    const evidence = review.evidence_source ?? 'none';
    const full = review.pass === 'full';

    return (
        <div className="space-y-4">
            <div className="space-y-1.5">
                <h2 className="text-lg font-semibold">{qaReviewTitle(review)}</h2>
                <div className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                    <Badge variant="outline">{authorLabel}</Badge>
                    <span>{playbook ?? 'No playbook'}</span>
                    {review.replied_at && <span>· replied {shortDate(review.replied_at)}</span>}
                    <span>· {QA_STATUS_LABELS[review.status] ?? review.status}</span>
                    <a
                        href={conversationUrl(review.conversation_id)}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-0.5 text-primary hover:underline"
                    >
                        · Open the conversation <ExternalLinkIcon className="h-3 w-3" />
                    </a>
                </div>
                <p className="text-xs text-muted-foreground">{qaSelectionText(review)}</p>
            </div>

            {review.human_verdict && (
                <Notice tone="warning">
                    <p>
                        Marked as <strong>{(QA_VERDICT_LABELS[review.human_verdict] ?? review.human_verdict).toLowerCase()}</strong>
                        {review.human_at ? ` on ${shortDate(review.human_at)}` : ''}. This review is left out of every average and gap
                        count.
                    </p>
                    {review.human_note && <p className="mt-1 text-xs">“{review.human_note}”</p>}
                </Notice>
            )}
            {review.status === 'pending' && (
                <Notice>The check is queued and usually finishes within a few minutes. This page refreshes by itself.</Notice>
            )}
            {review.status === 'failed' && (
                <Notice tone="danger">This reply could not be checked.{review.error ? ` (${review.error})` : ''}</Notice>
            )}
            {review.status === 'skipped' && (
                <Notice>
                    Skipped: {(review.skip_reason && QA_SKIP_REASON_LABELS[review.skip_reason]) || review.skip_reason || 'no reason given.'}
                </Notice>
            )}

            <div className="grid gap-4 lg:grid-cols-2">
                <MessageBox title="What the customer wrote" body={review.customer_message} />
                <MessageBox title={`The reply that was checked (${authorLabel.toLowerCase()})`} body={review.reviewed_reply} />
            </div>

            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <Section title="Scores" description="1 = poor, 5 = great.">
                    {full ? (
                        <div className="space-y-3">
                            <div className="flex items-baseline gap-2">
                                <span className="text-xs text-muted-foreground">Overall</span>
                                <ScoreText value={review.overall} className="text-2xl" />
                                <span className="text-xs text-muted-foreground">/ 5</span>
                            </div>
                            <ul className="space-y-1.5">
                                {QA_SCORE_ORDER.map((key) => (
                                    <li key={key} className="flex items-center justify-between gap-3 text-sm">
                                        <span>{QA_SCORE_LABELS[key]}</span>
                                        <ScoreDots value={scores[key] ?? null} label={QA_SCORE_LABELS[key]} />
                                    </li>
                                ))}
                            </ul>
                            {scores.factual_grounding == null && (
                                <p className="text-[11px] text-muted-foreground">
                                    Facts were not judged because there were no order details to check them against. The overall score is
                                    the average of the other four.
                                </p>
                            )}
                        </div>
                    ) : review.pass === 'first' ? (
                        <div className="space-y-1">
                            <div className="flex items-baseline gap-2">
                                <span className="text-xs text-muted-foreground">Quick check</span>
                                <ScoreText value={review.triage_score ?? triage.score ?? null} className="text-2xl" />
                                <span className="text-xs text-muted-foreground">/ 5</span>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Only the quick check ran, so there are no detailed scores. Replies that score below 4 get a full review.
                            </p>
                        </div>
                    ) : (
                        <p className="text-sm text-muted-foreground">No scores yet.</p>
                    )}
                </Section>

                <Section title="What the review found">
                    {full ? (
                        <div className="space-y-3 text-sm">
                            <div className="space-y-1">
                                <FailureClassBadge value={review.failure_class} />
                                {review.failure_class && QA_FAILURE_CLASS_HELP[review.failure_class] && (
                                    <p className="text-xs text-muted-foreground">{QA_FAILURE_CLASS_HELP[review.failure_class]}</p>
                                )}
                            </div>
                            {review.summary && <TextBlock label="Summary" text={review.summary} />}
                            {review.gap && <TextBlock label="What was missing" text={review.gap} />}
                            {review.suggested_fix && <TextBlock label="Suggested fix" text={review.suggested_fix} />}
                        </div>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            {review.pass === 'first' ? 'Nothing to report: the quick check did not flag this reply.' : 'Nothing yet.'}
                        </p>
                    )}
                </Section>
            </div>

            {(review.gap_group || proposalId) && (
                <Section title="Similar problems" description="Reviews that find the same missing rule or knowledge are grouped together.">
                    {review.gap_group && (
                        <div className="space-y-2 text-sm">
                            {review.gap_group.label && <p className="font-medium">{review.gap_group.label}</p>}
                            <div className="flex flex-wrap items-center gap-1.5">
                                <Badge variant="secondary" title={QA_GAP_STATUS_HELP[review.gap_group.status] ?? undefined}>
                                    {QA_GAP_STATUS_LABELS[review.gap_group.status] ?? review.gap_group.status}
                                </Badge>
                                {review.failure_class === 'missing_tool' ? (
                                    <span className="text-xs text-muted-foreground">
                                        Seen in {review.gap_group.count} conversation{review.gap_group.count === 1 ? '' : 's'}. Missing
                                        tools never become proposals.
                                    </span>
                                ) : (
                                    <span className="text-xs text-muted-foreground">
                                        {gapProgressText(review.gap_group.count, threshold, review.gap_group.status)}
                                    </span>
                                )}
                            </div>
                            {review.failure_class !== 'missing_tool' && review.gap_group.status === 'open' && (
                                <ProgressBar value={threshold > 0 ? review.gap_group.count / threshold : 0} className="max-w-xs" />
                            )}
                            <Button
                                size="xs"
                                variant="link"
                                className="px-0"
                                onClick={() => onOpenTab(review.failure_class === 'missing_tool' ? 'capabilities' : 'gaps')}
                            >
                                {review.failure_class === 'missing_tool' ? 'See all capability requests' : 'See all gaps'} →
                            </Button>
                        </div>
                    )}
                    {proposalId && (
                        <p className="text-sm">
                            <Link href={proposalUrl(proposalId)} className="text-primary hover:underline">
                                Open the proposal this led to →
                            </Link>
                        </p>
                    )}
                </Section>
            )}

            <Section>
                <div className="space-y-3 text-sm">
                    <div>
                        <p className="text-xs font-medium text-muted-foreground">Order details the reviewer had</p>
                        <p>{QA_EVIDENCE_SOURCE_LABELS[evidence] ?? evidence}</p>
                        {QA_EVIDENCE_SOURCE_HELP[evidence] && (
                            <p className="text-xs text-muted-foreground">{QA_EVIDENCE_SOURCE_HELP[evidence]}</p>
                        )}
                    </div>
                    <QuickCheckDetails review={review} defaultOpen={review.pass === 'first'} />
                    <Disclosure title="Technical details">
                        <dl className="grid grid-cols-[max-content_minmax(0,1fr)] gap-x-4 gap-y-1 text-xs">
                            <dt className="text-muted-foreground">Cost of this check</dt>
                            <dd>{usd(review.cost_usd ?? microToUsd(review.cost_micro_usd))}</dd>
                            {typeof triage.cost_micro_usd === 'number' && (
                                <>
                                    <dt className="text-muted-foreground">of which quick check</dt>
                                    <dd>{usd(microToUsd(triage.cost_micro_usd))}</dd>
                                </>
                            )}
                            {review.reviewer_model && (
                                <>
                                    <dt className="text-muted-foreground">Full review model</dt>
                                    <dd>{review.reviewer_model}</dd>
                                </>
                            )}
                            {triage.model && (
                                <>
                                    <dt className="text-muted-foreground">Quick check model</dt>
                                    <dd>{triage.model}</dd>
                                </>
                            )}
                            {review.reviewer_prompt_version && (
                                <>
                                    <dt className="text-muted-foreground">Review version</dt>
                                    <dd>{review.reviewer_prompt_version}</dd>
                                </>
                            )}
                            {review.reviewed_at && (
                                <>
                                    <dt className="text-muted-foreground">Checked</dt>
                                    <dd>{shortDate(review.reviewed_at)}</dd>
                                </>
                            )}
                            <dt className="text-muted-foreground">Review number</dt>
                            <dd>#{review.id}</dd>
                            {review.run_id && (
                                <>
                                    <dt className="text-muted-foreground">Agent run</dt>
                                    <dd>#{review.run_id}</dd>
                                </>
                            )}
                        </dl>
                    </Disclosure>
                </div>
            </Section>

            {(review.status === 'reviewed' || review.status === 'triaged' || review.human_verdict) && (
                <VerdictBox review={review} onSaved={onVerdict} />
            )}
        </div>
    );
}

function MessageBox({ title, body }: { title: string; body: string | null | undefined }) {
    return (
        <div className="flex min-w-0 flex-col rounded-xl border border-border bg-card">
            <p className="border-b border-border px-4 py-2 text-xs font-semibold">{title}</p>
            <div className="max-h-96 overflow-y-auto px-4 py-3">
                {body?.trim() ? (
                    <p className="whitespace-pre-wrap break-words text-sm">{body}</p>
                ) : (
                    <p className="text-sm italic text-muted-foreground">This message is no longer available.</p>
                )}
            </div>
        </div>
    );
}

function TextBlock({ label, text }: { label: string; text: string }) {
    return (
        <div>
            <p className="text-xs font-medium text-muted-foreground">{label}</p>
            <p className="whitespace-pre-wrap">{text}</p>
        </div>
    );
}

function QuickCheckDetails({ review, defaultOpen }: { review: QaReviewDetail; defaultOpen: boolean }) {
    const triage = qaTriage(review);
    const concerns = asArray<string>(triage.concerns).filter((c) => typeof c === 'string' && c.trim());
    const score = review.triage_score ?? (typeof triage.score === 'number' ? triage.score : null);
    if (score === null && concerns.length === 0 && !triage.reason) {
        return <p className="text-xs text-muted-foreground">No quick check: this reply went straight to a full review.</p>;
    }
    return (
        <Disclosure title="Quick check" defaultOpen={defaultOpen}>
            <div className="space-y-1.5 text-xs">
                <p>
                    Score <ScoreText value={score} /> / 5
                    {triage.flagged === true ? ' · flagged for a full review' : triage.flagged === false ? ' · not flagged' : ''}
                </p>
                {concerns.length > 0 && (
                    <div>
                        <p className="text-muted-foreground">Concerns</p>
                        <ul className="list-disc space-y-0.5 pl-4">
                            {concerns.map((c, i) => (
                                <li key={i}>{c}</li>
                            ))}
                        </ul>
                    </div>
                )}
                {triage.reason && (
                    <p>
                        <span className="text-muted-foreground">Why: </span>
                        {triage.reason}
                    </p>
                )}
                {triage.playbook && (
                    <p>
                        <span className="text-muted-foreground">Playbook it matched: </span>
                        {triage.playbook.replace(/_/g, ' ')}
                    </p>
                )}
            </div>
        </Disclosure>
    );
}

function VerdictBox({ review, onSaved }: { review: QaReviewDetail; onSaved: (review: QaReview) => void }) {
    const [choice, setChoice] = useState<QaHumanVerdict | null>(null);
    const [note, setNote] = useState('');
    const action = useAction();

    async function save(verdict: QaHumanVerdict | null) {
        const updated = await action.run('verdict', () => qaApi.setVerdict(review.id, verdict, verdict ? note : null));
        if (updated) {
            setChoice(null);
            setNote('');
            onSaved(updated);
        }
    }

    if (review.human_verdict) {
        return (
            <Section title="Your verdict">
                <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <p className="text-muted-foreground">
                        Changed your mind? Undo it and this review counts again in the averages and gap counts.
                    </p>
                    <Button size="sm" variant="outline" disabled={action.busy !== null} onClick={() => void save(null)}>
                        {action.busy ? 'Undoing…' : 'Undo'}
                    </Button>
                </div>
                <ErrorLine error={action.error ? withQaReason(action.error) : null} />
            </Section>
        );
    }

    return (
        <Section
            title="This review is wrong"
            description="If you disagree, take it out. Reviews you take out are left out of every average and gap count (a gap can drop back below the proposal threshold). You can undo it later."
        >
            <div className="flex flex-wrap gap-2">
                {(['incorrect', 'dismissed'] as QaHumanVerdict[]).map((v) => (
                    <button
                        key={v}
                        type="button"
                        aria-pressed={choice === v}
                        onClick={() => setChoice(choice === v ? null : v)}
                        className={cn(
                            'max-w-xs flex-1 rounded-lg border px-3 py-2 text-left transition-colors hover:border-primary/40',
                            choice === v ? 'border-primary/50 bg-primary/5' : 'border-border',
                        )}
                    >
                        <span className="block text-sm font-medium">{QA_VERDICT_LABELS[v]}</span>
                        <span className="block text-xs text-muted-foreground">{QA_VERDICT_HELP[v]}</span>
                    </button>
                ))}
            </div>
            {choice && (
                <div className="space-y-2">
                    <Textarea
                        value={note}
                        maxLength={2000}
                        placeholder="Why? (optional, helps the colleague who reads this later)"
                        className="min-h-16"
                        onChange={(e) => setNote(e.target.value)}
                    />
                    <div className="flex items-center gap-2">
                        <Button size="sm" disabled={action.busy !== null} onClick={() => void save(choice)}>
                            {action.busy ? 'Saving…' : 'Take this review out'}
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => setChoice(null)}>
                            Cancel
                        </Button>
                    </div>
                </div>
            )}
            <ErrorLine error={action.error ? withQaReason(action.error) : null} />
        </Section>
    );
}
