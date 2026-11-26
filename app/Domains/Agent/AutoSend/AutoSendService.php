<?php

namespace App\Domains\Agent\AutoSend;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Conversation\Jobs\SendReplyJob;
use App\Domains\Conversation\Models\AiSuggestion;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Enums\ConversationStatus;
use App\Enums\ThreadType;
use App\Events\AiSuggestionReady;
use App\Events\ConversationUpdated;
use App\Events\NewThreadReceived;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Auto-send lifecycle of one reply thread (docs/agent-autosend.md):
 *
 *   schedule  the agent's reply becomes a message thread with origin `ai`,
 *             no user, scheduled send_at = now + delay; SendReplyJob is
 *             queued with that delay (the existing scheduled-send path)
 *   check     SendReplyJob calls AutoSendGuard right before mailing
 *   sent      markSent(): status after send (never closed), audit
 *   cancel    the thread becomes an internal note ("auto-reply cancelled"),
 *             send_at cleared (so the queued job is a no-op), and the text
 *             optionally comes back as a normal draft (AiSuggestion)
 *
 * meta.auto_send on the thread: {status: scheduled|sending|sent|cancelled,
 * run_id, playbook_id, scheduled_for, sent_at?, cancel_reason?, cancelled_by?}.
 */
class AutoSendService
{
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_CANCELLED = 'cancelled';

    /** Cancel reasons after which no draft is restored: the reply is moot. */
    public const NO_DRAFT_REASONS = ['new_customer_message', 'staff_replied'];

    public const REASON_LABELS = [
        'cancelled_by_user' => 'cancelled by a team member',
        'edit_instead' => 'a team member chose to edit it instead',
        'kill_switch' => 'the kill switch is on',
        'agent_mode' => 'the agent is no longer in live mode',
        'paused' => 'auto-sending is paused',
        'playbook_mode' => 'the playbook is no longer on auto',
        'playbook_hold' => 'the playbook is being re-tested after a change',
        'rollout' => 'the conversation is no longer in the playbook\'s rollout',
        'new_customer_message' => 'the customer wrote again',
        'staff_replied' => 'a team member replied',
        'customer_blocked' => 'the customer is blocked',
        'conversation_closed' => 'the conversation was closed or marked spam',
        'no_recipient' => 'there is no customer email address to send to',
        'run_missing' => 'the agent run behind it is missing',
        'breaker' => 'a circuit breaker switched the playbook back to draft',
        'guard_error' => 'a pre-send check failed',
        'action_failed' => 'a Shopify action it depends on failed, was rejected or did not complete, so it needs rewriting',
        'action_needs_approval' => 'a Shopify action it depends on needs a person to approve it',
        'action_unrecorded' => 'a Shopify action it depends on could not be recorded',
        'action_timeout' => 'the Shopify action it depends on did not finish in time',
        'action_check_error' => 'the Shopify action check failed',
    ];

    public static function reasonLabel(string $reason): string
    {
        return self::REASON_LABELS[$reason] ?? str_replace('_', ' ', $reason);
    }

    public static function isAutoThread(Thread $thread): bool
    {
        return $thread->origin === Thread::ORIGIN_AI;
    }

    /** @return array<string, mixed> */
    public static function state(Thread $thread): array
    {
        return (array) (($thread->meta ?? [])['auto_send'] ?? []);
    }

    // ── schedule ─────────────────────────────────────────────────────────────

    /**
     * @param  bool  $awaitingActions  an unattended action the reply depends on is
     *                                 still running: the pre-send guard holds the
     *                                 send (re-checking every `action_poll_seconds`)
     *                                 until it succeeds, and cancels back to a draft
     *                                 if it fails or `action_wait_seconds` pass
     */
    public function schedule(AgentRun $run, Conversation $conversation, bool $awaitingActions = false): Thread
    {
        $settings = AgentSetting::forWorkspace((int) $conversation->workspace_id);
        $options = $settings->autoSendOptions();
        $delay = max(0, (int) $settings->auto_send_delay_seconds);
        $sendAt = now()->addSeconds($delay);

        [$html, $plain] = self::render((string) $run->draft, $options['disclosure_line']);

        $waitUntil = $awaitingActions ? $sendAt->copy()->addSeconds(max(0, (int) config('agent.auto_send.action_wait_seconds', 900))) : null;

        $thread = DB::transaction(function () use ($run, $conversation, $html, $plain, $sendAt, $waitUntil) {
            /** @var Thread $thread */
            $thread = $conversation->threads()->create([
                'user_id' => null,
                'customer_id' => null,
                'type' => ThreadType::Message,
                'body' => $html,
                'body_plain' => $plain,
                'source' => 'web',
                'origin' => Thread::ORIGIN_AI,
                'send_at' => $sendAt,
                'meta' => [
                    // Rendered as the author name (same convention as Gmail-origin threads).
                    'from_name' => 'AI agent',
                    'sent_by' => 'ai_agent',
                    'agent_run_id' => $run->id,
                    'auto_send' => [
                        'status' => self::STATUS_SCHEDULED,
                        'run_id' => $run->id,
                        'playbook_id' => $run->playbook_id,
                        'scheduled_for' => $sendAt->toIso8601String(),
                        'awaiting_actions' => $waitUntil !== null,
                        'wait_until' => $waitUntil?->toIso8601String(),
                    ],
                ],
            ]);

            $conversation->forceFill(['handover_reason' => null, 'last_reply_at' => now()])->save();

            return $thread;
        });

        AutoSendAudit::update($run, [
            'status' => self::STATUS_SCHEDULED,
            'thread_id' => $thread->id,
            'scheduled_for' => $sendAt->toIso8601String(),
            'delay_seconds' => $delay,
            'awaiting_actions' => $waitUntil !== null,
        ]);
        AutoSendAudit::record('auto_send_scheduled', "AI agent scheduled an auto-reply (run {$run->id}) to send at {$sendAt->toIso8601String()}", $conversation, $run, [
            'thread_id' => $thread->id,
            'playbook_id' => $run->playbook_id,
            'delay_seconds' => $delay,
        ]);

        $pending = SendReplyJob::dispatch($thread, $conversation, Carbon::instance($sendAt))->onQueue('email-outbound');
        if ($delay > 0) {
            $pending->delay($sendAt);
        }

        $this->broadcastSafely(fn () => broadcast(new NewThreadReceived($thread)));
        $this->broadcastSafely(fn () => broadcast(new ConversationUpdated($conversation->fresh() ?? $conversation)));

        return $thread;
    }

    /**
     * The draft as the HTML body of the email, plus the optional disclosure.
     *
     * @return array{0: string, 1: string}
     */
    public static function render(string $draft, string $disclosure = ''): array
    {
        $paragraphs = preg_split('/\R{2,}/', trim($draft)) ?: [];
        $html = implode('', array_map(fn ($p) => '<p>'.nl2br(e(trim($p)), false).'</p>', array_filter($paragraphs, fn ($p) => trim($p) !== '')));
        $plain = trim($draft);

        if ($disclosure !== '') {
            $html .= '<p><em>'.e($disclosure).'</em></p>';
            $plain .= "\n\n".$disclosure;
        }

        return [$html, $plain];
    }

    /** Cancel the pending auto-reply (if any) written by this run. */
    public function cancelForRun(int $runId, string $reason): int
    {
        $run = AgentRun::find($runId);
        if ($run === null || $run->conversation_id === null) {
            return 0;
        }

        $count = 0;
        $threads = self::pendingQuery((int) $run->workspace_id)->where('conversation_id', $run->conversation_id)->get()
            ->filter(fn (Thread $t) => (int) (self::state($t)['run_id'] ?? 0) === $runId);
        foreach ($threads as $thread) {
            if ($this->cancel($thread, $reason) !== null) {
                $count++;
            }
        }

        return $count;
    }

    // ── sent ─────────────────────────────────────────────────────────────────

    /** Called by SendReplyJob once the mail has gone out. */
    public function markSent(Thread $thread): void
    {
        try {
            $thread->refresh();
            $state = self::state($thread);
            $run = isset($state['run_id']) ? AgentRun::find((int) $state['run_id']) : null;
            $now = now();

            $thread->forceFill([
                'sent_at' => $thread->sent_at ?? $now,
                'send_at' => null,
                'meta' => [...($thread->meta ?? []), 'auto_send' => [...$state, 'status' => self::STATUS_SENT, 'sent_at' => $now->toIso8601String()]],
            ])->save();

            $conversation = Conversation::find($thread->conversation_id);
            if ($conversation !== null) {
                $settings = AgentSetting::forWorkspace((int) $conversation->workspace_id);
                $status = $settings->autoSendOptions()['status_after_send'] === 'open' ? ConversationStatus::Open : ConversationStatus::Pending;
                // Never closed: a customer reply must always land in an active conversation.
                if (! in_array($conversation->status, [ConversationStatus::Spam, ConversationStatus::Closed], true)) {
                    $conversation->update(['status' => $status, 'last_reply_at' => $now]);
                }
            }

            if ($run !== null) {
                AutoSendAudit::update($run, ['status' => self::STATUS_SENT, 'sent_at' => $now->toIso8601String()]);
            }
            AutoSendAudit::record('auto_send_sent', 'AI agent auto-reply sent'.($run ? " (run {$run->id})" : ''), $conversation ?? $thread, $run, ['thread_id' => $thread->id]);
        } catch (Throwable $e) {
            // The mail is out; never let bookkeeping make the job retry (and send twice).
            Log::error('Auto-send bookkeeping after send failed', ['thread_id' => $thread->id, 'error' => $e->getMessage()]);
        }
    }

    // ── cancel ───────────────────────────────────────────────────────────────

    /**
     * Stop a scheduled auto-reply. Safe to call twice and after it was sent
     * (then it does nothing and returns null).
     *
     * @return array{thread: Thread, suggestion: AiSuggestion|null, draft: string}|null
     */
    public function cancel(Thread $thread, string $reason, ?int $userId = null, ?bool $restoreDraft = null): ?array
    {
        $restoreDraft ??= ! in_array($reason, self::NO_DRAFT_REASONS, true);

        $cancelled = DB::transaction(function () use ($thread, $reason, $userId) {
            /** @var Thread|null $locked */
            $locked = Thread::query()->lockForUpdate()->find($thread->id);
            if ($locked === null || ! self::isAutoThread($locked) || $locked->type !== ThreadType::Message) {
                return null;
            }
            $state = self::state($locked);
            if (! in_array($state['status'] ?? null, [self::STATUS_SCHEDULED, self::STATUS_SENDING], true)) {
                return null;
            }

            $label = self::reasonLabel($reason);
            $original = (string) $locked->body;
            $locked->forceFill([
                'type' => ThreadType::Note,
                'send_at' => null,
                'body' => '<p><strong>AI auto-reply cancelled</strong>: '.e($label).'. It was not sent.</p>'.$original,
                'body_plain' => "AI auto-reply cancelled: {$label}. It was not sent.\n\n".$locked->body_plain,
                'meta' => [...($locked->meta ?? []), 'auto_send' => [
                    ...$state,
                    'status' => self::STATUS_CANCELLED,
                    'cancel_reason' => $reason,
                    'cancelled_by' => $userId,
                    'cancelled_at' => now()->toIso8601String(),
                    'original_body' => $original,
                ]],
            ])->save();

            return $locked;
        });

        if ($cancelled === null) {
            return null;
        }

        $state = self::state($cancelled);
        $run = isset($state['run_id']) ? AgentRun::find((int) $state['run_id']) : null;
        $conversation = Conversation::find($cancelled->conversation_id);
        $draft = (string) ($run !== null ? $run->draft : $cancelled->body_plain);

        $suggestion = null;
        if ($restoreDraft && $conversation !== null && trim($draft) !== '') {
            $writerModel = $run?->config['models']['writer'] ?? null;
            $suggestion = AiSuggestion::create([
                'conversation_id' => $conversation->id,
                'thread_id' => $run?->customer_thread_id,
                'type' => 'reply',
                'content' => $draft,
                'model' => is_string($writerModel) ? $writerModel : null,
                'prompt_tokens' => $run->tokens_in ?? 0,
                'completion_tokens' => $run->tokens_out ?? 0,
                'agent_run_id' => $run?->id,
            ]);
            if ($run !== null) {
                $run->forceFill(['ai_suggestion_id' => $suggestion->id])->save();
            }
        }

        if ($run !== null) {
            AutoSendAudit::update($run, [
                'status' => self::STATUS_CANCELLED,
                'cancel_reason' => $reason,
                'cancelled_by' => $userId,
                'cancelled_at' => now()->toIso8601String(),
                'restored_draft' => $suggestion !== null,
            ]);
        }
        AutoSendAudit::record('auto_send_cancelled', 'AI auto-reply cancelled: '.self::reasonLabel($reason), $conversation ?? $cancelled, $run, [
            'thread_id' => $cancelled->id,
            'reason' => $reason,
            'restored_draft' => $suggestion !== null,
        ], $userId);

        if ($conversation !== null) {
            if ($suggestion !== null) {
                $this->broadcastSafely(fn () => broadcast(new AiSuggestionReady($conversation->id, $draft, $suggestion->id, $run?->id)));
            }
            $this->broadcastSafely(fn () => broadcast(new ConversationUpdated($conversation->fresh() ?? $conversation)));
        }

        return ['thread' => $cancelled, 'suggestion' => $suggestion, 'draft' => $draft];
    }

    /**
     * Cancel every scheduled auto-reply of a workspace (optionally one
     * playbook or one conversation). Used by the kill switch, pause, mode
     * changes, breakers and the mail ingest.
     */
    public function cancelPending(int $workspaceId, string $reason, ?int $playbookId = null, ?int $conversationId = null, ?int $userId = null, ?int $exceptThreadId = null): int
    {
        $threads = self::pendingQuery($workspaceId)
            ->when($conversationId !== null, fn ($q) => $q->where('conversation_id', $conversationId))
            ->when($exceptThreadId !== null, fn ($q) => $q->where('id', '!=', $exceptThreadId))
            ->get()
            ->filter(fn (Thread $t) => $playbookId === null || (int) (self::state($t)['playbook_id'] ?? 0) === $playbookId);

        $count = 0;
        foreach ($threads as $thread) {
            try {
                if ($this->cancel($thread, $reason, $userId) !== null) {
                    $count++;
                }
            } catch (Throwable $e) {
                Log::error('Cancelling a pending auto-send failed', ['thread_id' => $thread->id, 'error' => $e->getMessage()]);
            }
        }

        return $count;
    }

    /** @return Builder<Thread> */
    public static function pendingQuery(int $workspaceId): Builder
    {
        return Thread::query()
            ->where('origin', Thread::ORIGIN_AI)
            ->where('type', ThreadType::Message->value)
            ->whereIn('meta->auto_send->status', [self::STATUS_SCHEDULED, self::STATUS_SENDING])
            ->whereHas('conversation', fn ($q) => $q->where('workspace_id', $workspaceId));
    }

    /**
     * A message arrived on a conversation (customer, Gmail or in-app staff
     * reply): any scheduled auto-reply there is now stale.
     */
    public function onNewMessage(Thread $thread): void
    {
        if ($thread->type !== ThreadType::Message || self::isAutoThread($thread)) {
            return;
        }
        $conversation = Conversation::find($thread->conversation_id);
        if ($conversation === null) {
            return;
        }

        $reason = $thread->customer_id !== null ? 'new_customer_message' : 'staff_replied';
        $this->cancelPending((int) $conversation->workspace_id, $reason, conversationId: $conversation->id, exceptThreadId: $thread->id);
    }

    /** Same playbook-mode test the guard and the breakers use. */
    public static function playbookAllowsAuto(?Playbook $playbook): bool
    {
        return $playbook !== null && $playbook->mode === Playbook::MODE_AUTO && $playbook->archived_at === null
            && $playbook->auto_hold === null && ! $playbook->always_human;
    }

    private function broadcastSafely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            Log::warning('Auto-send broadcast failed', ['error' => $e->getMessage()]);
        }
    }
}
