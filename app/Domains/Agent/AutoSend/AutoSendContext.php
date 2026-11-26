<?php

namespace App\Domains\Agent\AutoSend;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Pipeline\EvidenceLedger;
use App\Domains\Agent\Pipeline\PipelineInput;
use App\Domains\Agent\Pipeline\PlaybookSpec;
use App\Domains\Agent\Pipeline\PolicyGate;
use App\Domains\Commerce\Actions\ActionRegistry;
use App\Domains\Commerce\Actions\Models\ActionPolicy;
use App\Domains\Commerce\Actions\Policy\PolicyResolver;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Customer\Models\Customer;
use App\Domains\Mailbox\Sync\Enums\MailClassification;
use App\Domains\Mailbox\Sync\Parsing\MessageClassifier;
use App\Enums\ThreadType;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The `auto_send` input of PolicyGate: everything auto-send needs that is
 * not already in the gate (pause, retest hold, rewrites, grounding,
 * language, channel, human reply, blocked customer, automated inbound mail,
 * caps). Read-only; any exception = `context_error` (=> draft).
 *
 * Only live and shadow runs of a real conversation get a context; preview,
 * simulation and test runs get none and can never auto-send.
 */
class AutoSendContext
{
    /**
     * @param  array<string, mixed>  $triage
     * @param  array<string, mixed>  $state  pipeline state (claims, knowledge, validator)
     * @return array<string, mixed>|null
     */
    public function build(AgentSetting $settings, ?PlaybookSpec $playbook, PipelineInput $input, array $triage, array $state, EvidenceLedger $ledger): ?array
    {
        if (! in_array($input->purpose, [AgentRun::PURPOSE_LIVE, AgentRun::PURPOSE_SHADOW], true) || $input->conversation === null) {
            return null;
        }

        try {
            return $this->compute($settings, $playbook, $input, $triage, $state, $ledger);
        } catch (Throwable $e) {
            Log::warning('Auto-send context failed; the reply stays a draft', ['conversation_id' => $input->conversation->id, 'error' => $e->getMessage()]);

            return ['context_error' => true, 'error' => class_basename($e).': '.mb_substr($e->getMessage(), 0, 300)];
        }
    }

    /**
     * @param  array<string, mixed>  $triage
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function compute(AgentSetting $settings, ?PlaybookSpec $playbook, PipelineInput $input, array $triage, array $state, EvidenceLedger $ledger): array
    {
        /** @var Conversation $conversation */
        $conversation = $input->conversation;
        $options = $settings->autoSendOptions();
        $ws = (int) $conversation->workspace_id;

        $latest = $input->transcript->latestCustomerTurn();
        $customerThread = $latest?->threadId !== null ? Thread::with('mailMessage')->find($latest->threadId) : null;

        $sentToday = AutoSendCounts::today($ws);
        $customerToday = AutoSendCounts::todayForCustomer($ws, $conversation->customer_id);
        $lastHour = AutoSendCounts::lastHour($ws);

        return [
            'context_error' => false,
            'purpose_live' => $input->isLive(),
            'paused' => $settings->isPaused(),
            'playbook_hold' => $playbook?->id !== null
                ? Playbook::whereKey($playbook->id)->value('auto_hold') !== null
                : true,
            'rewrites' => (int) ($state['validator']['rewrites'] ?? 1),
            'claims_grounded' => $this->claimsGrounded((array) ($state['claims'] ?? []), $ledger, (array) ($state['knowledge'] ?? [])),
            'language' => (string) ($triage['language'] ?? ''),
            'allowed_languages' => $options['allowed_languages'],
            'sentiment' => (string) ($triage['sentiment'] ?? 'unknown'),
            'channel' => $conversation->channel_type->value ?? 'email',
            'allowed_channels' => $options['channels'],
            'human_replied' => $this->staffRepliedSinceCustomer($input),
            'customer_blocked' => $this->customerBlocked($conversation, $customerThread),
            'inbound_automated' => $latest === null || $latest->autoSubmitted || $this->automated($customerThread, $input->subject),
            'requested_by_human' => $input->force,
            'daily_remaining' => $settings->cap('auto_send_per_day') - $sentToday,
            'customer_remaining' => $settings->cap('auto_send_per_customer_per_day') - $customerToday,
            'consecutive_remaining' => $settings->cap('auto_send_consecutive_per_conversation') - AutoSendCounts::consecutive($conversation->id),
            'hourly_breaker' => $lastHour >= (int) config('agent.auto_send.max_per_hour', 10),
            'actions_unattended' => $this->actionsUnattended($ws, $playbook?->id, (array) ($state['proposed_actions'] ?? [])),
            'counts' => ['today' => $sentToday, 'customer_today' => $customerToday, 'last_hour' => $lastHour],
        ];
    }

    /**
     * Every proposed action could run without a human: its effective policy
     * is `always` and the action type may ever run unattended. Whether it
     * actually does (limits, caps, sender match, fresh order state) is
     * decided when it is recorded; RunEffects and the pre-send guard then
     * hold or cancel the reply (ActionGate::autoSendVerdict). Any action
     * that needs a human approval keeps the reply a draft.
     *
     * @param  list<array<string, mixed>>  $actions
     */
    private function actionsUnattended(int $workspaceId, ?int $playbookId, array $actions): bool
    {
        $registry = app(ActionRegistry::class);
        $resolver = app(PolicyResolver::class);
        foreach ($actions as $action) {
            if (! is_array($action) || ($action['status'] ?? '') !== 'proposed') {
                continue;
            }
            $key = (string) ($action['action'] ?? '');
            if (! $registry->has($key) || ! $registry->get($key)->unattendedAllowed()) {
                return false;
            }
            if ($resolver->resolve($workspaceId, $key, $playbookId)->mode !== ActionPolicy::ALWAYS) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every claim cites at least one source and every source is in the
     * evidence ledger or the knowledge the writer saw.
     *
     * @param  list<array<string, mixed>>  $claims
     * @param  array<string, mixed>  $knowledge
     */
    private function claimsGrounded(array $claims, EvidenceLedger $ledger, array $knowledge): bool
    {
        foreach ($claims as $claim) {
            $sources = array_values(array_filter(array_map('strval', (array) ($claim['sources'] ?? [])), fn ($s) => $s !== ''));
            if ($sources === []) {
                return false;
            }
            foreach ($sources as $source) {
                if (! $ledger->has($source) && ! isset($knowledge[$source])) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Somebody (a human, or an earlier auto-send) already answered the latest customer message. */
    private function staffRepliedSinceCustomer(PipelineInput $input): bool
    {
        $seenCustomer = false;
        foreach (array_reverse($input->transcript->turns) as $turn) {
            if ($turn->isCustomer()) {
                $seenCustomer = true;
                break;
            }

            return true;
        }

        if (! $seenCustomer) {
            return true;
        }

        // Defence in depth: a staff message thread newer than the customer
        // message (e.g. scheduled, not yet in the transcript ordering).
        $latest = $input->transcript->latestCustomerTurn();

        return $input->conversation !== null && $latest?->threadId !== null && Thread::where('conversation_id', $input->conversation->id)
            ->where('type', ThreadType::Message->value)
            ->whereNull('customer_id')
            ->where('id', '>', $latest->threadId)
            ->exists();
    }

    private function customerBlocked(Conversation $conversation, ?Thread $customerThread): bool
    {
        $ids = array_values(array_unique(array_filter([$conversation->customer_id, $customerThread?->customer_id])));

        return $ids === [] || Customer::whereIn('id', $ids)->where('is_blocked', true)->exists();
    }

    /** The inbound message is itself an auto-reply, bulk mail or a machine notification. */
    private function automated(?Thread $thread, string $subject): bool
    {
        $mail = $thread?->mailMessage;
        if ($mail !== null) {
            if ($mail->classification !== null && $mail->classification !== MailClassification::Support) {
                return true;
            }
            $parsed = $mail->toParsedMessage();
            if ((new MessageClassifier)->isAutoReply($parsed)) {
                return true;
            }
            $precedence = strtolower((string) $parsed->header('precedence'));
            if ($parsed->header('list-id') !== null || $parsed->header('list-unsubscribe') !== null || in_array($precedence, ['bulk', 'list', 'junk', 'auto_reply'], true)) {
                return true;
            }
        }

        if ((bool) (($thread->meta ?? [])['auto_submitted'] ?? false)) {
            return true;
        }

        $pattern = '/^\s*(?:re:\s*)?(?:auto(?:matic)?[\s-]?(?:reply|response)|autoreply|out of (?:the )?office|delivery status notification|undeliver(?:able|ed)|mail delivery failed)\b/i';

        return preg_match($pattern, $subject) === 1
            || preg_match('/^\s*(?:this is an automatic reply|i am (?:currently )?out of (?:the )?office)/i', (string) ($thread->body_plain ?? '')) === 1;
    }

    /** @param  array<string, mixed>  $gate  PolicyGate::decide() result */
    public static function wouldAutoSend(array $gate): bool
    {
        return PolicyGate::onlyBlockedBy((array) ($gate['auto_send_blockers'] ?? ['handover']), PolicyGate::SHADOW_IGNORED_BLOCKERS);
    }

    /** @param  array<string, mixed>  $gate */
    public static function eligibleIfAuto(array $gate): bool
    {
        return PolicyGate::onlyBlockedBy((array) ($gate['auto_send_blockers'] ?? ['handover']), PolicyGate::CANDIDATE_IGNORED_BLOCKERS);
    }
}
