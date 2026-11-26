<?php

namespace App\Domains\Agent\Http\Presenters;

use App\Domains\Agent\Feedback\PreviewService;
use App\Domains\Agent\Models\FeedbackEvent;
use App\Domains\Agent\Models\KnowledgeItem;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Models\Proposal;
use App\Domains\Agent\Models\ProposalChange;
use App\Domains\Agent\Models\Simulation;
use App\Domains\Agent\Models\SimulationResult;

/**
 * JSON shapes of the feedback-loop API. Documented field by field in
 * docs/agent-proposals.md ("HTTP API"); keep them in sync.
 */
final class LoopPresenter
{
    /** @return array<string, mixed> */
    public static function feedback(FeedbackEvent $event): array
    {
        $meta = (array) ($event->meta ?? []);
        $routing = (array) ($meta['routing'] ?? []);
        $proposal = isset($meta['proposal_id']) ? Proposal::with('changes')->find($meta['proposal_id']) : null;

        return [
            'id' => $event->id,
            'conversation_id' => $event->conversation_id,
            'run_id' => $event->run_id,
            'user_id' => $event->user_id,
            'kind' => $event->kind,
            'text' => $event->text,
            'selected_text' => $meta['selected_text'] ?? null,
            // pending | question | one_off | proposal | suggestions_only | failed
            'status' => $routing['status'] ?? 'pending',
            'scope' => $routing['scope'] ?? $routing['forced_scope'] ?? null,
            'scope_overridden' => (bool) ($routing['overridden'] ?? false) || ($routing['forced_scope'] ?? null) !== null,
            'reason' => $routing['reason'] ?? null,
            'question' => $routing['question'] ?? null,
            'answer' => $routing['answer'] ?? null,
            'one_off_instruction' => $routing['one_off_instruction'] ?? null,
            'target' => $routing['target'] ?? null,
            'suggestions' => array_values((array) ($proposal !== null ? ($proposal->suggestions ?? []) : ($routing['suggestions'] ?? []))),
            'error' => $routing['error'] ?? null,
            'scopes' => ['one_off', 'voice', 'playbook', 'knowledge', 'example'],
            'proposal' => $proposal !== null ? self::proposal($proposal) : null,
            'preview' => $proposal !== null
                ? PreviewService::present($proposal->preview, self::flatDiff($proposal))
                : PreviewService::present($meta['preview'] ?? null, []),
            'created_at' => $event->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function proposal(Proposal $proposal, bool $withPreview = false): array
    {
        $proposal->loadMissing('changes');
        $gate = $proposal->gate_simulation_id !== null ? Simulation::find($proposal->gate_simulation_id) : null;
        $conflicts = $proposal->conflicts;

        return [
            'id' => $proposal->id,
            'origin' => $proposal->origin,
            'status' => $proposal->status,
            'title' => $proposal->title,
            'rationale' => $proposal->rationale,
            'scope' => $proposal->scope,
            'evidence' => $proposal->evidence,
            'conversation_id' => $proposal->conversation_id,
            'run_id' => $proposal->run_id,
            'feedback_id' => $proposal->feedback_event_id,
            'base_config_hash' => $proposal->base_config_hash,
            'changes' => $proposal->changes->map(fn (ProposalChange $c) => self::change($c))->values()->all(),
            'diff' => self::flatDiff($proposal),
            'suggestions' => array_values((array) ($proposal->suggestions ?? [])),
            'conflicts' => $conflicts === null ? null : [
                'checked_at' => $conflicts['checked_at'] ?? null,
                'llm' => $conflicts['llm'] ?? null,
                'items' => array_values((array) ($conflicts['items'] ?? [])),
                'example_flags' => array_values((array) ($conflicts['example_flags'] ?? [])),
                'summary' => $conflicts['summary'] ?? null,
                'precedence' => $conflicts['precedence'] ?? null,
            ],
            'blocking_conflicts' => count($proposal->blockingConflicts()),
            'can_approve' => in_array($proposal->status, [Proposal::STATUS_DRAFT, Proposal::STATUS_READY], true)
                && $proposal->conflicts_checked_at !== null && $proposal->blockingConflicts() === []
                && ($gate === null || $gate->isFinished() || $gate->status === Simulation::STATUS_PAUSED),
            'gate' => $gate !== null ? self::simulation($gate) : null,
            'preview' => $withPreview ? PreviewService::present($proposal->preview, self::flatDiff($proposal)) : ($proposal->preview ?? null),
            'applied' => $proposal->applied,
            // Partial approval: items the reviewer left out (restorable while open).
            'excluded' => array_values((array) ($proposal->excluded ?? [])),
            'review_note' => $proposal->review_note,
            'created_by' => $proposal->created_by,
            'reviewed_by' => $proposal->reviewed_by,
            'reviewed_at' => $proposal->reviewed_at?->toIso8601String(),
            'applied_at' => $proposal->applied_at?->toIso8601String(),
            'reverted_at' => $proposal->reverted_at?->toIso8601String(),
            'created_at' => $proposal->created_at?->toIso8601String(),
            'updated_at' => $proposal->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function change(ProposalChange $change): array
    {
        return [
            'id' => $change->id,
            'position' => $change->position,
            'target_type' => $change->target_type,
            'target_id' => $change->target_id,
            'target_label' => self::targetLabel($change),
            'base_version_id' => $change->base_version_id,
            'ops' => $change->ops,
            'diff' => array_values((array) ($change->diff ?? [])),
            'before' => $change->before,
            'after' => $change->after,
        ];
    }

    /**
     * Every diff entry of the proposal with its target, for the side-by-side view.
     *
     * @return list<array<string, mixed>>
     */
    public static function flatDiff(Proposal $proposal): array
    {
        $out = [];
        foreach ($proposal->changes as $change) {
            foreach ((array) ($change->diff ?? []) as $entry) {
                $out[] = [...$entry, 'change_id' => $change->id, 'target_type' => $change->target_type, 'target_id' => $change->target_id, 'target_label' => self::targetLabel($change)];
            }
        }

        return $out;
    }

    public static function targetLabel(ProposalChange $change): string
    {
        return match ($change->target_type) {
            ProposalChange::TARGET_VOICE => 'Brand voice',
            ProposalChange::TARGET_PLAYBOOK => 'Playbook: '.($change->target_id !== null
                ? (Playbook::find($change->target_id)->name ?? '#'.$change->target_id)
                : ($change->after['name'] ?? 'new').' (new)'),
            ProposalChange::TARGET_KNOWLEDGE => $change->target_id !== null
                ? 'Knowledge: '.(KnowledgeItem::find($change->target_id)->title ?? '#'.$change->target_id)
                : 'New knowledge'.(! empty($change->after['title']) ? ': '.$change->after['title'] : ''),
            ProposalChange::TARGET_EXAMPLE => 'Example X'.$change->target_id,
            default => 'Test case',
        };
    }

    /** @return array<string, mixed> */
    public static function simulation(Simulation $simulation): array
    {
        return $simulation->toUiArray();
    }

    /** @return array<string, mixed> */
    public static function result(SimulationResult $result): array
    {
        $result->loadMissing(['frozenCase', 'testCase.playbook', 'run.playbook', 'baselineRun']);
        $scores = (array) ($result->scores ?? []);
        $brief = fn (?Playbook $p) => $p !== null ? ['id' => $p->id, 'slug' => $p->slug, 'name' => $p->name] : null;
        // The playbook the case belongs to (its test case's), else the one the agent chose.
        $playbook = $result->testCase?->playbook;
        $playbook ??= $result->run?->playbook;

        return [
            'id' => $result->id,
            'simulation_id' => $result->simulation_id,
            'frozen_case_id' => $result->frozen_case_id,
            'test_case_id' => $result->test_case_id,
            'test_case_name' => $result->testCase?->name,
            'playbook' => $brief($playbook),
            'ai_playbook' => $brief($result->run?->playbook),
            'conversation_id' => $result->frozenCase->conversation_id,
            'subject' => $result->frozenCase->subject,
            'evidence_quality' => $result->frozenCase->evidence_quality,
            'customer_message' => $result->frozenCase->toUiArray()['customer_message'] ?? null,
            'human_reply' => $result->frozenCase->human_reply,
            'agent_run_id' => $result->agent_run_id,
            'baseline_run_id' => $result->baseline_run_id,
            'rerun_run_id' => $result->rerun_run_id,
            'ai_reply' => $result->run?->draft,
            'ai_decision' => $result->run?->decision,
            'ai_handover_reason' => $result->run?->handover_reason,
            'baseline_reply' => $result->baselineRun?->draft,
            'baseline_decision' => $result->baselineRun?->decision,
            'scores' => [
                'judge' => $scores['judge'] ?? null,
                'deterministic' => $scores['deterministic'] ?? null,
                'facts_scored' => $scores['facts_scored'] ?? null,
                'rerun' => $scores['rerun'] ?? null,
            ],
            'baseline_scores' => $result->baseline_scores !== null ? [
                'judge' => $result->baseline_scores['judge'] ?? null,
                'deterministic' => $result->baseline_scores['deterministic'] ?? null,
            ] : null,
            'passed' => $result->passed,
            'baseline_passed' => $result->baseline_passed,
            'verdict' => $result->verdict,
            'effective_verdict' => $result->effectiveVerdict(),
            'judge_notes' => $result->judge_notes,
            'cached' => $result->cached,
            'human_override' => $result->human_override,
            'human_override_note' => $result->human_override_note,
            'human_override_by' => $result->human_override_by,
            'cost_micro_usd' => $result->cost_micro_usd,
            'error' => $result->error,
            'created_at' => $result->created_at?->toIso8601String(),
        ];
    }
}
