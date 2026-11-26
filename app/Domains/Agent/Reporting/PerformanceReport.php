<?php

namespace App\Domains\Agent\Reporting;

use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Pipeline\HandoverReasons;
use App\Domains\Agent\Qa\Models\QaReview;
use App\Domains\Commerce\Actions\ActionStats;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The "AI performance" report: by day / week and by playbook. Every metric
 * is a plain aggregate over raw rows, imported history excluded. Metric
 * definitions (and the date each one is anchored on) are in
 * docs/agent-qa.md "Report" and returned in `definitions`.
 *
 * Postgres only (date_trunc, FILTER, jsonb).
 */
class PerformanceReport
{
    public const GROUPS = ['day', 'week'];

    /** Pipeline usage step -> AI role. */
    private const STEP_ROLES = ['triage' => 'triage', 'writer' => 'writer', 'writer_fallback' => 'writer', 'rewrite' => 'writer', 'validator' => 'validator'];

    /** Mining stage -> AI role. */
    private const MINING_ROLES = ['extract' => 'history_extraction', 'cluster' => 'history_extraction', 'synthesise' => 'manager', 'voice' => 'manager'];

    private int $ws;

    private Carbon $from;

    private Carbon $to;

    private string $unit;

    private ?int $playbookId;

    /** @return array<string, mixed> */
    public function build(int $workspaceId, Carbon $from, Carbon $to, string $group = 'day', ?int $playbookId = null): array
    {
        $this->ws = $workspaceId;
        $this->unit = in_array($group, self::GROUPS, true) ? $group : 'day';
        $this->from = $from->copy();
        $this->to = $to->copy();
        $this->playbookId = $playbookId;

        $periods = [];
        foreach ($this->bucketKeys() as $key) {
            $periods[$key] = $this->emptyRow(['period_start' => $key]);
        }
        $totals = $this->emptyRow(['period_start' => null]);
        $playbooks = [];

        $this->fill($periods, $totals, $playbooks);

        // Conversation-level counts cannot be split by playbook.
        $blank = fn (array $row) => $playbookId === null ? $row : [...$row, 'conversations_received' => null, 'resolved_conversations' => null];
        foreach ($periods as $k => $row) {
            $periods[$k] = $this->derive($blank($row));
        }
        $totals = $this->derive($blank($totals));
        $slugs = Playbook::where('workspace_id', $workspaceId)->get(['id', 'slug', 'name'])->keyBy('id');
        foreach ($playbooks as $id => $row) {
            $row['playbook_id'] = $id === 0 ? null : $id;
            $row['playbook_slug'] = $id === 0 ? null : ($slugs[$id]->slug ?? null);
            $row['playbook_name'] = $id === 0 ? '(no playbook)' : ($slugs[$id]->name ?? null);
            $row['conversations_received'] = null;
            $row['resolved_conversations'] = null;
            $playbooks[$id] = $this->derive($row);
        }

        return [
            'from' => $this->from->toIso8601String(),
            'to' => $this->to->toIso8601String(),
            'group' => $this->unit,
            'playbook_id' => $playbookId,
            'periods' => array_values($periods),
            'totals' => $totals,
            'playbooks' => collect($playbooks)->sortByDesc(fn ($r) => $r['drafts_produced'] + $r['handovers'])->values()->all(),
            'definitions' => self::definitions(),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $periods
     * @param  array<string, mixed>  $totals
     * @param  array<int, array<string, mixed>>  $playbooks
     */
    private function fill(array &$periods, array &$totals, array &$playbooks): void
    {
        $add = function (string $path, ?string $bucket, ?int $playbook, float|int $value) use (&$periods, &$totals, &$playbooks): void {
            if ($bucket !== null && isset($periods[$bucket])) {
                self::inc($periods[$bucket], $path, $value);
            }
            self::inc($totals, $path, $value);
            if ($playbook !== -1) {
                $key = $playbook ?? 0;
                $playbooks[$key] ??= $this->emptyRow(['period_start' => null]);
                self::inc($playbooks[$key], $path, $value);
            }
        };

        // Conversations received (not per playbook).
        if ($this->playbookId === null) {
            foreach ($this->grouped($this->conversations()->whereBetween('c.created_at', [$this->from, $this->to]), 'c.created_at', 'count(*)') as $r) {
                $add('conversations_received', $r->bucket, -1, (int) $r->v);
            }
            $resolved = $this->conversations()->where('c.status', 'closed')->whereBetween('c.updated_at', [$this->from, $this->to]);
            foreach ($this->grouped($resolved, 'c.updated_at', 'count(*)') as $r) {
                $add('resolved_conversations', $r->bucket, -1, (int) $r->v);
            }
            $auto = DB::table('threads as t')->join('conversations as c', 'c.id', '=', 't.conversation_id')
                ->where('c.workspace_id', $this->ws)->where('c.imported', false)
                ->where('t.type', 'message')->where('t.origin', 'ai')->whereNotNull('t.sent_at')
                ->whereBetween('t.sent_at', [$this->from, $this->to]);
            foreach ($this->grouped($auto, 't.sent_at', 'count(distinct t.conversation_id)') as $r) {
                $add('auto_sent', $r->bucket, -1, (int) $r->v);
            }
        }

        // Live runs: drafts, handovers, conversations with a draft, sent as-is.
        $runs = $this->liveRuns();
        $rows = (clone $runs)->selectRaw("{$this->bucket('r.created_at')} as bucket, r.playbook_id,
                count(*) filter (where r.decision in ('draft', 'auto_send') and r.draft is not null) as drafts,
                count(*) filter (where r.decision = 'handover') as handovers,
                count(*) filter (where r.decision = 'auto_send') as auto_send_decisions,
                count(distinct r.conversation_id) filter (where r.decision in ('draft', 'auto_send') and r.draft is not null) as with_draft,
                count(distinct r.conversation_id) filter (where r.decision in ('draft', 'auto_send') and exists (
                    select 1 from agent_feedback_events f where f.run_id = r.id and f.kind = 'sent_as_is')) as sent_as_is_conv")
            ->groupByRaw("{$this->bucket('r.created_at')}, r.playbook_id")->get();
        foreach ($rows as $r) {
            $pb = $r->playbook_id !== null ? (int) $r->playbook_id : null;
            $add('drafts_produced', self::key($r->bucket), $pb, (int) $r->drafts);
            $add('handovers', self::key($r->bucket), $pb, (int) $r->handovers);
            $add('auto_send_decisions', self::key($r->bucket), $pb, (int) $r->auto_send_decisions);
            $add('conversations_with_draft', self::key($r->bucket), $pb, (int) $r->with_draft);
            $add('conversations_sent_as_is', self::key($r->bucket), $pb, (int) $r->sent_as_is_conv);
        }
        $reasons = (clone $runs)->where('r.decision', 'handover')->whereNotNull('r.handover_reason')
            ->selectRaw("{$this->bucket('r.created_at')} as bucket, r.playbook_id, split_part(r.handover_reason, ':', 1) as reason, count(*) as n")
            ->groupByRaw("{$this->bucket('r.created_at')}, r.playbook_id, split_part(r.handover_reason, ':', 1)")->get();
        foreach ($reasons as $r) {
            $add('handover_reasons.'.$r->reason, self::key($r->bucket), $r->playbook_id !== null ? (int) $r->playbook_id : null, (int) $r->n);
        }

        // Shopify action executions (live), anchored on when they were proposed.
        $actions = ActionStats::query($this->ws, $this->from, $this->to, $this->playbookId)
            ->selectRaw("{$this->bucket('e.created_at')} as bucket, e.playbook_id, ".ActionStats::selectSql())
            ->groupByRaw("{$this->bucket('e.created_at')}, e.playbook_id")->get();
        foreach ($actions as $r) {
            $pb = $r->playbook_id !== null ? (int) $r->playbook_id : null;
            foreach (['proposed', 'approved', 'unattended', 'succeeded', 'failed', 'rejected'] as $k) {
                $add("actions.{$k}", self::key($r->bucket), $pb, (int) $r->{$k});
            }
            $add('actions.refunded_amount', self::key($r->bucket), $pb, (float) $r->refunded_amount);
        }

        // Time to first draft: the first drafted live run per customer message.
        $first = DB::query()->fromSub(
            (clone $runs)->whereIn('r.decision', ['draft', 'auto_send'])->whereNotNull('r.draft')->whereNotNull('r.completed_at')->whereNotNull('r.customer_thread_id')
                ->join('threads as ct', 'ct.id', '=', 'r.customer_thread_id')
                ->selectRaw('distinct on (r.customer_thread_id) r.created_at, r.playbook_id, extract(epoch from (r.completed_at - coalesce(ct.sent_at, ct.created_at))) as secs')
                ->orderBy('r.customer_thread_id')->orderBy('r.id'),
            'x')->where('secs', '>=', 0)->get();
        foreach ($first as $r) {
            $bucket = $this->keyOf(Carbon::parse($r->created_at));
            $add('ttfd.values', $bucket, $r->playbook_id !== null ? (int) $r->playbook_id : null, (float) $r->secs);
        }

        // What staff did with drafts (feedback events on live runs).
        $feedback = DB::table('agent_feedback_events as f')->join('conversations as c', 'c.id', '=', 'f.conversation_id')
            ->leftJoin('agent_runs as r', 'r.id', '=', 'f.run_id')
            ->where('f.workspace_id', $this->ws)->where('c.imported', false)
            ->whereIn('f.kind', ['sent_as_is', 'sent_edited', 'gmail_reply', 'rejected'])
            ->whereBetween('f.created_at', [$this->from, $this->to])
            ->when($this->playbookId !== null, fn ($q) => $q->where('r.playbook_id', $this->playbookId))
            ->selectRaw("{$this->bucket('f.created_at')} as bucket, r.playbook_id, f.kind, count(*) as n, sum(f.percent_changed) as pct_sum, count(f.percent_changed) as pct_n")
            ->groupByRaw("{$this->bucket('f.created_at')}, r.playbook_id, f.kind")->get();
        foreach ($feedback as $r) {
            $pb = $r->playbook_id !== null ? (int) $r->playbook_id : null;
            $add('feedback.'.$r->kind, self::key($r->bucket), $pb, (int) $r->n);
            if ($r->kind === 'sent_edited') {
                $add('pct_sum', self::key($r->bucket), $pb, (int) $r->pct_sum);
                $add('pct_n', self::key($r->bucket), $pb, (int) $r->pct_n);
            }
        }

        // QA by author type (dismissed / incorrect excluded).
        $qa = DB::table('agent_qa_reviews as q')->join('conversations as c', 'c.id', '=', 'q.conversation_id')
            ->where('q.workspace_id', $this->ws)->where('c.imported', false)->whereNull('q.human_verdict')
            ->whereIn('q.status', ['triaged', 'reviewed'])
            ->whereBetween(DB::raw('coalesce(q.replied_at, q.created_at)'), [$this->from, $this->to])
            ->when($this->playbookId !== null, fn ($q) => $q->where('q.playbook_id', $this->playbookId));
        $qaRows = (clone $qa)->selectRaw("{$this->bucket('coalesce(q.replied_at, q.created_at)')} as bucket, q.playbook_id, q.author_type,
                count(q.triage_score) as triaged, sum(q.triage_score) as triage_sum,
                count(*) filter (where q.status = 'reviewed') as reviewed, sum(q.overall) filter (where q.status = 'reviewed') as overall_sum")
            ->groupByRaw("{$this->bucket('coalesce(q.replied_at, q.created_at)')}, q.playbook_id, q.author_type")->get();
        foreach ($qaRows as $r) {
            $pb = $r->playbook_id !== null ? (int) $r->playbook_id : null;
            $type = (string) $r->author_type;
            $add("qa.{$type}.triaged", self::key($r->bucket), $pb, (int) $r->triaged);
            $add("qa.{$type}.triage_sum", self::key($r->bucket), $pb, (int) $r->triage_sum);
            $add("qa.{$type}.reviewed", self::key($r->bucket), $pb, (int) $r->reviewed);
            $add("qa.{$type}.overall_sum", self::key($r->bucket), $pb, (float) $r->overall_sum);
        }
        $classes = (clone $qa)->where('q.status', 'reviewed')->whereNotNull('q.failure_class')
            ->selectRaw("{$this->bucket('coalesce(q.replied_at, q.created_at)')} as bucket, q.playbook_id, q.failure_class, count(*) as n")
            ->groupByRaw("{$this->bucket('coalesce(q.replied_at, q.created_at)')}, q.playbook_id, q.failure_class")->get();
        foreach ($classes as $r) {
            $add('failure_classes.'.$r->failure_class, self::key($r->bucket), $r->playbook_id !== null ? (int) $r->playbook_id : null, (int) $r->n);
        }

        // Cost by role and purpose.
        foreach ($this->costRows() as $r) {
            $add('cost.by_role.'.$r['role'], $r['bucket'], $r['playbook'], $r['micro']);
            $add('cost.by_purpose.'.$r['purpose'], $r['bucket'], $r['playbook'], $r['micro']);
            $add('cost.total', $r['bucket'], $r['playbook'], $r['micro']);
            if ($r['purpose'] === 'live') {
                $add('cost.live', $r['bucket'], $r['playbook'], $r['micro']);
            }
        }
    }

    /**
     * Every AI cost in the range as {bucket, playbook (null = none, -1 = not
     * attributable), role, purpose, micro}.
     *
     * @return list<array{bucket: string|null, playbook: int|null, role: string, purpose: string, micro: int}>
     */
    private function costRows(): array
    {
        $out = [];
        $range = [$this->from, $this->to];
        $pbFilter = $this->playbookId;

        // Agent runs: per usage step (role), anchored on the run. Imported conversations never have runs.
        $runs = DB::table('agent_runs as r')->where('r.workspace_id', $this->ws)->whereBetween('r.created_at', $range)
            ->when($pbFilter !== null, fn ($q) => $q->where('r.playbook_id', $pbFilter));
        $steps = (clone $runs)->crossJoin(DB::raw("jsonb_array_elements(coalesce(r.trace->'usage', '[]'::jsonb)) as u"))
            ->selectRaw("{$this->bucket('r.created_at')} as bucket, r.playbook_id, r.purpose, u->>'step' as step, sum(coalesce((u->>'cost_micro_usd')::bigint, 0)) as micro")
            ->groupByRaw("{$this->bucket('r.created_at')}, r.playbook_id, r.purpose, u->>'step'")->get();
        $byStep = [];
        foreach ($steps as $r) {
            $out[] = ['bucket' => self::key($r->bucket), 'playbook' => $r->playbook_id !== null ? (int) $r->playbook_id : null,
                'role' => self::STEP_ROLES[(string) $r->step] ?? (string) $r->step, 'purpose' => (string) $r->purpose, 'micro' => (int) $r->micro];
            $k = self::key($r->bucket).'|'.$r->playbook_id.'|'.$r->purpose;
            $byStep[$k] = ($byStep[$k] ?? 0) + (int) $r->micro;
        }
        // Run cost not itemised in the trace (older rows) counts as role "unattributed".
        $totals = (clone $runs)->selectRaw("{$this->bucket('r.created_at')} as bucket, r.playbook_id, r.purpose, sum(r.cost_micro_usd) as micro")
            ->groupByRaw("{$this->bucket('r.created_at')}, r.playbook_id, r.purpose")->get();
        foreach ($totals as $r) {
            $rest = (int) $r->micro - ($byStep[self::key($r->bucket).'|'.$r->playbook_id.'|'.$r->purpose] ?? 0);
            if ($rest > 0) {
                $out[] = ['bucket' => self::key($r->bucket), 'playbook' => $r->playbook_id !== null ? (int) $r->playbook_id : null, 'role' => 'unattributed', 'purpose' => (string) $r->purpose, 'micro' => $rest];
            }
        }

        // QA reviews: first pass (triage) + full review (simulation_judge).
        $qa = DB::table('agent_qa_reviews as q')->where('q.workspace_id', $this->ws)->whereBetween('q.created_at', $range)
            ->when($pbFilter !== null, fn ($q) => $q->where('q.playbook_id', $pbFilter))
            ->selectRaw("{$this->bucket('q.created_at')} as bucket, q.playbook_id, sum(q.triage_cost_micro_usd) as t, sum(q.review_cost_micro_usd) as j")
            ->groupByRaw("{$this->bucket('q.created_at')}, q.playbook_id")->get();
        foreach ($qa as $r) {
            $pb = $r->playbook_id !== null ? (int) $r->playbook_id : null;
            $out[] = ['bucket' => self::key($r->bucket), 'playbook' => $pb, 'role' => 'triage', 'purpose' => 'qa', 'micro' => (int) $r->t];
            $out[] = ['bucket' => self::key($r->bucket), 'playbook' => $pb, 'role' => 'simulation_judge', 'purpose' => 'qa', 'micro' => (int) $r->j];
        }
        $gaps = DB::table('agent_qa_gaps')->where('workspace_id', $this->ws)->whereBetween('updated_at', $range)
            ->when($pbFilter !== null, fn ($q) => $q->where('playbook_id', $pbFilter))
            ->selectRaw("{$this->bucket('updated_at')} as bucket, playbook_id, sum(cost_micro_usd) as micro")->groupByRaw("{$this->bucket('updated_at')}, playbook_id")->get();
        foreach ($gaps as $r) {
            $out[] = ['bucket' => self::key($r->bucket), 'playbook' => $r->playbook_id !== null ? (int) $r->playbook_id : null, 'role' => 'manager', 'purpose' => 'qa_proposals', 'micro' => (int) $r->micro];
        }

        if ($pbFilter === null) {
            $simple = [
                ['manager', 'manager_chat', DB::table('agent_manager_messages')->where('workspace_id', $this->ws)->whereBetween('created_at', $range), 'created_at', 'sum(cost_micro_usd)'],
                ['manager', 'feedback_routing', DB::table('agent_feedback_events')->where('workspace_id', $this->ws)->where('kind', 'comment')->whereBetween('created_at', $range),
                    'created_at', "sum(coalesce((meta->'routing'->>'cost_micro_usd')::bigint, 0))"],
                ['manager', 'conflict_check', DB::table('agent_proposals')->where('workspace_id', $this->ws)->whereNotNull('conflicts_checked_at')->whereBetween('conflicts_checked_at', $range),
                    'conflicts_checked_at', "sum(coalesce((conflicts->'llm'->>'cost_micro_usd')::bigint, 0))"],
                ['simulation_judge', 'simulation_judge', DB::table('agent_simulation_results as sr')->join('agent_simulations as s', 's.id', '=', 'sr.simulation_id')
                    ->where('s.workspace_id', $this->ws)->whereBetween('sr.created_at', $range),
                    'sr.created_at', "sum(coalesce((sr.scores->'judge'->>'cost_micro_usd')::bigint, 0) + coalesce((sr.baseline_scores->'judge'->>'cost_micro_usd')::bigint, 0))"],
            ];
            foreach ($simple as [$role, $purpose, $query, $col, $expr]) {
                foreach ($this->grouped($query, $col, $expr) as $r) {
                    $out[] = ['bucket' => $r->bucket, 'playbook' => -1, 'role' => $role, 'purpose' => $purpose, 'micro' => (int) $r->v];
                }
            }
            $mining = DB::table('agent_mining_runs as m')->crossJoin(DB::raw('jsonb_each(coalesce(m.usage, \'{}\'::jsonb)) as u(stage, data)'))
                ->where('m.workspace_id', $this->ws)->whereBetween('m.created_at', $range)
                ->selectRaw("{$this->bucket('m.created_at')} as bucket, u.stage, sum(coalesce((u.data->>'cost_micro_usd')::bigint, 0)) as micro")
                ->groupByRaw("{$this->bucket('m.created_at')}, u.stage")->get();
            foreach ($mining as $r) {
                $out[] = ['bucket' => self::key($r->bucket), 'playbook' => -1, 'role' => self::MINING_ROLES[(string) $r->stage] ?? 'history_extraction', 'purpose' => 'mining', 'micro' => (int) $r->micro];
            }
        }

        return array_values(array_filter($out, fn ($r) => $r['micro'] > 0));
    }

    /** @param  array<string, mixed>  $row */
    private function derive(array $row): array
    {
        $rate = fn (int|float $a, int|float $b) => $b > 0 ? round($a / $b, 4) : null;
        $f = $row['feedback'];
        $fromDraft = $f['sent_as_is'] + $f['sent_edited'];
        $handled = $fromDraft + $f['gmail_reply'] + $f['rejected'];
        $values = $row['ttfd']['values'];
        sort($values);
        $n = count($values);

        $qa = [];
        foreach (QaReview::AUTHOR_TYPES as $type) {
            $q = $row['qa'][$type] ?? ['triaged' => 0, 'triage_sum' => 0, 'reviewed' => 0, 'overall_sum' => 0];
            $qa[$type] = [
                'triaged' => (int) $q['triaged'],
                'triage_avg' => $q['triaged'] > 0 ? round($q['triage_sum'] / $q['triaged'], 2) : null,
                'reviewed' => (int) $q['reviewed'],
                'review_avg' => $q['reviewed'] > 0 ? round($q['overall_sum'] / $q['reviewed'], 2) : null,
            ];
        }
        $reasons = [];
        arsort($row['handover_reasons']);
        foreach ($row['handover_reasons'] as $reason => $count) {
            $reasons[] = ['reason' => (string) $reason, 'label' => HandoverReasons::label((string) $reason), 'count' => (int) $count];
        }
        $micro = (int) $row['cost']['total'];
        $resolved = $row['resolved_conversations'];

        return [
            ...array_intersect_key($row, array_flip(['period_start', 'playbook_id', 'playbook_slug', 'playbook_name'])),
            'conversations_received' => $row['conversations_received'],
            'drafts_produced' => $row['drafts_produced'],
            'conversations_with_draft' => $row['conversations_with_draft'],
            'conversations_sent_as_is' => $row['conversations_sent_as_is'],
            'automation_rate' => $rate($row['conversations_sent_as_is'], $row['conversations_with_draft']),
            'auto_sent' => $row['auto_sent'],
            'auto_sent_rate' => $row['conversations_received'] === null ? null : ($row['conversations_received'] > 0 ? round($row['auto_sent'] / $row['conversations_received'], 4) : 0.0),
            'handovers' => $row['handovers'],
            'auto_send_decisions' => $row['auto_send_decisions'],
            'handover_reasons' => $reasons,
            'feedback' => $f,
            'edit_rate' => $rate($f['sent_edited'], $fromDraft),
            'avg_percent_changed' => $row['pct_n'] > 0 ? (int) round($row['pct_sum'] / $row['pct_n']) : null,
            'gmail_reply_rate' => $rate($f['gmail_reply'], $handled),
            'qa' => ['by_author_type' => $qa, 'failure_classes' => array_merge(array_fill_keys(QaReview::FAILURE_CLASSES, 0), $row['failure_classes'])],
            'cost' => [
                'total_micro_usd' => $micro,
                'total_usd' => round($micro / 1_000_000, 4),
                'by_role' => collect($row['cost']['by_role'])->map(fn ($m) => round($m / 1_000_000, 4))->sortDesc()->all(),
                'by_purpose' => collect($row['cost']['by_purpose'])->map(fn ($m) => round($m / 1_000_000, 4))->sortDesc()->all(),
            ],
            'resolved_conversations' => $resolved,
            'cost_per_resolved_usd' => $resolved !== null && $resolved > 0 ? round($micro / 1_000_000 / $resolved, 4) : null,
            'live_cost_per_resolved_usd' => $resolved !== null && $resolved > 0 ? round(((int) $row['cost']['live']) / 1_000_000 / $resolved, 4) : null,
            'actions' => [...$row['actions'], 'refunded_amount' => round((float) $row['actions']['refunded_amount'], 2)],
            'time_to_first_draft' => [
                'count' => $n,
                'avg_seconds' => $n > 0 ? (int) round(array_sum($values) / $n) : null,
                'median_seconds' => $n > 0 ? (int) round($n % 2 === 1 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2) : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function emptyRow(array $extra): array
    {
        return [...$extra,
            'conversations_received' => 0, 'drafts_produced' => 0, 'conversations_with_draft' => 0, 'conversations_sent_as_is' => 0,
            'auto_sent' => 0, 'handovers' => 0, 'auto_send_decisions' => 0, 'handover_reasons' => [],
            'actions' => ['proposed' => 0, 'approved' => 0, 'unattended' => 0, 'succeeded' => 0, 'failed' => 0, 'rejected' => 0, 'refunded_amount' => 0.0],
            'feedback' => ['sent_as_is' => 0, 'sent_edited' => 0, 'gmail_reply' => 0, 'rejected' => 0],
            'pct_sum' => 0, 'pct_n' => 0, 'qa' => [], 'failure_classes' => [],
            'cost' => ['total' => 0, 'live' => 0, 'by_role' => [], 'by_purpose' => []],
            'resolved_conversations' => 0, 'ttfd' => ['values' => []],
        ];
    }

    /** @param  array<string, mixed>  $row */
    private static function inc(array &$row, string $path, float|int $value): void
    {
        $keys = explode('.', $path);
        $ref = &$row;
        foreach ($keys as $i => $key) {
            if ($i === count($keys) - 1) {
                if ($key === 'values') {
                    $ref[$key][] = $value;
                } else {
                    $ref[$key] = ($ref[$key] ?? 0) + $value;
                }
            } else {
                $ref[$key] ??= [];
                $ref = &$ref[$key];
            }
        }
    }

    private function conversations(): Builder
    {
        return DB::table('conversations as c')->where('c.workspace_id', $this->ws)->where('c.imported', false)
            ->whereNull('c.deleted_at')->where('c.status', '!=', 'spam');
    }

    private function liveRuns(): Builder
    {
        return DB::table('agent_runs as r')->join('conversations as c', 'c.id', '=', 'r.conversation_id')
            ->where('r.workspace_id', $this->ws)->where('c.imported', false)
            ->where('r.purpose', 'live')
            ->whereBetween('r.created_at', [$this->from, $this->to])
            ->when($this->playbookId !== null, fn ($q) => $q->where('r.playbook_id', $this->playbookId));
    }

    /** @return list<object{bucket: string, v: mixed}> */
    private function grouped(Builder $query, string $column, string $expr): array
    {
        $out = [];
        foreach ($query->selectRaw("{$this->bucket($column)} as bucket, {$expr} as v")->groupByRaw($this->bucket($column))->get() as $r) {
            $out[] = (object) ['bucket' => self::key($r->bucket), 'v' => $r->v];
        }

        return $out;
    }

    private function bucket(string $column): string
    {
        return "date_trunc('{$this->unit}', {$column})";
    }

    private static function key(mixed $bucket): string
    {
        return substr((string) $bucket, 0, 10);
    }

    private function keyOf(Carbon $at): string
    {
        return ($this->unit === 'week' ? $at->copy()->startOfWeek(Carbon::MONDAY) : $at->copy()->startOfDay())->toDateString();
    }

    /** @return list<string> */
    private function bucketKeys(): array
    {
        $keys = [];
        $cursor = Carbon::parse($this->keyOf($this->from));
        $guard = 0;
        while ($cursor->lte($this->to) && $guard++ < 800) {
            $keys[] = $cursor->toDateString();
            $this->unit === 'week' ? $cursor->addWeek() : $cursor->addDay();
        }

        return $keys;
    }

    /** @return array<string, string> */
    public static function definitions(): array
    {
        return [
            'conversations_received' => 'Conversations created in the period (not imported, not spam, not deleted). Not split by playbook.',
            'drafts_produced' => 'Live agent runs in the period that produced a reply (decision draft or auto_send).',
            'conversations_with_draft' => 'Distinct conversations with at least one such run in the period.',
            'conversations_sent_as_is' => 'Of those, conversations where a drafted run\'s reply was sent unchanged (feedback sent_as_is).',
            'automation_rate' => 'conversations_sent_as_is / conversations_with_draft.',
            'auto_sent' => 'Distinct conversations with an AI-sent message (threads.origin = ai, sent) in the period. Zero until auto-send runs.',
            'auto_sent_rate' => 'auto_sent / conversations_received.',
            'auto_send_decisions' => 'Live runs in the period the policy gate decided to auto-send (counted even if the send was later cancelled; compare auto_sent).',
            'actions' => 'Shopify action executions proposed in the period (live only, dated by proposal): proposed (all), approved (by a person or unattended), unattended, succeeded, failed, rejected (current status), refunded_amount = sum of amount on succeeded refund / cancel-order executions (shop currency).',
            'handovers' => 'Live runs in the period that handed over; handover_reasons groups them by reason (prefix before ":").',
            'feedback' => 'Feedback events in the period: sent_as_is, sent_edited, gmail_reply (staff answered in Gmail instead of using the draft), rejected.',
            'edit_rate' => 'sent_edited / (sent_as_is + sent_edited).',
            'avg_percent_changed' => 'Average percent changed of sent_edited events.',
            'gmail_reply_rate' => 'gmail_reply / (sent_as_is + sent_edited + gmail_reply + rejected).',
            'qa' => 'QA reviews by author type (ai, ai_edited, human), dated by when the reviewed reply was sent; dismissed / incorrect excluded. triage_avg = first-pass score (every reviewed reply), review_avg = full-review overall (flagged + sampled replies).',
            'cost' => 'Every AI call in the period by role (triage, writer, validator, simulation_judge, manager, history_extraction; "unattributed" = run cost without a per-step trace) and purpose (live, shadow, preview, simulation, test, qa, qa_proposals, manager_chat, feedback_routing, conflict_check, simulation_judge, mining).',
            'resolved_conversations' => 'Non-imported conversations now closed whose last update falls in the period (proxy for "resolved in the period").',
            'cost_per_resolved_usd' => 'cost.total / resolved_conversations. live_cost_per_resolved_usd uses live-run cost only.',
            'time_to_first_draft' => 'For the first drafted live run per customer message: run completed_at minus the message\'s sent time. Average and median seconds.',
        ];
    }
}
