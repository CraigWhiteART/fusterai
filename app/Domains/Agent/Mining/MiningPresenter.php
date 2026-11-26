<?php

namespace App\Domains\Agent\Mining;

use App\Domains\Agent\Mining\Models\MiningConversation;
use App\Domains\Agent\Mining\Models\MiningRun;
use App\Domains\Agent\Mining\Models\MiningSituation;
use App\Domains\Agent\Models\Proposal;

/** JSON shapes for the mining API (documented in docs/agent-mining.md). */
class MiningPresenter
{
    /** @return array<string, mixed> */
    public function summary(MiningRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status,
            'stage' => $run->stage,
            'pause_reason' => $run->pause_reason,
            'message' => $run->message,
            'options' => $run->options,
            'selected' => $run->counter('selected'),
            'cost_usd' => MiningRun::usd($run->cost_micro_usd),
            'budget_usd' => MiningRun::usd($run->budget_micro_usd),
            'created_by' => $run->user?->name,
            'created_at' => $run->created_at?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(MiningRun $run): array
    {
        $byStatus = MiningConversation::query()->where('run_id', $run->id)
            ->groupBy('status')->selectRaw('status, count(*) as n')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        $byOutcome = MiningConversation::query()->where('run_id', $run->id)->whereNotNull('outcome')
            ->groupBy('outcome')->selectRaw('outcome, count(*) as n')->pluck('n', 'outcome')->map(fn ($n) => (int) $n)->all();

        $situations = MiningSituation::query()->with('playbook:id,slug')->where('run_id', $run->id)
            ->orderByDesc('conversation_count')->orderBy('id')->get();
        $clustered = max(1, (int) $situations->sum('conversation_count'));
        $proposals = (array) $run->result('proposals', []);
        $proposalStatus = $this->proposalStatuses($proposals);

        $usage = [];
        foreach (MiningRun::LLM_STAGES as $stage) {
            $row = (array) (($run->usage ?? [])[$stage] ?? []);
            $usage[$stage] = [
                'calls' => (int) ($row['calls'] ?? 0),
                'cache_hits' => (int) ($row['cache_hits'] ?? 0),
                'tokens_in' => (int) ($row['tokens_in'] ?? 0),
                'tokens_out' => (int) ($row['tokens_out'] ?? 0),
                'cost_usd' => MiningRun::usd((int) ($row['cost_micro_usd'] ?? 0)),
            ];
        }

        $inconsistencies = $run->result('inconsistencies');
        if (! is_array($inconsistencies)) {
            $inconsistencies = [];
            foreach ($situations as $s) {
                foreach ((array) ($s->synthesis['inconsistencies'] ?? []) as $inc) {
                    $inconsistencies[] = $inc + ['situation' => $s->key, 'situation_name' => $s->name];
                }
            }
        }

        $voice = $run->result('voice');

        return $this->summary($run) + [
            'stages' => MiningRun::STAGES,
            'stage_index' => (int) array_search($run->stage, MiningRun::STAGES, true),
            'last_error' => $run->last_error,
            'last_error_at' => $run->last_error_at?->toIso8601String(),
            'error_count' => $run->error_count,
            'heartbeat_at' => $run->heartbeat_at?->toIso8601String(),
            'cost_micro_usd' => $run->cost_micro_usd,
            'remaining_budget_usd' => MiningRun::usd(max(0, $run->remainingBudgetMicroUsd())),
            'tokens' => ['in' => $run->tokens_in, 'out' => $run->tokens_out],
            'usage' => $usage,
            'progress' => [
                'eligible' => $run->counter('eligible'),
                'selected' => $run->counter('selected'),
                'pending' => $byStatus[MiningConversation::STATUS_PENDING] ?? 0,
                'done' => $byStatus[MiningConversation::STATUS_DONE] ?? 0,
                'failed' => $byStatus[MiningConversation::STATUS_FAILED] ?? 0,
                'extracted_new' => $byOutcome[MiningConversation::OUTCOME_NEW] ?? 0,
                'reused' => $byOutcome[MiningConversation::OUTCOME_REUSED] ?? 0,
                'macro_copies' => $byOutcome[MiningConversation::OUTCOME_MACRO] ?? 0,
                'situations' => $situations->count(),
                'situations_pending' => $situations->where('status', MiningSituation::STATUS_PENDING)->count(),
                'situations_synthesised' => $situations->where('status', MiningSituation::STATUS_SYNTHESISED)->count(),
                'situations_failed' => $situations->where('status', MiningSituation::STATUS_FAILED)->count(),
                'proposals_emitted' => count($proposals),
            ],
            'selected_by_month' => (array) (($run->counters ?? [])['selected_by_month'] ?? []),
            'dropped' => (array) (($run->counters ?? [])['dropped'] ?? []),
            'counters' => $run->counters ?? [],
            'clustering' => array_intersect_key((array) $run->result('clustering', []), array_flip(['method', 'labels', 'new_labels'])),
            'coverage' => $situations->map(function (MiningSituation $s) use ($clustered, $proposals, $proposalStatus) {
                $syn = (array) ($s->synthesis ?? []);
                $p = $proposals['situation:'.$s->key] ?? null;

                return [
                    'key' => $s->key,
                    'name' => $s->name,
                    'description' => $s->description,
                    'playbook_id' => $s->playbook_id,
                    'playbook_slug' => $s->playbook?->slug,
                    'is_new' => $s->playbook_id === null,
                    'conversation_count' => $s->conversation_count,
                    'share' => round($s->conversation_count / $clustered, 4),
                    'status' => $s->status,
                    'labels' => $s->labels,
                    'error' => $s->error,
                    'synthesis' => $s->synthesis === null ? null : [
                        'rules' => count((array) ($syn['rules'] ?? [])),
                        'steps' => count((array) ($syn['steps'] ?? [])),
                        'knowledge' => count((array) ($syn['knowledge'] ?? [])),
                        'inconsistencies' => count((array) ($syn['inconsistencies'] ?? [])),
                        'test_cases' => count((array) ($syn['test_cases'] ?? [])),
                        'suggested_actions' => array_values(array_unique(array_column((array) ($syn['suggested_actions'] ?? []), 'action'))),
                    ],
                    'proposal' => $p === null ? null : $this->proposalRef($p, $proposalStatus),
                ];
            })->values()->all(),
            'inconsistencies' => $inconsistencies,
            'voice' => is_array($voice) ? [
                'sample_size' => $voice['sample_size'] ?? 0,
                'staff' => $voice['staff'] ?? [],
                'staff_differences' => $voice['staff_differences'] ?? [],
                'example_phrases' => $voice['example_phrases'] ?? [],
                'proposal' => isset($proposals['voice']) ? $this->proposalRef($proposals['voice'], $proposalStatus) : null,
            ] : null,
            'proposals' => array_map(fn ($p) => $this->proposalRef($p, $proposalStatus), $proposals),
            'estimate' => $run->result('estimate'),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $proposals
     * @return array<int, string>
     */
    private function proposalStatuses(array $proposals): array
    {
        $ids = array_values(array_filter(array_map(fn ($p) => $p['proposal_id'] ?? null, $proposals)));

        return $ids === [] ? [] : Proposal::query()->whereIn('id', $ids)->pluck('status', 'id')->all();
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<int, string>  $statuses
     * @return array<string, mixed>
     */
    private function proposalRef(array $p, array $statuses): array
    {
        $id = isset($p['proposal_id']) ? (int) $p['proposal_id'] : null;

        return [
            'action' => $p['action'] ?? null,
            'proposal_id' => $id,
            'status' => $id !== null ? ($statuses[$id] ?? $p['status'] ?? null) : null,
            'reason' => $p['reason'] ?? null,
            'errors' => $p['errors'] ?? [],
        ];
    }
}
