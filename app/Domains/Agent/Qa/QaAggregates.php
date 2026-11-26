<?php

namespace App\Domains\Agent\Qa;

use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Qa\Models\QaGap;
use App\Domains\Agent\Qa\Models\QaReview;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Chart aggregates over QA reviews. Reviews a human dismissed or marked
 * incorrect are excluded, as are imported conversations. Dates are the day
 * the reviewed reply was sent (`replied_at`). docs/agent-qa.md.
 *
 * Averages: `triage_avg` = first-pass score over every reviewed reply
 * (cheap, unbiased); `review_avg` = full-review overall over every full
 * review (biased towards flagged replies); `sample_avg` = full-review
 * overall over the random sample only (unbiased, smaller).
 */
class QaAggregates
{
    /** @return array<string, mixed> */
    public function compute(int $workspaceId, Carbon $from, Carbon $to, ?int $playbookId = null): array
    {
        $base = fn (): Builder => DB::table('agent_qa_reviews as q')
            ->join('conversations as c', 'c.id', '=', 'q.conversation_id')
            ->where('q.workspace_id', $workspaceId)
            ->where('c.imported', false)
            ->whereNull('q.human_verdict')
            ->whereIn('q.status', [QaReview::STATUS_TRIAGED, QaReview::STATUS_REVIEWED])
            ->whereBetween(DB::raw('coalesce(q.replied_at, q.created_at)'), [$from, $to])
            ->when($playbookId !== null, fn ($q) => $q->where('q.playbook_id', $playbookId));

        $byAuthor = $base()->selectRaw("q.author_type,
                count(*) as total,
                count(q.triage_score) as triaged, avg(q.triage_score) as triage_avg,
                count(*) filter (where q.status = 'reviewed') as reviewed,
                avg(q.overall) filter (where q.status = 'reviewed') as review_avg,
                count(*) filter (where q.status = 'reviewed' and q.sampled) as sampled,
                avg(q.overall) filter (where q.status = 'reviewed' and q.sampled) as sample_avg,
                avg((q.scores->>'resolved')::numeric) filter (where q.status = 'reviewed') as resolved,
                avg((q.scores->>'policy_consistency')::numeric) filter (where q.status = 'reviewed') as policy_consistency,
                avg((q.scores->>'factual_grounding')::numeric) filter (where q.status = 'reviewed') as factual_grounding,
                avg((q.scores->>'tone_match')::numeric) filter (where q.status = 'reviewed') as tone_match,
                avg((q.scores->>'clarity')::numeric) filter (where q.status = 'reviewed') as clarity")
            ->groupBy('q.author_type')->get()->keyBy('author_type');

        $classes = $base()->where('q.status', QaReview::STATUS_REVIEWED)
            ->selectRaw('q.author_type, q.failure_class, count(*) as n')->groupBy('q.author_type', 'q.failure_class')->get();

        $authors = [];
        foreach (QaReview::AUTHOR_TYPES as $type) {
            $row = $byAuthor[$type] ?? null;
            $authors[] = [
                'author_type' => $type,
                'reviews' => (int) ($row->total ?? 0),
                'triaged' => (int) ($row->triaged ?? 0),
                'triage_avg' => self::avg($row->triage_avg ?? null),
                'reviewed' => (int) ($row->reviewed ?? 0),
                'review_avg' => self::avg($row->review_avg ?? null),
                'sampled' => (int) ($row->sampled ?? 0),
                'sample_avg' => self::avg($row->sample_avg ?? null),
                'scores' => array_combine(QaReview::SCORE_KEYS, array_map(fn ($k) => self::avg($row->{$k} ?? null), QaReview::SCORE_KEYS)),
                'failure_classes' => self::classCounts($classes->where('author_type', $type)),
            ];
        }

        $slugs = Playbook::where('workspace_id', $workspaceId)->pluck('slug', 'id');
        $byPlaybook = $base()->selectRaw("q.playbook_id, count(*) as total, avg(q.triage_score) as triage_avg,
                count(*) filter (where q.status = 'reviewed') as reviewed, avg(q.overall) filter (where q.status = 'reviewed') as review_avg,
                count(*) filter (where q.failure_class = 'needed_human') as needed_human,
                count(*) filter (where q.author_type in ('ai', 'ai_edited')) as ai_replies,
                count(*) filter (where q.author_type = 'human') as human_replies")
            ->groupBy('q.playbook_id')->get()
            ->map(fn ($r) => [
                'playbook_id' => $r->playbook_id !== null ? (int) $r->playbook_id : null,
                'playbook_slug' => $r->playbook_id !== null ? ($slugs[(int) $r->playbook_id] ?? null) : null,
                'reviews' => (int) $r->total,
                'triage_avg' => self::avg($r->triage_avg),
                'reviewed' => (int) $r->reviewed,
                'review_avg' => self::avg($r->review_avg),
                'needed_human' => (int) $r->needed_human,
                'ai_replies' => (int) $r->ai_replies,
                'human_replies' => (int) $r->human_replies,
                'failure_classes' => [],
            ])->sortByDesc('reviews')->values()->all();
        $playbookClasses = $base()->where('q.status', QaReview::STATUS_REVIEWED)
            ->selectRaw('q.playbook_id, q.failure_class, count(*) as n')->groupBy('q.playbook_id', 'q.failure_class')->get();
        foreach ($byPlaybook as $i => $row) {
            $byPlaybook[$i]['failure_classes'] = self::classCounts($playbookClasses->filter(fn ($r) => ($r->playbook_id !== null ? (int) $r->playbook_id : null) === $row['playbook_id']));
        }

        $daily = $base()->selectRaw("date(coalesce(q.replied_at, q.created_at)) as day, q.author_type, avg(q.triage_score) as triage_avg,
                avg(q.overall) filter (where q.status = 'reviewed') as review_avg, count(*) as n")
            ->groupByRaw('date(coalesce(q.replied_at, q.created_at)), q.author_type')->orderBy('day')->get()
            ->map(fn ($r) => ['date' => substr((string) $r->day, 0, 10), 'author_type' => $r->author_type, 'reviews' => (int) $r->n,
                'triage_avg' => self::avg($r->triage_avg), 'review_avg' => self::avg($r->review_avg)])->values()->all();

        $gaps = QaGap::where('workspace_id', $workspaceId)->selectRaw('failure_class, status, count(*) as n')->groupBy('failure_class', 'status')->toBase()->get();

        return [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'by_author_type' => $authors,
            'failure_classes' => self::classCounts($classes),
            'by_playbook' => $byPlaybook,
            'needed_human_by_playbook' => collect($byPlaybook)->filter(fn ($r) => $r['needed_human'] > 0)->sortByDesc('needed_human')
                ->map(fn ($r) => ['playbook_id' => $r['playbook_id'], 'playbook_slug' => $r['playbook_slug'], 'needed_human' => $r['needed_human'], 'reviewed' => $r['reviewed']])->values()->all(),
            'daily' => $daily,
            'gaps' => $gaps->map(fn ($r) => ['failure_class' => $r->failure_class, 'status' => $r->status, 'count' => (int) $r->n])->values()->all(),
            'excluded_by_human' => (int) DB::table('agent_qa_reviews')->where('workspace_id', $workspaceId)->whereNotNull('human_verdict')
                ->whereBetween(DB::raw('coalesce(replied_at, created_at)'), [$from, $to])->count(),
        ];
    }

    /** @return array<string, int> */
    public static function classCounts(iterable $rows): array
    {
        $out = array_fill_keys(QaReview::FAILURE_CLASSES, 0);
        foreach ($rows as $row) {
            if ($row->failure_class !== null && isset($out[$row->failure_class])) {
                $out[$row->failure_class] += (int) $row->n;
            }
        }

        return $out;
    }

    private static function avg(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }
}
