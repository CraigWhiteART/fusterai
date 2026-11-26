<?php

namespace App\Domains\Agent\Feedback;

use App\Domains\Agent\Events\AgentLoopUpdated;
use App\Domains\Agent\Jobs\RouteFeedbackJob;
use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\FeedbackEvent;
use App\Domains\Agent\Models\Proposal;
use App\Domains\Agent\Proposals\ConflictChecker;
use App\Domains\Agent\Proposals\InvalidProposalException;
use App\Domains\Agent\Proposals\ProposalService;
use App\Domains\Agent\Simulation\CaseSelector;
use App\Domains\Agent\Simulation\SimulationService;
use App\Domains\Conversation\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * The feedback loop for one comment on a draft:
 *
 *   submit   record a `comment` feedback event, queue routing (ai queue)
 *   process  manager routes it -> unclear: one question | one_off: preview
 *            with an ephemeral instruction (nothing saved) | otherwise: a
 *            proposal (validated ops), conflict check, preview of this
 *            conversation, regression gate when test cases are affected
 *   override the human picks another scope: re-route without re-asking
 *   answer   the human answers the clarifying question
 *
 * State lives in agent_feedback_events.meta (routing, preview, proposal_id).
 */
class FeedbackService
{
    public const MAX_ATTEMPTS = 2;

    public function __construct(
        private readonly FeedbackRouter $router,
        private readonly ProposalService $proposals,
        private readonly ConflictChecker $conflicts,
        private readonly PreviewService $preview,
        private readonly CaseSelector $selector,
        private readonly SimulationService $simulations,
    ) {}

    /** @param  array<string, mixed>  $target */
    public function submit(Conversation $conversation, AgentRun $run, User $user, string $text, ?string $selected = null, ?string $scope = null, array $target = []): FeedbackEvent
    {
        if ((int) $run->conversation_id !== (int) $conversation->id) {
            throw new InvalidArgumentException('That run belongs to another conversation.');
        }

        $event = FeedbackEvent::create([
            'workspace_id' => $conversation->workspace_id,
            'run_id' => $run->id,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'kind' => FeedbackEvent::KIND_COMMENT,
            'draft_before' => $run->draft,
            'text' => mb_substr(trim($text), 0, 4000),
            'meta' => [
                'selected_text' => $selected !== null && trim($selected) !== '' ? mb_substr(trim($selected), 0, 2000) : null,
                'routing' => ['status' => 'pending', 'forced_scope' => $scope, 'forced_target' => $target, 'attempts' => 0],
                'proposal_id' => null,
                'preview' => null,
            ],
        ]);

        dispatch(new RouteFeedbackJob($event->id));

        return $event->fresh() ?? $event;
    }

    /** @param  array<string, mixed>  $target */
    public function override(FeedbackEvent $event, User $user, string $scope, array $target = []): FeedbackEvent
    {
        if (! in_array($scope, array_diff(FeedbackRouter::SCOPES, ['unclear']), true)) {
            throw new InvalidArgumentException('Unknown scope.');
        }
        $this->supersede($event, $user, "Superseded: scope changed to {$scope}.");
        $this->setRouting($event, ['status' => 'pending', 'forced_scope' => $scope, 'forced_target' => $target, 'overridden' => true, 'answer' => null], resetProposal: true);
        dispatch(new RouteFeedbackJob($event->id));

        return $event->fresh() ?? $event;
    }

    public function answer(FeedbackEvent $event, User $user, string $answer): FeedbackEvent
    {
        if (($event->meta['routing']['status'] ?? null) !== 'question') {
            throw new InvalidArgumentException('This feedback is not waiting for an answer.');
        }
        $this->setRouting($event, ['status' => 'pending', 'answer' => mb_substr(trim($answer), 0, 2000)]);
        dispatch(new RouteFeedbackJob($event->id));

        return $event->fresh() ?? $event;
    }

    /** Job body. */
    public function process(FeedbackEvent $event): void
    {
        $run = $event->run_id !== null ? AgentRun::find($event->run_id) : null;
        if ($run === null) {
            $this->fail($event, 'The draft this feedback was about no longer exists.');

            return;
        }

        $routing = (array) ($event->meta['routing'] ?? []);
        $forced = $routing['forced_scope'] ?? null;
        $target = (array) ($routing['forced_target'] ?? []);
        $answer = $routing['answer'] ?? null;

        try {
            $decision = $forced === 'one_off'
                ? ['scope' => 'one_off', 'reason' => 'Chosen by the reviewer: this reply only.', 'question' => null, 'title' => mb_substr((string) $event->text, 0, 120),
                    'one_off_instruction' => (string) $event->text, 'target' => [], 'changes' => [], 'must_not_include' => [], 'must_include' => [], 'suggestions' => [], 'cost_micro_usd' => 0]
                : $this->router->decide($event, $run, $forced, $target, $answer);

            if ($decision['scope'] === 'unclear') {
                $this->setRouting($event, ['status' => 'question', 'scope' => 'unclear', 'question' => $decision['question'], 'reason' => $decision['reason'], 'cost_micro_usd' => $decision['cost_micro_usd']]);
                $this->routed($event->fresh() ?? $event);

                return;
            }

            if ($decision['scope'] === 'one_off') {
                $this->oneOff($event, $run, $decision);

                return;
            }

            $this->proposal($event, $run, $decision, $forced, $target, $answer);
        } catch (Throwable $e) {
            Log::warning('Feedback routing failed', ['feedback_id' => $event->id, 'error' => $e->getMessage()]);
            $this->fail($event, class_basename($e).': '.mb_substr($e->getMessage(), 0, 500));
        }
    }

    /** @param  array<string, mixed>  $decision */
    private function oneOff(FeedbackEvent $event, AgentRun $run, array $decision): void
    {
        $instruction = (string) ($decision['one_off_instruction'] ?? $event->text);
        $this->setRouting($event, ['status' => 'one_off', 'scope' => 'one_off', 'reason' => $decision['reason'], 'question' => null,
            'one_off_instruction' => $instruction, 'suggestions' => $decision['suggestions'], 'cost_micro_usd' => $decision['cost_micro_usd']]);
        $this->setMeta($event, ['preview' => ['status' => 'running', 'run_id' => null, 'baseline_run_id' => $run->id, 'error' => null, 'at' => now()->toIso8601String()]]);
        $this->routed($event->fresh() ?? $event);

        try {
            $preview = $this->preview->run($run, $this->preview->oneOffOverlay($run, $instruction));
            $state = ['status' => 'ready', 'run_id' => $preview->id, 'baseline_run_id' => $run->id, 'error' => null, 'at' => now()->toIso8601String()];
        } catch (Throwable $e) {
            $state = ['status' => 'failed', 'run_id' => null, 'baseline_run_id' => $run->id, 'error' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 500), 'at' => now()->toIso8601String()];
        }
        $this->setMeta($event, ['preview' => $state]);
        $this->previewed($event->fresh() ?? $event, null);
    }

    /**
     * @param  array<string, mixed>  $decision
     * @param  array<string, mixed>  $target
     */
    private function proposal(FeedbackEvent $event, AgentRun $run, array $decision, ?string $forced, array $target, ?string $answer): void
    {
        $errors = [];
        $attempt = 0;
        while (true) {
            $attempt++;
            if ($attempt > 1) {
                $decision = $this->router->decide($event, $run, $forced ?? $decision['scope'], $target, $answer, $errors);
            }
            if ($decision['changes'] === [] && $decision['suggestions'] !== []) {
                // Only things a person must change in settings: nothing to stage.
                $this->setRouting($event, ['status' => 'suggestions_only', 'scope' => $decision['scope'], 'reason' => $decision['reason'], 'suggestions' => $decision['suggestions'], 'question' => null]);
                $this->routed($event->fresh() ?? $event);

                return;
            }

            try {
                $selected = (string) ($event->meta['selected_text'] ?? '');
                $mustNot = $decision['must_not_include'];
                if ($mustNot === [] && $selected !== '' && mb_strlen($selected) <= 120) {
                    $mustNot = [$selected];
                }
                $proposal = $this->proposals->createProposal(
                    $event->workspace_id,
                    Proposal::ORIGIN_FEEDBACK,
                    $decision['title'],
                    $decision['reason'],
                    $decision['changes'],
                    [
                        'conversation_ids' => [$event->conversation_id],
                        'thread_ids' => array_values(array_filter([$run->customer_thread_id])),
                        'run_id' => $run->id,
                        'comment' => $event->text,
                        'selected_text' => $selected !== '' ? $selected : null,
                        'test_expectation' => ['must_not_include' => $mustNot, 'must_include' => $decision['must_include'], 'expected_outcome' => 'draft', 'notes' => $event->text],
                    ],
                    $event->user_id,
                    [
                        'scope' => $decision['scope'],
                        'conversation_id' => $event->conversation_id,
                        'run_id' => $run->id,
                        'feedback_event_id' => $event->id,
                        'suggestions' => $decision['suggestions'],
                    ],
                );
                break;
            } catch (InvalidProposalException $e) {
                $errors = $e->errors;
                $onlyRefused = $e->suggestions !== [] && count($errors) === 1 && str_starts_with($errors[0], 'No applicable changes');
                if ($onlyRefused) {
                    // Everything asked for is a permission / limit / mode change: show it as advice, stage nothing.
                    $this->setRouting($event, ['status' => 'suggestions_only', 'scope' => $decision['scope'], 'reason' => $decision['reason'],
                        'suggestions' => [...$decision['suggestions'], ...$e->suggestions], 'question' => null]);
                    $this->routed($event->fresh() ?? $event);

                    return;
                }
                if ($attempt >= self::MAX_ATTEMPTS) {
                    $this->setRouting($event, ['status' => 'failed', 'scope' => $decision['scope'], 'reason' => $decision['reason'],
                        'error' => 'The suggested change did not apply: '.implode('; ', array_slice($errors, 0, 3)), 'errors' => $errors,
                        'suggestions' => [...$decision['suggestions'], ...$e->suggestions]]);
                    $this->routed($event->fresh() ?? $event);

                    return;
                }
            }
        }

        $this->setRouting($event, ['status' => 'proposal', 'scope' => $decision['scope'], 'reason' => $decision['reason'], 'question' => null,
            'target' => $decision['target'], 'suggestions' => $proposal->suggestions, 'cost_micro_usd' => $decision['cost_micro_usd']]);
        $this->setMeta($event, ['proposal_id' => $proposal->id]);

        try {
            $this->conflicts->check($proposal);
        } catch (Throwable $e) {
            Log::warning('Feedback conflict check failed', ['proposal_id' => $proposal->id, 'error' => $e->getMessage()]);
        }
        $this->routed($event->fresh() ?? $event);

        $this->preview->previewProposal($proposal->fresh() ?? $proposal);
        $this->previewed($event->fresh() ?? $event, $proposal);

        if ((bool) config('agent.feedback.auto_gate', true) && $this->selector->gate($proposal) !== []) {
            try {
                $this->simulations->createGate($proposal->fresh() ?? $proposal, $event->user_id);
            } catch (Throwable $e) {
                Log::warning('Feedback gate failed to start', ['proposal_id' => $proposal->id, 'error' => $e->getMessage()]);
            }
        }
    }

    private function supersede(FeedbackEvent $event, User $user, string $note): void
    {
        $id = $event->meta['proposal_id'] ?? null;
        $proposal = $id !== null ? Proposal::find($id) : null;
        if ($proposal !== null && $proposal->isOpen()) {
            $this->proposals->reject($proposal, $user->id, $note);
        }
    }

    private function fail(FeedbackEvent $event, string $error): void
    {
        $this->setRouting($event, ['status' => 'failed', 'error' => $error]);
        $this->routed($event->fresh() ?? $event);
    }

    /** @param  array<string, mixed>  $routing */
    private function setRouting(FeedbackEvent $event, array $routing, bool $resetProposal = false): void
    {
        $event->refresh();
        $meta = (array) ($event->meta ?? []);
        $meta['routing'] = [...(array) ($meta['routing'] ?? []), ...$routing];
        if ($resetProposal) {
            $meta['proposal_id'] = null;
            $meta['preview'] = null;
        }
        $event->forceFill(['meta' => $meta])->save();
    }

    /** @param  array<string, mixed>  $values */
    private function setMeta(FeedbackEvent $event, array $values): void
    {
        $event->refresh();
        $event->forceFill(['meta' => [...(array) ($event->meta ?? []), ...$values]])->save();
    }

    private function routed(FeedbackEvent $event): void
    {
        AgentLoopUpdated::dispatchSafely($event->workspace_id, AgentLoopUpdated::FEEDBACK_ROUTED, [
            'feedback_id' => $event->id,
            'conversation_id' => $event->conversation_id,
            'run_id' => $event->run_id,
            'status' => $event->meta['routing']['status'] ?? null,
            'scope' => $event->meta['routing']['scope'] ?? null,
            'proposal_id' => $event->meta['proposal_id'] ?? null,
            'question' => $event->meta['routing']['question'] ?? null,
        ]);
    }

    private function previewed(FeedbackEvent $event, ?Proposal $proposal): void
    {
        $preview = $proposal !== null ? ($proposal->fresh()->preview ?? null) : ($event->meta['preview'] ?? null);
        AgentLoopUpdated::dispatchSafely($event->workspace_id, AgentLoopUpdated::PREVIEW_READY, [
            'feedback_id' => $event->id,
            'conversation_id' => $event->conversation_id,
            'proposal_id' => $proposal?->id,
            'status' => $preview['status'] ?? null,
            'run_id' => $preview['run_id'] ?? null,
        ]);
    }
}
