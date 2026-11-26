<?php

namespace App\Domains\Agent\Qa;

use App\Domains\Agent\Agents\QaReviewAgent;
use App\Domains\Agent\Agents\QaTriageAgent;
use App\Domains\Agent\Mining\Pii\PiiScrubber;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Models\VoiceVersion;
use App\Domains\Agent\Pipeline\TranscriptTurn;
use App\Domains\Agent\Pipeline\UntrustedData;
use App\Domains\Agent\Pipeline\UsageMeter;
use App\Domains\Agent\Qa\Models\QaReview;
use App\Services\AiSettingsService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one QA review in two passes for cost: a cheap first pass (`triage`
 * role) scores the reply 1-5; below `agent_qa.first_pass.threshold` it is
 * flagged for the full review (`simulation_judge` role), and a random
 * `full_review_sample_rate` of the unflagged ones gets the full review too
 * (an unbiased sample for averages). Forced reviews skip the first pass.
 *
 * Only data already in the database is used. System prompts hold only the
 * fixed instructions plus human-approved voice / playbook content; the
 * conversation and evidence are untrusted data in the user message.
 */
class QaReviewer
{
    /** @var (callable(): float)|null */
    public static $random = null;

    public function __construct(
        private readonly AiSettingsService $ai,
        private readonly QaCaseBuilder $cases,
        private readonly GapGrouper $gaps,
    ) {}

    public function review(QaReview $review): QaReview
    {
        if (! in_array($review->status, [QaReview::STATUS_PENDING, QaReview::STATUS_TRIAGED], true)) {
            return $review;
        }
        $conversation = $review->conversation()->withTrashed()->first();
        if ($conversation === null) {
            return $this->skip($review, 'spam');
        }
        $case = $this->cases->build($conversation, requireClosed: ! $review->forced, reviewedThreadId: $review->reviewed_thread_id);
        if ($case['skip'] !== null) {
            return $this->skip($review, $case['skip'] === 'not_closed' ? 'reopened' : $case['skip']);
        }

        $run = $case['run'];
        $review->forceFill([
            'customer_thread_id' => $case['customer']->id,
            'author_type' => $case['author_type'],
            'author_user_id' => $case['author_user_id'],
            'run_id' => $run?->id,
            'playbook_id' => $review->playbook_id ?? $run?->playbook_id,
            'evidence_source' => $case['evidence_source'],
            'replied_at' => $case['reply']->at,
        ]);

        $untrusted = new UntrustedData;
        $data = $this->dataMessage($case, $untrusted);

        try {
            if (! $review->forced && $review->status === QaReview::STATUS_PENDING) {
                $flagged = $this->firstPass($review, $data, $untrusted);
                $sampled = ! $flagged && $this->random() < (float) config('agent_qa.full_review_sample_rate', 0.15);
                if (! $flagged && ! $sampled) {
                    $review->forceFill(['status' => QaReview::STATUS_TRIAGED, 'reviewed_at' => now()])->save();

                    return $review;
                }
                $review->sampled = $sampled;
            }
            $this->fullReview($review, $data);
        } catch (Throwable $e) {
            Log::warning('QA review failed', ['review_id' => $review->id, 'error' => $e->getMessage()]);
            $review->forceFill(['status' => QaReview::STATUS_FAILED, 'error' => class_basename($e).': '.mb_substr($e->getMessage(), 0, 500)])->save();

            return $review;
        }

        if (in_array($review->failure_class, QaReview::GAP_CLASSES, true)) {
            $this->gaps->attach($review);
        }

        return $review->fresh() ?? $review;
    }

    private function skip(QaReview $review, string $reason): QaReview
    {
        $review->forceFill(['status' => QaReview::STATUS_SKIPPED, 'skip_reason' => $reason])->save();

        return $review;
    }

    private function random(): float
    {
        return self::$random !== null ? (float) (self::$random)() : mt_rand() / mt_getrandmax();
    }

    private function firstPass(QaReview $review, string $data, UntrustedData $untrusted): bool
    {
        $ws = $review->workspace_id;
        $catalogue = Playbook::with('liveVersion')->where('workspace_id', $ws)->selectable()->orderBy('slug')->get();
        $slugs = $catalogue->pluck('slug')->all();
        $system = implode("\n", [
            'You do quick quality control of customer-support replies for a small online store. You see a closed conversation and ONE staff reply to review (it may have been written by a person or by an AI agent).',
            'Score the reviewed reply 1-5: 5 = a careful senior agent would send it unchanged; 4 = fine with trivial nits; 3 = noticeable problems; 2 = wrong or unhelpful in an important way; 1 = harmful or badly wrong.',
            'Judge: did it answer what the customer actually asked, is it consistent with how the store handles this kind of case, are its facts supported by the evidence, is the tone right. Be strict about invented facts and broken promises.',
            'Also name the playbook (situation) this case falls under, from this list, or none:',
            ...$catalogue->map(fn (Playbook $p) => "- {$p->slug}: ".mb_substr((string) $p->liveVersion?->when_text, 0, 200))->all(),
            $untrusted->preamble(),
        ]);

        $response = $this->ai->withWorkspaceCredentials(
            $ws,
            fn ($lab, $model, $options = []) => (new QaTriageAgent($system, $slugs))
                ->withProviderOptions($options)
                ->prompt($data, provider: $lab, model: $model),
            task: 'triage',
        );
        $out = method_exists($response, 'toArray') ? $response->toArray() : [];
        $score = max(1, min(5, (int) ($out['score'] ?? 1)));
        $cost = UsageMeter::microUsd($response->meta->model ?? null, $response->usage);
        $flagged = $score < (int) config('agent_qa.first_pass.threshold', 4);
        $slug = in_array($out['playbook'] ?? null, $slugs, true) ? (string) $out['playbook'] : null;

        if ($review->playbook_id === null && $slug !== null) {
            $review->playbook_id = (int) $catalogue->firstWhere('slug', $slug)?->id ?: null;
        }
        $review->forceFill([
            'triage' => [
                'score' => $score,
                'flagged' => $flagged,
                'concerns' => array_slice(array_map(fn ($c) => mb_substr((string) $c, 0, 300), (array) ($out['concerns'] ?? [])), 0, 8),
                'playbook' => $slug,
                'reason' => mb_substr((string) ($out['reason'] ?? ''), 0, 500),
                'model' => $response->meta->model ?? null,
                'cost_micro_usd' => $cost,
            ],
            'triage_score' => $score,
            'triage_cost_micro_usd' => $cost,
            'cost_micro_usd' => $cost,
            'tokens_in' => $response->usage->promptTokens,
            'tokens_out' => $response->usage->completionTokens,
            'reviewer_model' => $response->meta->model ?? null,
        ]);

        return $flagged;
    }

    private function fullReview(QaReview $review, string $data): void
    {
        $ws = $review->workspace_id;
        $system = $this->reviewSystem($ws, $review->playbook_id);
        $response = $this->ai->withWorkspaceCredentials(
            $ws,
            fn ($lab, $model, $options = []) => (new QaReviewAgent($system))
                ->withProviderOptions($options)
                ->prompt($data, provider: $lab, model: $model),
            task: 'simulation_judge',
        );
        $out = method_exists($response, 'toArray') ? $response->toArray() : [];
        $clamp = fn ($v) => max(1, min(5, (int) ($v ?? 3)));
        $judgeable = (bool) ($out['grounding_judgeable'] ?? true) && $review->evidence_source !== 'none';
        $scores = [
            'resolved' => $clamp($out['resolved'] ?? null),
            'policy_consistency' => $clamp($out['policy_consistency'] ?? null),
            'factual_grounding' => $judgeable ? $clamp($out['factual_grounding'] ?? null) : null,
            'tone_match' => $clamp($out['tone_match'] ?? null),
            'clarity' => $clamp($out['clarity'] ?? null),
        ];
        $present = array_values(array_filter($scores, fn ($v) => $v !== null));
        $class = in_array($out['failure_class'] ?? null, QaReview::FAILURE_CLASSES, true) ? (string) $out['failure_class'] : 'none';
        $scrubber = new PiiScrubber;
        $names = PiiScrubber::namesFrom($review->conversation?->customer?->name, $review->conversation?->customer?->email);
        $clean = fn ($text, int $len) => ($t = trim((string) $text)) === '' ? null : mb_substr($scrubber->scrub($t, $names), 0, $len);
        $cost = UsageMeter::microUsd($response->meta->model ?? null, $response->usage);
        $gapClass = in_array($class, QaReview::GAP_CLASSES, true);

        $review->forceFill([
            'status' => QaReview::STATUS_REVIEWED,
            'scores' => $scores,
            'overall' => number_format(array_sum($present) / max(1, count($present)), 2, '.', ''),
            'failure_class' => $class,
            'summary' => $clean($out['summary'] ?? '', 1000),
            'gap' => $gapClass || $class === 'needed_human' ? $clean($out['gap'] ?? '', 1000) : null,
            'gap_topic' => $gapClass ? (preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace([' ', '-'], '_', (string) ($out['gap_topic'] ?? '')))) ?: null) : null,
            'suggested_fix' => $clean($out['suggested_fix'] ?? '', 1000),
            'review_cost_micro_usd' => $cost,
            'cost_micro_usd' => $review->triage_cost_micro_usd + $cost,
            'tokens_in' => $review->tokens_in + $response->usage->promptTokens,
            'tokens_out' => $review->tokens_out + $response->usage->completionTokens,
            'reviewer_model' => $response->meta->model ?? null,
            'reviewed_at' => now(),
            'error' => null,
        ])->save();
    }

    public function reviewSystem(int $workspaceId, ?int $playbookId): string
    {
        $voiceId = AgentSetting::forWorkspace($workspaceId)->live_voice_version_id;
        $voice = $voiceId !== null ? VoiceVersion::find($voiceId) : null;
        $playbook = $playbookId !== null ? Playbook::with('liveVersion')->where('workspace_id', $workspaceId)->find($playbookId) : null;
        $version = $playbook?->liveVersion;

        $lines = [
            'You review the quality of ONE staff reply on a closed customer-support conversation of a small online store. The reply may have been written by a person, by an AI agent, or drafted by the AI and edited by a person; judge the reply itself the same way in every case.',
            'Score 1-5: resolved (answered what the customer actually asked), policy_consistency (consistent with the playbook and brand rules below), factual_grounding (facts supported by the evidence shown; 3 and grounding_judgeable=false when there is no evidence), tone_match (brand voice), clarity.',
            'failure_class, the main reason it fell short:',
            '- none: good enough.',
            '- missing_instruction: the playbook has no rule for this situation, or the rule is unclear, so the reply guessed.',
            '- missing_knowledge: a fact or policy the reply needed is not known to the agent (not in the evidence or the instructions).',
            '- missing_tool: the right answer needed a capability that does not exist (e.g. changing an address, looking something up that cannot be looked up).',
            '- needed_human: the case needed a person\'s judgement or authority (should have been handed over or escalated).',
            '- agent_error: an AI-written reply ignored available instructions or evidence.',
            '- human_error: a person-written reply ignored available instructions or evidence.',
            'For missing_*: state the gap generally, as an instruction / knowledge / capability that is missing, never mentioning the customer, names, order numbers or other personal data, and give a short snake_case gap_topic.',
            'Messages after the reviewed reply show whether it resolved the case (thanks, silence, the same question again, a complaint).',
            '',
            'Brand voice (approved): '.($voice !== null ? $voice->personality : '(none)'),
        ];
        if ($voice !== null && $voice->dontPhraseTexts() !== []) {
            $lines[] = 'Never say: '.implode('; ', $voice->dontPhraseTexts());
        }
        if ($version !== null) {
            $lines[] = '';
            $lines[] = "Playbook that applies (approved): {$playbook->slug} - {$playbook->name}";
            $lines[] = 'When: '.$version->when_text;
            foreach ((array) ($version->rules ?? []) as $rule) {
                $lines[] = '- '.(($rule['if'] ?? 'always') === 'always' ? '' : 'If '.$rule['if'].': ').$rule['then'];
            }
            foreach ((array) ($version->steps ?? []) as $step) {
                $lines[] = '- step: '.$step['text'];
            }
            if ($version->escalation) {
                $lines[] = 'Escalation: '.$version->escalation;
            }
        } else {
            $lines[] = 'No playbook is known for this case: judge against the brand voice and common sense; a missing playbook is missing_instruction.';
        }

        return implode("\n", $lines);
    }

    /** @param  array<string, mixed>  $case */
    private function dataMessage(array $case, UntrustedData $u): string
    {
        $chars = (int) config('agent_qa.turn_chars', 3000);
        $render = fn (TranscriptTurn $t) => strtoupper($t->role).' ('.$t->at->toDateTimeString().($t->role === TranscriptTurn::ROLE_STAFF ? ', '.($t->origin ?? 'fusterai') : '').'): '.mb_substr($t->text, 0, $chars);
        $before = array_slice($case['before'], -max(1, (int) config('agent_qa.max_turns', 12)));

        return implode("\n\n", array_filter([
            $u->preamble(),
            'CONVERSATION BEFORE THE REVIEWED REPLY:'."\n".$u->block('transcript', implode("\n\n", array_map($render, $before))),
            'REVIEWED REPLY (author: '.$case['author_type'].'):'."\n".$u->block('reviewed_reply', mb_substr($case['reply']->text, 0, $chars * 2)),
            $case['draft'] !== null ? 'THE AI DRAFT THE PERSON EDITED:'."\n".$u->block('ai_draft', mb_substr((string) $case['draft'], 0, $chars)) : null,
            'WHAT HAPPENED AFTER:'."\n".$u->block('after', $case['after'] === [] ? '(nothing: the conversation was closed)' : implode("\n\n", array_map($render, array_slice($case['after'], 0, 4)))),
            'EVIDENCE ('.$case['evidence_source'].'):'."\n".$u->block('evidence', $case['evidence'] ?? '(no order or tracking evidence was recorded for this conversation)'),
            $case['run'] !== null ? 'AGENT RUN: decision '.($case['run']->decision ?? 'none').($case['run']->handover_reason ? ', handover '.$case['run']->handover_reason : '') : null,
        ]));
    }
}
