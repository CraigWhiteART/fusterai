<?php

namespace App\Domains\Agent\AutoSend;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Pipeline\PolicyGate;
use App\Domains\Commerce\Actions\ActionGate;
use App\Domains\Conversation\Jobs\SendReplyJob;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Customer\Models\Customer;
use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\ThreadType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The last check, inside SendReplyJob, immediately before an auto-reply is
 * mailed (and again on every retry). If anything changed since the agent
 * decided — kill switch, workspace mode or pause, the playbook's mode /
 * hold / rollout, a newer customer message or staff reply (including one
 * ingested from Gmail), a cancel, a blocked customer, a closed conversation —
 * the send is cancelled and the reply goes back to being a draft (or is
 * dropped when the customer wrote again / a human answered), with the reason
 * recorded. Any exception = do not send.
 */
class AutoSendGuard
{
    /** blockReason() result: not yet, re-check later (an unattended action is still running). */
    public const WAIT = 'awaiting_actions';

    private static bool $rechecking = false;

    public function __construct(private readonly AutoSendService $service) {}

    /**
     * Why autonomous work (auto-send, and the seam for unattended action
     * executions) must not run for this workspace right now; null = allowed.
     * The Phase 9 action executor should call this immediately before it
     * executes a queued action without a human.
     */
    public static function autonomyBlockedReason(int $workspaceId): ?string
    {
        $settings = AgentSetting::query()->where('workspace_id', $workspaceId)->first();

        return match (true) {
            $settings === null => 'agent_mode',
            $settings->kill_switch => 'kill_switch',
            $settings->mode !== AgentSetting::MODE_LIVE => 'agent_mode',
            $settings->isPaused() => 'paused',
            default => null,
        };
    }

    /** True = send now. False = do not send (already handled: cancelled, sent, or reverted to a draft). */
    public function allowsSend(Thread $thread): bool
    {
        try {
            [$allowed, $reason] = DB::transaction(function () use ($thread) {
                /** @var Thread|null $locked */
                $locked = Thread::query()->lockForUpdate()->find($thread->id);
                if ($locked === null || $locked->type !== ThreadType::Message || $locked->send_at === null) {
                    return [false, null];
                }
                $state = AutoSendService::state($locked);
                if (! in_array($state['status'] ?? null, [AutoSendService::STATUS_SCHEDULED, AutoSendService::STATUS_SENDING], true)) {
                    return [false, null];
                }

                $reason = $this->blockReason($locked, $state);
                if ($reason === self::WAIT) {
                    $until = isset($state['wait_until']) ? Carbon::parse((string) $state['wait_until']) : null;

                    return $until !== null && now()->lt($until) ? [false, self::WAIT] : [false, 'action_timeout'];
                }
                if ($reason !== null) {
                    return [false, $reason];
                }

                $locked->forceFill(['meta' => [...($locked->meta ?? []), 'auto_send' => [...$state, 'status' => AutoSendService::STATUS_SENDING, 'checked_at' => now()->toIso8601String()]]])->save();

                return [true, null];
            });
        } catch (Throwable $e) {
            Log::error('Auto-send pre-send check failed; not sending', ['thread_id' => $thread->id, 'error' => $e->getMessage()]);
            [$allowed, $reason] = [false, 'guard_error'];
        }

        if ($reason === self::WAIT) {
            $this->recheckLater($thread);

            return false;
        }

        if (! $allowed && $reason !== null) {
            try {
                $this->service->cancel($thread, $reason);
            } catch (Throwable $e) {
                Log::error('Auto-send cancel after a failed check failed', ['thread_id' => $thread->id, 'error' => $e->getMessage()]);
            }
        }

        return $allowed;
    }

    /** Queue another pre-send check (the thread stays scheduled). */
    private function recheckLater(Thread $thread): void
    {
        $conversation = Conversation::find($thread->conversation_id);
        $fresh = $thread->fresh();
        if ($conversation === null || $fresh === null || $fresh->send_at === null) {
            return;
        }
        // A sync queue ignores the delay and would run the re-check inside this
        // one, forever: only ever one level deep (the reply then stays
        // scheduled until the next real check; Horizon never takes this path).
        if (self::$rechecking) {
            Log::warning('Auto-send re-check skipped: the queue ran it synchronously', ['thread_id' => $thread->id]);

            return;
        }

        $poll = max(5, (int) config('agent.auto_send.action_poll_seconds', 30));
        self::$rechecking = true;
        try {
            SendReplyJob::dispatch($fresh, $conversation, Carbon::instance($fresh->send_at))
                ->onQueue('email-outbound')
                ->delay(now()->addSeconds($poll));
        } finally {
            self::$rechecking = false;
        }
    }

    /** @param  array<string, mixed>  $state */
    private function blockReason(Thread $thread, array $state): ?string
    {
        $conversation = Conversation::find($thread->conversation_id);
        if ($conversation === null || in_array($conversation->status, [ConversationStatus::Closed, ConversationStatus::Spam], true)) {
            return 'conversation_closed';
        }

        if (($autonomy = self::autonomyBlockedReason((int) $conversation->workspace_id)) !== null) {
            return $autonomy;
        }

        $run = isset($state['run_id']) ? AgentRun::find((int) $state['run_id']) : null;
        if ($run === null || $run->decision !== PolicyGate::AUTO_SEND || (int) $run->conversation_id !== $conversation->id) {
            return 'run_missing';
        }

        $playbook = $run->playbook_id !== null ? Playbook::find($run->playbook_id) : null;
        if ($playbook === null || $playbook->mode !== Playbook::MODE_AUTO || $playbook->archived_at !== null || $playbook->always_human) {
            return 'playbook_mode';
        }
        if ($playbook->auto_hold !== null) {
            return 'playbook_hold';
        }
        if (! PolicyGate::inRollout(PolicyGate::rolloutBucket($conversation->id), (int) $playbook->rollout_percent)) {
            return 'rollout';
        }

        // Anything newer than the customer message the run answered.
        $newer = Thread::where('conversation_id', $conversation->id)
            ->where('type', ThreadType::Message->value)
            ->where('id', '!=', $thread->id)
            ->where('id', '>', (int) $run->customer_thread_id)
            ->orderBy('id')
            ->get(['id', 'customer_id']);
        if ($newer->contains(fn (Thread $t) => $t->customer_id !== null)) {
            return 'new_customer_message';
        }
        if ($newer->isNotEmpty()) {
            return 'staff_replied';
        }

        $customer = $conversation->customer_id !== null ? Customer::find($conversation->customer_id) : null;
        if ($customer === null || $customer->is_blocked) {
            return $customer === null ? 'no_recipient' : 'customer_blocked';
        }
        if ($conversation->channel_type === ChannelType::Email && blank($customer->email)) {
            return 'no_recipient';
        }

        // Actions the reply depends on (docs/agent-actions.md "Reply coupling"):
        // only an all-unattended, all-succeeded set may go; anything needing a
        // human, failed or unknown cancels back to a draft; still running = wait.
        $actions = ActionGate::autoSendVerdict($run);
        if ($actions['verdict'] === ActionGate::VERDICT_CANCEL) {
            return (string) $actions['reason'];
        }
        if ($actions['verdict'] === ActionGate::VERDICT_WAIT || ActionGate::blocksSending($run)) {
            return self::WAIT;
        }

        return null;
    }
}
