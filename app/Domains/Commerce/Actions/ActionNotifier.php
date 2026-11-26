<?php

namespace App\Domains\Commerce\Actions;

use App\Domains\Agent\AutoSend\AutoSendService;
use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\AI\Jobs\GenerateReplySuggestionJob;
use App\Domains\Commerce\Actions\Events\ActionExecutionUpdated;
use App\Domains\Commerce\Actions\Models\ActionExecution;
use App\Enums\ThreadType;
use App\Events\NewThreadReceived;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Side effects of an execution changing state: broadcast, activity log,
 * internal notes handing the conversation to a human, and the reply
 * rewrite when a reply-blocking action fails or is rejected. Never throws.
 */
class ActionNotifier
{
    public function changed(ActionExecution $execution, string $event, ?User $causer = null): void
    {
        try {
            activity('agent-actions')
                ->performedOn($execution)
                ->causedBy($causer)
                ->event($event)
                ->withProperties([
                    'action_key' => $execution->action_key,
                    'status' => $execution->status,
                    'route' => $execution->route,
                    'order' => $execution->order_number,
                    'amount' => $execution->amount,
                    'currency' => $execution->currency,
                    'error' => $execution->error,
                ])
                ->log("Action {$execution->action_key} {$event}");
        } catch (Throwable $e) {
            Log::warning('Action activity log failed', ['execution_id' => $execution->id, 'error' => $e->getMessage()]);
        }

        ActionExecutionUpdated::dispatchFor($execution);

        $this->cancelDependentAutoSend($execution);
    }

    /**
     * A pending auto-reply written by this execution's run may only go out
     * once every action it depends on has succeeded unattended. If this one
     * now needs a human, failed, or has an unknown outcome, the auto-reply
     * goes back to being a draft at once (the pre-send guard would also catch
     * it, but not until the send is due).
     */
    private function cancelDependentAutoSend(ActionExecution $execution): void
    {
        $stops = [ActionExecution::FAILED, ActionExecution::REJECTED, ActionExecution::EXPIRED, ActionExecution::CANCELLED, ActionExecution::UNKNOWN, ActionExecution::PENDING_APPROVAL];
        if ($execution->run_id === null || ! in_array($execution->status, $stops, true)) {
            return;
        }

        try {
            app(AutoSendService::class)->cancelForRun(
                (int) $execution->run_id,
                $execution->status === ActionExecution::PENDING_APPROVAL ? 'action_needs_approval' : 'action_failed',
            );
        } catch (Throwable $e) {
            Log::warning('Cancelling an auto-reply after an action change failed', ['execution_id' => $execution->id, 'error' => $e->getMessage()]);
        }
    }

    /** Internal note + conversations.handover_reason: a human must look. */
    public function handOver(ActionExecution $execution, string $reason, string $text): void
    {
        $conversation = $execution->conversation;
        if ($conversation === null) {
            return;
        }

        try {
            $conversation->forceFill(['handover_reason' => 'action:'.$reason])->save();
            $thread = $conversation->threads()->create([
                'type' => ThreadType::Note,
                'body' => '<p><strong>Shopify action needs a human</strong></p>'.nl2br(e($text)),
                'body_plain' => "Shopify action needs a human\n".$text,
                'source' => 'web',
                'meta' => ['agent_action_execution_id' => $execution->id, 'handover_reason' => 'action:'.$reason],
            ]);
            broadcast(new NewThreadReceived($thread));
        } catch (Throwable $e) {
            Log::warning('Action handover note failed', ['execution_id' => $execution->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * A reply that depended on this action can no longer be sent as written:
     * flag it, and regenerate once with the outcome in evidence
     * (ActionOutcomeEvidence). Only for live conversations whose latest run
     * is the one that proposed it, so an old outcome never rewrites a newer draft.
     */
    public function requestRewrite(ActionExecution $execution): void
    {
        if (! $execution->reply_blocking || $execution->rewrite_requested || $execution->conversation === null || $execution->run_id === null) {
            return;
        }
        if (! $execution->transition([$execution->status], $execution->status, ['rewrite_requested' => true])) {
            return;
        }

        try {
            $settings = AgentSetting::forWorkspace($execution->workspace_id);
            $latestRunId = AgentRun::where('conversation_id', $execution->conversation_id)->where('purpose', AgentRun::PURPOSE_LIVE)->max('id');

            if ($settings->pipelineOwnsDrafts() && ! $settings->kill_switch && (int) $latestRunId === (int) $execution->run_id) {
                GenerateReplySuggestionJob::dispatch($execution->conversation, regenerate: true);
            }
        } catch (Throwable $e) {
            Log::warning('Action rewrite dispatch failed', ['execution_id' => $execution->id, 'error' => $e->getMessage()]);
        }
    }
}
