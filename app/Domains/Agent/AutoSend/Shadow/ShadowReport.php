<?php

namespace App\Domains\Agent\AutoSend\Shadow;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Models\ShadowComparison;
use App\Domains\Conversation\Models\Thread;
use App\Support\PlainText;

/**
 * GET /agent/autosend/shadow-report: is the agent ready to go live?
 * Read-only aggregates over shadow runs and their comparisons.
 */
class ShadowReport
{
    /** @return array<string, mixed> */
    public function build(int $workspaceId, int $days = 30, int $worst = 10): array
    {
        $since = now()->subDays($days)->startOfDay();

        $runs = AgentRun::where('workspace_id', $workspaceId)
            ->where('purpose', AgentRun::PURPOSE_SHADOW)
            ->where('status', AgentRun::STATUS_COMPLETED)
            ->where('created_at', '>=', $since)
            ->get(['id', 'playbook_id', 'decision', 'auto_send']);

        $comparisons = ShadowComparison::where('workspace_id', $workspaceId)
            ->where('created_at', '>=', $since)
            ->get();

        $playbooks = Playbook::where('workspace_id', $workspaceId)->get(['id', 'slug', 'name', 'mode', 'rollout_percent'])->keyBy('id');

        $per = [];
        $row = function (?int $playbookId) use (&$per, $playbooks) {
            $key = $playbookId ?? 0;
            $per[$key] ??= [
                'playbook' => $playbookId !== null && isset($playbooks[$playbookId])
                    ? ['id' => $playbookId, 'slug' => $playbooks[$playbookId]->slug, 'name' => $playbooks[$playbookId]->name, 'mode' => $playbooks[$playbookId]->mode, 'rollout_percent' => $playbooks[$playbookId]->rollout_percent]
                    : null,
                'runs' => 0, 'drafts' => 0, 'handovers' => 0, 'would_auto_send' => 0, 'eligible_if_auto' => 0,
                'compared' => 0, 'agreed' => 0, 'agreement_rate' => null, 'avg_score' => null,
                'would_auto_send_compared' => 0, 'would_auto_send_agreed' => 0, 'would_auto_send_agreement_rate' => null,
                '_scores' => [],
            ];

            return $key;
        };

        foreach ($runs as $run) {
            $k = $row($run->playbook_id);
            $auto = (array) ($run->auto_send ?? []);
            $per[$k]['runs']++;
            $per[$k]['handovers'] += $run->decision === 'handover' ? 1 : 0;
            $per[$k]['drafts'] += $run->decision !== 'handover' ? 1 : 0;
            $per[$k]['would_auto_send'] += ! empty($auto['would_auto_send']) ? 1 : 0;
            $per[$k]['eligible_if_auto'] += ! empty($auto['eligible_if_auto']) ? 1 : 0;
        }

        foreach ($comparisons->where('status', ShadowComparison::STATUS_OK) as $c) {
            $k = $row($c->playbook_id);
            $per[$k]['compared']++;
            $per[$k]['agreed'] += $c->agreed ? 1 : 0;
            if ($c->score !== null) {
                $per[$k]['_scores'][] = $c->score;
            }
            if ($c->would_auto_send) {
                $per[$k]['would_auto_send_compared']++;
                $per[$k]['would_auto_send_agreed'] += $c->agreed ? 1 : 0;
            }
        }

        foreach ($per as &$p) {
            $p['agreement_rate'] = $p['compared'] > 0 ? round($p['agreed'] / $p['compared'], 3) : null;
            $p['would_auto_send_agreement_rate'] = $p['would_auto_send_compared'] > 0 ? round($p['would_auto_send_agreed'] / $p['would_auto_send_compared'], 3) : null;
            $p['avg_score'] = $p['_scores'] !== [] ? round(array_sum($p['_scores']) / count($p['_scores']), 2) : null;
            unset($p['_scores']);
        }
        unset($p);

        $ok = $comparisons->where('status', ShadowComparison::STATUS_OK);
        $worstRows = $ok->filter(fn (ShadowComparison $c) => $c->agreed === false)
            ->sortBy(fn (ShadowComparison $c) => [$c->would_auto_send ? 0 : 1, $c->score ?? 0])
            ->take($worst)
            ->values();
        $runsById = AgentRun::whereIn('id', $worstRows->pluck('run_id'))->get(['id', 'draft', 'decision', 'handover_reason', 'customer_thread_id'])->keyBy('id');
        $threads = Thread::whereIn('id', $worstRows->pluck('human_thread_id')->filter())->get(['id', 'body', 'body_plain'])->keyBy('id');

        $autoOk = $ok->filter(fn (ShadowComparison $c) => (bool) $c->would_auto_send);

        return [
            'window_days' => $days,
            'since' => $since->toIso8601String(),
            'totals' => [
                'shadow_runs' => $runs->count(),
                'would_auto_send' => $runs->filter(fn ($r) => ! empty(($r->auto_send ?? [])['would_auto_send']))->count(),
                'eligible_if_auto' => $runs->filter(fn ($r) => ! empty(($r->auto_send ?? [])['eligible_if_auto']))->count(),
                'compared' => $ok->count(),
                'agreed' => $ok->where('agreed', true)->count(),
                'agreement_rate' => $ok->count() > 0 ? round($ok->where('agreed', true)->count() / $ok->count(), 3) : null,
                // Same as the per-playbook keys: comparisons of runs that would have auto-sent.
                'would_auto_send_compared' => $autoOk->count(),
                'would_auto_send_agreed' => $autoOk->where('agreed', true)->count(),
                'would_auto_send_agreement_rate' => $autoOk->count() > 0 ? round($autoOk->where('agreed', true)->count() / $autoOk->count(), 3) : null,
                'skipped' => $comparisons->where('status', ShadowComparison::STATUS_SKIPPED)->count(),
                'failed' => $comparisons->where('status', ShadowComparison::STATUS_FAILED)->count(),
                'cost_micro_usd' => (int) $comparisons->sum('cost_micro_usd'),
                'cost_usd' => round($comparisons->sum('cost_micro_usd') / 1_000_000, 4),
            ],
            'playbooks' => array_values($per),
            'worst_disagreements' => $worstRows->map(function (ShadowComparison $c) use ($runsById, $threads) {
                $run = $runsById[$c->run_id] ?? null;
                $human = $c->human_thread_id !== null ? ($threads[$c->human_thread_id] ?? null) : null;
                $judge = (array) (($c->scores ?? [])['judge'] ?? []);

                return [
                    'comparison_id' => $c->id,
                    'run_id' => $c->run_id,
                    'conversation_id' => $c->conversation_id,
                    'playbook_id' => $c->playbook_id,
                    'would_auto_send' => $c->would_auto_send,
                    'score' => $c->score,
                    'judge' => [
                        'outcome_match' => $judge['outcome_match'] ?? null,
                        'policy_consistency' => $judge['policy_consistency'] ?? null,
                        'factual_grounding' => $judge['factual_grounding'] ?? null,
                        'tone_match' => $judge['tone_match'] ?? null,
                        'would_send' => $judge['would_send'] ?? null,
                        'reason' => $judge['reason'] ?? null,
                    ],
                    'ai_decision' => $run?->decision,
                    'ai_handover_reason' => $run?->handover_reason,
                    'ai_reply' => $run?->draft !== null ? mb_substr((string) $run->draft, 0, 1500) : null,
                    'human_reply' => $human !== null ? mb_substr(PlainText::from($human->body_plain ?: $human->body), 0, 1500) : null,
                    'created_at' => $c->created_at?->toIso8601String(),
                ];
            })->all(),
            'sampling' => [
                'sample_rate' => (float) config('agent.auto_send.shadow.sample_rate', 1.0),
                'daily_cap' => (int) config('agent.auto_send.shadow.daily_cap', 50),
            ],
        ];
    }
}
