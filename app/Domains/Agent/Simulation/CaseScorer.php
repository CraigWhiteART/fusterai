<?php

namespace App\Domains\Agent\Simulation;

use App\Domains\Agent\Agents\SimulationJudgeAgent;
use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\AgentTestCase;
use App\Domains\Agent\Models\FrozenCase;
use App\Domains\Agent\Pipeline\InstructionSet;
use App\Domains\Agent\Pipeline\PolicyGate;
use App\Domains\Agent\Pipeline\UntrustedData;
use App\Domains\Agent\Pipeline\UsageMeter;
use App\Services\AiSettingsService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scores one simulated run: deterministic expectations first (must / must
 * not include, expected outcome and handover reason, expected actions),
 * then the `simulation_judge` model comparing the reply with what the human
 * actually sent. When evidence is below `recorded`, the judge scores
 * outcome, policy and tone and factual differences are not penalised.
 *
 * passed = expectations met AND judge would_send AND outcome, policy (and,
 * with recorded evidence, facts) >= 3. Judge failure => passed null (error),
 * unless an expectation already failed.
 */
class CaseScorer
{
    public const VERSION = 'scorer-v1';

    public function __construct(
        private readonly AiSettingsService $ai,
    ) {}

    /** @return array<string, mixed> */
    public function score(FrozenCase $case, ?AgentTestCase $testCase, AgentRun $run, InstructionSet $instructions): array
    {
        $deterministic = $this->expectations($testCase, $run);
        $judge = $this->judge($case, $testCase, $run, $instructions);

        $factsCount = $case->evidence_quality === FrozenCase::QUALITY_RECORDED;
        $judgeOk = $judge['status'] === 'ok';
        $judgePassed = $judgeOk
            && $judge['would_send'] === true
            && $judge['outcome_match'] >= 3
            && $judge['policy_consistency'] >= 3
            && (! $factsCount || ($judge['factual_grounding'] ?? 3) >= 3);

        $passed = match (true) {
            $deterministic['passed'] === false => false,
            ! $judgeOk => null,
            default => $judgePassed,
        };

        return [
            'version' => self::VERSION,
            'outcome' => $run->decision,
            'handover_reason' => $run->handover_reason,
            'evidence_quality' => $case->evidence_quality,
            'facts_scored' => $factsCount,
            'deterministic' => $deterministic,
            'judge' => $judge,
            'passed' => $passed,
            'cost_micro_usd' => (int) ($judge['cost_micro_usd'] ?? 0),
        ];
    }

    /** @return array{checked: bool, passed: bool|null, failures: list<array{check: string, detail: string}>} */
    public function expectations(?AgentTestCase $testCase, AgentRun $run): array
    {
        if ($testCase === null) {
            return ['checked' => false, 'passed' => null, 'failures' => []];
        }
        $e = AgentTestCase::normalizeExpectation($testCase->expectation);
        $draft = mb_strtolower((string) $run->draft);
        $failures = [];

        foreach ($e['must_include'] as $phrase) {
            if (! str_contains($draft, mb_strtolower($phrase))) {
                $failures[] = ['check' => 'must_include', 'detail' => "The reply does not contain \"{$phrase}\"."];
            }
        }
        foreach ($e['must_not_include'] as $phrase) {
            if (str_contains($draft, mb_strtolower($phrase))) {
                $failures[] = ['check' => 'must_not_include', 'detail' => "The reply contains \"{$phrase}\"."];
            }
        }
        if ($e['expected_outcome'] !== null && $run->decision !== $e['expected_outcome']) {
            $failures[] = ['check' => 'expected_outcome', 'detail' => "Expected {$e['expected_outcome']}, got ".($run->decision ?? 'nothing').($run->handover_reason ? " ({$run->handover_reason})" : '').'.'];
        }
        if ($e['expected_handover_reason'] !== null && ! str_starts_with((string) $run->handover_reason, $e['expected_handover_reason'])) {
            $failures[] = ['check' => 'expected_handover_reason', 'detail' => "Expected handover {$e['expected_handover_reason']}, got ".($run->handover_reason ?? 'none').'.'];
        }
        $proposed = array_values(array_map(fn ($a) => (string) ($a['action'] ?? ''), array_filter((array) ($run->proposed_actions ?? []), fn ($a) => ($a['status'] ?? '') === 'proposed')));
        foreach ($e['expected_actions'] as $action) {
            if (! in_array($action, $proposed, true)) {
                $failures[] = ['check' => 'expected_actions', 'detail' => "Expected the agent to propose \"{$action}\"."];
            }
        }

        $checked = $e['must_include'] !== [] || $e['must_not_include'] !== [] || $e['expected_outcome'] !== null
            || $e['expected_handover_reason'] !== null || $e['expected_actions'] !== [];

        return ['checked' => $checked, 'passed' => $checked ? $failures === [] : null, 'failures' => $failures];
    }

    /** @return array<string, mixed> */
    private function judge(FrozenCase $case, ?AgentTestCase $testCase, AgentRun $run, InstructionSet $instructions): array
    {
        $untrusted = new UntrustedData;
        $factsCount = $case->evidence_quality === FrozenCase::QUALITY_RECORDED;
        $system = implode("\n", array_filter([
            'You grade a customer-support AI agent on a past ticket. You see the conversation up to the customer\'s message, what the human support agent actually replied, and what the AI did.',
            'Score 1-5 each: outcome_match (same decision as the human: answered vs handed over, refund vs not, asked for the same information), policy_consistency (consistent with the brand rules below and with what the human allowed or refused), factual_grounding (facts the AI states are supported by the evidence it had), tone_match (brand voice and the human\'s tone).',
            'would_send: true only if a careful human would send the AI reply with at most trivial edits. A handover is "sendable" when the human also could not have answered without more information or a decision.',
            $factsCount ? null : 'IMPORTANT: the order / carrier evidence for this past case was reconstructed or is missing. Do NOT penalise factual differences from the human reply (dates, statuses, tracking details); score factual_grounding 3 and judge outcome, policy and tone only.',
            'The human reply is the reference, not gospel: if the human broke a brand rule, the AI following the rule is correct.',
            'Brand voice: '.$instructions->voice->personality,
            $instructions->voice->dontTexts() !== [] ? 'Never say: '.implode('; ', $instructions->voice->dontTexts()) : null,
            'Return the scores and one or two sentences of reasoning.',
        ]));

        $transcript = [];
        foreach (array_slice($case->transcript, -8) as $turn) {
            $transcript[] = strtoupper((string) ($turn['role'] ?? 'customer')).' ('.($turn['at'] ?? '').'): '.mb_substr((string) ($turn['text'] ?? ''), 0, 2500);
        }
        $expect = $testCase !== null ? AgentTestCase::normalizeExpectation($testCase->expectation) : null;
        $ai = in_array($run->decision, [PolicyGate::DRAFT, PolicyGate::AUTO_SEND], true)
            ? "AI REPLY:\n".(string) $run->draft
            : 'AI HANDED OVER to a human (reason: '.($run->handover_reason ?? 'unknown').'). Internal note: '.mb_substr((string) $run->internal_note, 0, 1500);

        $user = implode("\n\n", array_filter([
            $untrusted->preamble(),
            'CONVERSATION (up to the customer message being answered):'."\n".$untrusted->block('transcript', implode("\n\n", $transcript)),
            'HUMAN REPLY (reference):'."\n".$untrusted->block('human_reply', $case->human_reply !== null ? mb_substr($case->human_reply, 0, 4000) : '(no human reply was recorded: judge against the brand rules only)'),
            $untrusted->block('ai_output', $ai),
            'EVIDENCE QUALITY: '.$case->evidence_quality,
            $expect !== null && $expect['notes'] !== null ? 'REVIEWER NOTES FOR THIS CASE: '.$expect['notes'] : null,
        ]));

        try {
            $response = $this->ai->withWorkspaceCredentials(
                $case->workspace_id,
                fn ($lab, $model, $options = []) => (new SimulationJudgeAgent($system))
                    ->withProviderOptions($options)
                    ->prompt($user, provider: $lab, model: $model),
                task: 'simulation_judge',
            );
            $data = method_exists($response, 'toArray') ? $response->toArray() : [];
            $clamp = fn ($v) => max(1, min(5, (int) ($v ?? 1)));

            return [
                'status' => 'ok',
                'model' => $response->meta->model ?? null,
                'outcome_match' => $clamp($data['outcome_match'] ?? null),
                'policy_consistency' => $clamp($data['policy_consistency'] ?? null),
                'factual_grounding' => $factsCount ? $clamp($data['factual_grounding'] ?? null) : null,
                'tone_match' => $clamp($data['tone_match'] ?? null),
                'would_send' => (bool) ($data['would_send'] ?? false),
                'reason' => mb_substr((string) ($data['reason'] ?? ''), 0, 1000),
                'cost_micro_usd' => UsageMeter::microUsd($response->meta->model ?? null, $response->usage),
            ];
        } catch (Throwable $e) {
            Log::warning('Simulation judge failed', ['case_id' => $case->id, 'error' => $e->getMessage()]);

            return ['status' => 'failed', 'error' => class_basename($e).': '.mb_substr($e->getMessage(), 0, 300), 'cost_micro_usd' => 0];
        }
    }
}
