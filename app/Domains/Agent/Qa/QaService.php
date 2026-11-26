<?php

namespace App\Domains\Agent\Qa;

use App\Domains\Agent\Jobs\ReviewConversationJob;
use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Qa\Models\QaGap;
use App\Domains\Agent\Qa\Models\QaReview;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Enums\ThreadType;
use App\Services\AiSettingsService;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * QA triggers: conversation.closed (sampling + daily cap + delay), the
 * backfill, a forced review (manual, or the customer came back unhappy
 * after an AI-drafted reply), and the human "dismiss / mark incorrect".
 * Every review row is unique per (conversation, reviewed thread, prompt
 * version), so re-firing a trigger never reviews the same reply twice.
 */
class QaService
{
    /** @var (callable(): float)|null */
    public static $random = null;

    public function __construct(
        private readonly QaCaseBuilder $cases,
        private readonly AiSettingsService $ai,
        private readonly GapGrouper $gaps,
    ) {}

    public static function promptVersion(): string
    {
        return (string) config('agent_qa.prompt_version', 'qa-v1');
    }

    /** Why QA does not run for this workspace at all, or null. */
    public function disabledReason(int $workspaceId): ?string
    {
        if (! config('agent_qa.enabled', true)) {
            return 'disabled';
        }
        if (AgentSetting::forWorkspace($workspaceId)->mode === AgentSetting::MODE_OFF) {
            return 'agent_off';
        }
        try {
            $creds = $this->ai->resolveCredentials($workspaceId, 'simulation_judge');
        } catch (Throwable) {
            return 'ai_not_configured';
        }

        return filled($creds['key'] ?? null) ? null : 'ai_not_configured';
    }

    /** Hook conversation.closed. Returns the review row (pending / skipped) or null. */
    public function onClosed(Conversation $conversation): ?QaReview
    {
        if ($conversation->imported || $this->disabledReason($conversation->workspace_id) !== null) {
            return null;
        }

        $case = $this->cases->build($conversation);
        if (! isset($case['reviewed'])) {
            return null;
        }
        if ($this->existing($conversation, $case['reviewed']) !== null) {
            return null;
        }

        $skip = $case['skip'];
        $skip ??= $this->random() >= (float) config('agent_qa.sample_rate', 1.0) ? 'not_sampled' : null;
        $skip ??= $this->todayCount($conversation->workspace_id) >= (int) config('agent_qa.daily_cap', 60) ? 'daily_cap' : null;

        $review = $this->createRow($conversation, $case, 'closed', $skip);
        if ($review !== null && $review->status === QaReview::STATUS_PENDING) {
            ReviewConversationJob::dispatch($review->id)->delay(now()->addMinutes((int) config('agent_qa.delay_minutes', 10)));
        }

        return $review;
    }

    /**
     * Backfill one conversation (no sampling / daily cap: the command has its
     * own limit). Returns the pending row, or null when skipped / existing.
     */
    public function backfill(Conversation $conversation, bool $queue = true, bool $sync = false): ?QaReview
    {
        $case = $this->cases->build($conversation);
        if (! isset($case['reviewed']) || $this->existing($conversation, $case['reviewed']) !== null) {
            return null;
        }
        $review = $this->createRow($conversation, $case, 'backfill', $case['skip']);
        if ($review === null || $review->status !== QaReview::STATUS_PENDING) {
            return null;
        }
        if ($sync) {
            app(QaReviewer::class)->review($review);
        } elseif ($queue) {
            ReviewConversationJob::dispatch($review->id)
                ->onConnection(config('agent_qa.backfill.connection'))
                ->onQueue((string) config('agent_qa.backfill.queue', 'ai-bulk'));
        }

        return $review;
    }

    /**
     * Full review now, skipping the first pass, sampling and the daily cap
     * (manual request, unhappy return). Upgrades an existing first-pass-only
     * or skipped row; a row that already had a full review is left alone.
     */
    public function forceReview(Conversation $conversation, ?Thread $reviewed, string $trigger): ?QaReview
    {
        $case = $this->cases->build($conversation, requireClosed: false, reviewedThreadId: $reviewed?->id);
        if (! isset($case['reviewed'])) {
            return null;
        }
        $review = $this->existing($conversation, $case['reviewed'])
            ?? $this->createRow($conversation, $case, $trigger, null);
        if ($review === null || ($review->status === QaReview::STATUS_REVIEWED && ($review->forced || $trigger !== 'unhappy_return'))) {
            return $review;
        }

        $review->forceFill(['status' => QaReview::STATUS_PENDING, 'forced' => true, 'trigger' => $trigger, 'skip_reason' => null, 'error' => null])->save();
        ReviewConversationJob::dispatch($review->id);

        return $review;
    }

    /**
     * The customer came back unhappy after an AI-drafted reply: a live run
     * on a conversation answering a customer message that arrived within
     * agent_qa.unhappy_return.days of an AI / AI-edited reply that had been
     * through QA on close (so the conversation was closed in between), with
     * negative triage sentiment. Forces a full review of that reply.
     */
    public function checkUnhappyReturn(AgentRun $run): ?QaReview
    {
        if ($run->status !== AgentRun::STATUS_COMPLETED || ! in_array($run->purpose, [AgentRun::PURPOSE_LIVE, AgentRun::PURPOSE_SHADOW], true)
            || $run->conversation_id === null || $run->customer_thread_id === null) {
            return null;
        }
        $sentiment = (string) ($run->triage['sentiment'] ?? '');
        if (! in_array($sentiment, (array) config('agent_qa.unhappy_return.sentiments', ['frustrated', 'angry']), true)) {
            return null;
        }
        $customer = Thread::find($run->customer_thread_id);
        $at = $customer === null ? null : ($customer->sent_at ?? $customer->created_at);
        if ($customer === null || $at === null) {
            return null;
        }

        $review = QaReview::with('reviewedThread')->where('conversation_id', $run->conversation_id)
            ->whereIn('author_type', [QaReview::AUTHOR_AI, QaReview::AUTHOR_AI_EDITED])
            ->where('reviewer_prompt_version', self::promptVersion())
            ->where('created_at', '<=', $at)
            ->latest('id')->first();
        $replyAt = $review === null ? null : ($review->replied_at ?? $review->reviewedThread?->created_at);
        if ($review === null || $replyAt === null || $replyAt->gt($at) || $replyAt->lt($at->copy()->subDays((int) config('agent_qa.unhappy_return.days', 14)))) {
            return null;
        }
        if ($review->forced && $review->trigger === 'unhappy_return') {
            return $review;
        }
        $conversation = Conversation::find($run->conversation_id);
        if ($conversation === null || $this->disabledReason($conversation->workspace_id) !== null) {
            return null;
        }

        $forced = $this->forceReview($conversation, $review->reviewedThread, 'unhappy_return');
        $forced?->forceFill(['triage' => [...(array) ($forced->triage ?? []), 'unhappy_return' => ['run_id' => $run->id, 'sentiment' => $sentiment, 'customer_thread_id' => $customer->id]]])->save();

        return $forced;
    }

    /** Human disagreement: excluded from aggregates and gap counts. Null clears it. */
    public function setHumanVerdict(QaReview $review, ?string $verdict, ?string $note, int $userId): QaReview
    {
        $review->forceFill([
            'human_verdict' => $verdict,
            'human_note' => $verdict === null ? null : $note,
            'human_by' => $verdict === null ? null : $userId,
            'human_at' => $verdict === null ? null : now(),
        ])->save();

        if ($review->gap_id !== null && ($gap = QaGap::find($review->gap_id)) !== null) {
            $this->gaps->recount($gap);
        } elseif ($verdict === null) {
            $this->gaps->attach($review);
        }

        return $review->fresh() ?? $review;
    }

    private function existing(Conversation $conversation, Thread $reviewed): ?QaReview
    {
        return QaReview::where('conversation_id', $conversation->id)->where('reviewed_thread_id', $reviewed->id)
            ->where('reviewer_prompt_version', self::promptVersion())->first();
    }

    /** @param  array<string, mixed>  $case  QaCaseBuilder::build() result with a reviewed thread */
    private function createRow(Conversation $conversation, array $case, string $trigger, ?string $skip): ?QaReview
    {
        /** @var Thread $reviewed */
        $reviewed = $case['reviewed'];
        $customer = $case['customer'] ?? null;
        $run = $case['run'] ?? null;

        try {
            return QaReview::create([
                'workspace_id' => $conversation->workspace_id,
                'conversation_id' => $conversation->id,
                'reviewed_thread_id' => $reviewed->id,
                'customer_thread_id' => $customer?->id,
                'author_type' => $case['author_type'] ?? null,
                'author_user_id' => $case['author_user_id'] ?? null,
                'run_id' => $run?->id,
                'playbook_id' => $run?->playbook_id,
                'status' => $skip === null ? QaReview::STATUS_PENDING : QaReview::STATUS_SKIPPED,
                'skip_reason' => $skip,
                'trigger' => $trigger,
                'reviewer_prompt_version' => self::promptVersion(),
                'replied_at' => $reviewed->sent_at ?? $reviewed->created_at,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function todayCount(int $workspaceId): int
    {
        return QaReview::where('workspace_id', $workspaceId)->where('trigger', 'closed')
            ->where('status', '!=', QaReview::STATUS_SKIPPED)->where('created_at', '>=', now()->startOfDay())->count();
    }

    private function random(): float
    {
        return self::$random !== null ? (float) (self::$random)() : mt_rand() / mt_getrandmax();
    }

    /** Thread type helper for callers outside the domain. */
    public static function isStaffMessage(Thread $thread): bool
    {
        return $thread->type === ThreadType::Message && $thread->customer_id === null;
    }
}
