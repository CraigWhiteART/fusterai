<?php

namespace App\Domains\Agent\Pipeline;

use App\Domains\Agent\AutoSend\AutoSendAudit;
use App\Domains\Agent\AutoSend\AutoSendService;
use App\Domains\Agent\AutoSend\CircuitBreaker;
use App\Domains\Agent\Models\AgentRun;
use App\Domains\Commerce\Actions\ActionGate;
use App\Domains\Commerce\Actions\ActionRecorder;
use App\Domains\Conversation\Models\AiSuggestion;
use App\Domains\Conversation\Models\Conversation;
use App\Enums\ThreadType;
use App\Events\AiSuggestionFailed;
use App\Events\AiSuggestionReady;
use App\Events\NewThreadReceived;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * The only place a run touches the conversation. Called for live runs only;
 * anything else is a bug and throws. Nothing here sends mail or calls
 * Shopify: an auto_send decision only schedules the reply thread
 * (AutoSendService); SendReplyJob mails it after the undo window, once
 * AutoSendGuard has re-checked everything.
 */
class RunEffects
{
    public function __construct(
        private readonly AutoSendService $autoSend,
        private readonly CircuitBreaker $breakers,
    ) {}

    public function apply(AgentRun $run, Conversation $conversation): void
    {
        if (! $run->isLive()) {
            throw new LogicException("Run {$run->id} has purpose [{$run->purpose}]: only live runs may change a conversation.");
        }

        $this->afterDecision($run);

        // ── Phase 9: proposed Shopify actions -> agent_action_executions (docs/agent-actions.md) ──
        try {
            app(ActionRecorder::class)->recordForRun($run, $conversation);
        } catch (Throwable $e) {
            // Unrecorded reply-blocking proposals keep ActionGate::blocksSending() true (fail closed).
            Log::error('Recording proposed actions failed', ['run_id' => $run->id, 'error' => $e->getMessage()]);
        }
        // ── end Phase 9 ──

        if ($run->decision === PolicyGate::AUTO_SEND && filled($run->draft)) {
            $this->holdForActions($run, $conversation);
        }

        if ($run->decision === PolicyGate::AUTO_SEND && filled($run->draft)) {
            try {
                $this->autoSend->schedule($run, $conversation, awaitingActions: ActionGate::autoSendVerdict($run)['verdict'] === ActionGate::VERDICT_WAIT);

                return;
            } catch (Throwable $e) {
                // Any failure to schedule: the reply becomes a normal draft.
                Log::error('Auto-send scheduling failed; falling back to a draft', ['run_id' => $run->id, 'error' => $e->getMessage()]);
                AutoSendAudit::update($run, ['status' => 'schedule_failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
                AutoSendAudit::record('auto_send_failed', 'AI auto-reply could not be scheduled; left as a draft', $conversation, $run, ['error' => class_basename($e)]);
            }
        }

        if (in_array($run->decision, [PolicyGate::DRAFT, PolicyGate::AUTO_SEND], true) && filled($run->draft)) {
            $writerModel = $run->config['models']['writer'] ?? null;
            $suggestion = AiSuggestion::create([
                'conversation_id' => $conversation->id,
                'thread_id' => $run->customer_thread_id,
                'type' => 'reply',
                'content' => (string) $run->draft,
                'model' => is_string($writerModel) ? $writerModel : null,
                'prompt_tokens' => $run->tokens_in,
                'completion_tokens' => $run->tokens_out,
                'agent_run_id' => $run->id,
            ]);
            $run->forceFill(['ai_suggestion_id' => $suggestion->id])->save();

            if ($conversation->handover_reason !== null) {
                $conversation->forceFill(['handover_reason' => null])->save();
            }

            broadcast(new AiSuggestionReady($conversation->id, (string) $run->draft, $suggestion->id, $run->id));

            return;
        }

        $conversation->forceFill(['handover_reason' => $run->handover_reason])->save();

        $note = (string) $run->internal_note;
        $thread = $conversation->threads()->create([
            'type' => ThreadType::Note,
            'body' => '<p><strong>AI agent handed this over</strong></p>'.nl2br(e($note)),
            'body_plain' => "AI agent handed this over\n".$note,
            'source' => 'web',
            'meta' => ['agent_run_id' => $run->id, 'handover_reason' => $run->handover_reason],
        ]);

        broadcast(new NewThreadReceived($thread));
        broadcast(new AiSuggestionFailed($conversation->id, 'The AI agent handed this conversation to a human: '.HandoverReasons::label((string) $run->handover_reason)));
    }

    /**
     * An auto-send whose actions were just recorded: if any of them needs a
     * human (approval route), failed, or was never recorded, the reply is
     * downgraded to a normal draft (the approval card / rewrite flag shows
     * in the inbox). Otherwise it is scheduled and, while an unattended
     * reply-blocking action is still running, held by the pre-send guard
     * until it succeeds. Any exception = draft (fail closed).
     */
    private function holdForActions(AgentRun $run, Conversation $conversation): void
    {
        try {
            $verdict = ActionGate::autoSendVerdict($run);
        } catch (Throwable $e) {
            Log::error('Checking actions before auto-send failed; leaving a draft', ['run_id' => $run->id, 'error' => $e->getMessage()]);
            $verdict = ['verdict' => ActionGate::VERDICT_CANCEL, 'reason' => 'action_check_error'];
        }
        if ($verdict['verdict'] !== ActionGate::VERDICT_CANCEL) {
            return;
        }

        $reason = (string) $verdict['reason'];
        $trace = (array) ($run->trace ?? []);
        $trace['gate'] = [...(array) ($trace['gate'] ?? []), 'decision' => PolicyGate::DRAFT, 'downgraded_by_actions' => $reason];
        $run->forceFill(['decision' => PolicyGate::DRAFT, 'trace' => $trace])->save();
        AutoSendAudit::update($run, [
            'status' => 'downgraded',
            'blockers' => [...(array) (($run->auto_send ?? [])['blockers'] ?? []), $reason],
        ]);
        AutoSendAudit::record('auto_send_downgraded', "AI auto-reply left as a draft: {$reason} (run {$run->id})", $conversation, $run, ['reason' => $reason]);
    }

    /**
     * Audit an auto-send decision, trip the global hourly breaker when the
     * gate hit it, and re-evaluate the playbook's breakers (validator
     * failures count). Never throws.
     */
    private function afterDecision(AgentRun $run): void
    {
        try {
            $gate = (array) (($run->trace ?? [])['gate'] ?? []);
            $blockers = (array) ($gate['auto_send_blockers'] ?? []);

            if ($run->decision === PolicyGate::AUTO_SEND) {
                AutoSendAudit::record('auto_send_decision', "AI agent decided to auto-send (run {$run->id})", $run->conversation, $run, ['playbook_id' => $run->playbook_id]);
            }
            // This run would have been one auto-send too many this hour.
            if (in_array('hourly_breaker', $blockers, true) && PolicyGate::onlyBlockedBy($blockers, ['hourly_breaker', 'daily_cap', 'customer_cap', 'consecutive_cap'])) {
                $this->breakers->tripGlobal((int) $run->workspace_id, 'More than '.(int) config('agent.auto_send.max_per_hour', 10).' auto-sends in one hour.');
            }
            if ($run->playbook_id !== null) {
                $this->breakers->evaluatePlaybookId((int) $run->playbook_id);
            }
        } catch (Throwable $e) {
            Log::warning('Auto-send post-decision checks failed', ['run_id' => $run->id, 'error' => $e->getMessage()]);
        }
    }
}
