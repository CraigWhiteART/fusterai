<?php

namespace App\Domains\Commerce\Actions;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\FeedbackEvent;
use App\Domains\Agent\Pipeline\PolicyGate;
use App\Domains\Conversation\Models\Conversation;
use App\Models\User;
use App\Support\PlainText;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Human sends (inbox composer, API reply) while the agent's current draft
 * depends on a Shopify action that has not succeeded (ActionGate::blocksSending:
 * waiting for approval / still running / failed). Sending that draft would
 * tell the customer something that has not happened.
 *
 * Refused with a validation error on `send_anyway` unless the person both
 * changed the text and confirmed `send_anyway: true`: then the reply is theirs
 * (it is logged). Notes are never checked.
 */
class ReplySendGuard
{
    public const FIELD = 'send_anyway';

    public function check(Conversation $conversation, string $body, bool $sendAnyway, ?User $actor = null): void
    {
        $run = $this->blockingRun($conversation);
        if ($run === null) {
            return;
        }

        $state = ActionGate::state($run);
        $edited = PlainText::percentChanged((string) $run->draft, $body) > 0;

        if (! $sendAnyway || ! $edited) {
            $what = $state['state'] === 'needs_rewrite'
                ? 'A Shopify action this draft depends on failed or was rejected, so the draft promises something that did not happen.'
                : 'This draft depends on a Shopify action that has not completed yet (it is waiting for approval or still running).';

            throw ValidationException::withMessages([self::FIELD => $edited
                ? $what.' Confirm "send anyway" to send your edited reply.'
                : $what.' Wait for the action, or edit the reply and confirm "send anyway".']);
        }

        try {
            activity('agent-actions')
                ->performedOn($conversation)
                ->causedBy($actor)
                ->event('reply_sent_despite_actions')
                ->withProperties(['run_id' => $run->id, 'reply_state' => $state['state'], 'blocking' => $state['blocking'], 'failed' => $state['failed']])
                ->log('Reply sent by a person while a Shopify action it may depend on had not succeeded');
        } catch (Throwable $e) {
            Log::warning('Logging a send-anyway reply failed', ['conversation_id' => $conversation->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The conversation's current agent draft (latest live run that drafted
     * and has not been answered yet) when its actions block sending.
     */
    public function blockingRun(Conversation $conversation): ?AgentRun
    {
        $run = AgentRun::where('conversation_id', $conversation->id)
            ->where('purpose', AgentRun::PURPOSE_LIVE)
            ->where('status', AgentRun::STATUS_COMPLETED)
            ->whereIn('decision', [PolicyGate::DRAFT, PolicyGate::AUTO_SEND])
            ->whereNotNull('draft')
            ->latest('id')
            ->first();

        if ($run === null || $run->feedback()->whereIn('kind', [
            FeedbackEvent::KIND_SENT_AS_IS,
            FeedbackEvent::KIND_SENT_EDITED,
            FeedbackEvent::KIND_GMAIL_REPLY,
            FeedbackEvent::KIND_REJECTED,
        ])->exists()) {
            return null;
        }

        return ActionGate::blocksSending($run) ? $run : null;
    }
}
