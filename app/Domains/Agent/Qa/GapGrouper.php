<?php

namespace App\Domains\Agent\Qa;

use App\Domains\Agent\Jobs\DraftQaProposalJob;
use App\Domains\Agent\Manager\ScopeGuard;
use App\Domains\Agent\Models\Proposal;
use App\Domains\Agent\Qa\Models\QaGap;
use App\Domains\Agent\Qa\Models\QaReview;
use Illuminate\Support\Facades\DB;

/**
 * Groups QA gaps (missing_instruction / missing_knowledge / missing_tool)
 * by failure class + playbook + similar text, with no embeddings: the
 * reviewer's snake_case topic matching, or Jaccard similarity of normalised
 * terms >= agent_qa.gaps.similarity. A missing_instruction /
 * missing_knowledge group reaching agent_qa.gaps.proposal_threshold distinct
 * conversations (not dismissed) gets ONE proposal drafted by the manager;
 * missing_tool groups are only listed as capability requests.
 */
class GapGrouper
{
    private const STOP = ['the', 'and', 'for', 'with', 'that', 'this', 'when', 'what', 'about', 'from', 'into', 'there', 'their', 'they',
        'should', 'would', 'could', 'have', 'does', 'doesn', 'were', 'which', 'customer', 'customers', 'agent', 'reply', 'replies',
        'instruction', 'instructions', 'knowledge', 'missing', 'policy', 'information', 'playbook', 'rule', 'rules', 'how', 'whether', 'not', 'no'];

    /** @return list<string> */
    public static function terms(string $text): array
    {
        $stop = ScopeGuard::stems(implode(' ', self::STOP));

        return array_values(array_diff(ScopeGuard::stems(str_replace('_', ' ', $text)), $stop));
    }

    /** @param  list<string>  $a  @param  list<string>  $b */
    public static function similarity(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $inter = count(array_intersect($a, $b));
        $union = count(array_unique([...$a, ...$b]));

        return $union > 0 ? $inter / $union : 0.0;
    }

    public function attach(QaReview $review): ?QaGap
    {
        if (! in_array($review->failure_class, QaReview::GAP_CLASSES, true) || $review->status !== QaReview::STATUS_REVIEWED
            || $review->human_verdict !== null || trim((string) $review->gap) === '') {
            return null;
        }
        $previous = $review->gap_id;
        $terms = self::terms($review->gap.' '.$review->gap_topic);
        $threshold = (float) config('agent_qa.gaps.similarity', 0.34);

        $gap = DB::transaction(function () use ($review, $terms, $threshold) {
            $candidates = QaGap::where('workspace_id', $review->workspace_id)
                ->where('failure_class', $review->failure_class)
                ->when($review->playbook_id === null, fn ($q) => $q->whereNull('playbook_id'), fn ($q) => $q->where('playbook_id', $review->playbook_id))
                ->whereIn('status', [QaGap::STATUS_OPEN, QaGap::STATUS_DRAFTING, QaGap::STATUS_PROPOSED, QaGap::STATUS_FAILED])
                ->lockForUpdate()->get();

            $best = null;
            $bestScore = 0.0;
            foreach ($candidates as $candidate) {
                if ($this->closeIfDone($candidate)) {
                    continue;
                }
                $score = $review->gap_topic !== null && $candidate->topic === $review->gap_topic ? 1.0 : self::similarity($terms, (array) $candidate->terms);
                if ($score >= $threshold && $score > $bestScore) {
                    $best = $candidate;
                    $bestScore = $score;
                }
            }

            $best ??= QaGap::create([
                'workspace_id' => $review->workspace_id,
                'failure_class' => (string) $review->failure_class,
                'playbook_id' => $review->playbook_id,
                'topic' => $review->gap_topic,
                'label' => mb_substr((string) $review->gap, 0, 1000),
                'terms' => $terms,
                'status' => QaGap::STATUS_OPEN,
            ]);
            $review->forceFill(['gap_id' => $best->id, 'proposal_id' => $best->proposal_id ?? $review->proposal_id])->save();

            return $best;
        });

        if ($previous !== null && $previous !== $gap->id && ($old = QaGap::find($previous)) !== null) {
            $this->recount($old);
        }

        return $this->recount($gap);
    }

    /** Recount a group (after attach or a human dismiss) and draft its proposal at the threshold. */
    public function recount(QaGap $gap): QaGap
    {
        $rows = QaReview::where('gap_id', $gap->id)->where('status', QaReview::STATUS_REVIEWED)->whereNull('human_verdict')
            ->get(['conversation_id', 'replied_at', 'created_at']);
        $conversations = $rows->pluck('conversation_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $gap->forceFill([
            'review_count' => count($conversations),
            'conversation_ids' => $conversations,
            'last_seen_at' => $rows->max('created_at') ?? $gap->last_seen_at,
        ])->save();

        $threshold = max(1, (int) config('agent_qa.gaps.proposal_threshold', 3));
        if ($gap->failure_class !== 'missing_tool' && $gap->status === QaGap::STATUS_OPEN && $gap->proposal_id === null && count($conversations) >= $threshold) {
            // Claim the group: only one drafting job, ever, per group.
            $claimed = QaGap::whereKey($gap->id)->where('status', QaGap::STATUS_OPEN)->whereNull('proposal_id')
                ->update(['status' => QaGap::STATUS_DRAFTING, 'updated_at' => now()]);
            if ($claimed === 1) {
                DraftQaProposalJob::dispatch($gap->id);
            }
        }

        return $gap->fresh() ?? $gap;
    }

    /** A proposed group whose proposal is no longer open is closed: new gaps start a new group. */
    private function closeIfDone(QaGap $gap): bool
    {
        if ($gap->status !== QaGap::STATUS_PROPOSED || $gap->proposal_id === null) {
            return false;
        }
        $status = Proposal::whereKey($gap->proposal_id)->value('status');
        if ($status !== null && in_array($status, Proposal::OPEN_STATUSES, true)) {
            return false;
        }
        $gap->forceFill(['status' => QaGap::STATUS_CLOSED])->save();

        return true;
    }
}
