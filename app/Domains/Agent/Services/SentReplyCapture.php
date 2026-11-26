<?php

namespace App\Domains\Agent\Services;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\FeedbackEvent;
use App\Domains\Agent\Pipeline\PolicyGate;
use App\Domains\Conversation\Models\Thread;
use App\Enums\ThreadType;
use App\Support\PlainText;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Records what a human did with the agent's draft when a staff reply is sent
 * from fusterai or ingested from Gmail (thread.created). Replaces
 * EditLearningService for the agent pipeline.
 *
 *   app reply, derived from the draft:  sent_as_is (0% changed) | sent_edited (with %)
 *   app reply, not derived:             rejected (meta.reason = wrote_own_reply)
 *   Gmail reply:                        gmail_reply (% only when derived)
 *
 * "Derived" = at most DERIVED_MAX_CHANGE percent different from the draft.
 */
class SentReplyCapture
{
    public const DERIVED_MAX_CHANGE = 70;

    private const COMPARE_CHARS = 4000;

    public function capture(Thread $thread): ?FeedbackEvent
    {
        if ($thread->type !== ThreadType::Message || $thread->customer_id !== null) {
            return null;
        }

        $fromGmail = $thread->origin === Thread::ORIGIN_GMAIL;
        if (! $fromGmail && $thread->user_id === null) {
            return null;
        }
        if (in_array($thread->origin, [Thread::ORIGIN_IMPORT, Thread::ORIGIN_AI], true)) {
            return null;
        }

        $run = AgentRun::where('conversation_id', $thread->conversation_id)
            ->where('purpose', AgentRun::PURPOSE_LIVE)
            ->where('status', AgentRun::STATUS_COMPLETED)
            // A cancelled auto-send came back as a normal draft (Phase 8).
            ->where(fn ($q) => $q->where('decision', PolicyGate::DRAFT)
                ->orWhere(fn ($q) => $q->where('decision', PolicyGate::AUTO_SEND)->where('auto_send->status', 'cancelled')))
            ->whereNotNull('draft')
            ->where('created_at', '<=', $thread->created_at ?? now())
            ->whereDoesntHave('feedback', fn ($q) => $q->whereIn('kind', [
                FeedbackEvent::KIND_SENT_AS_IS,
                FeedbackEvent::KIND_SENT_EDITED,
                FeedbackEvent::KIND_GMAIL_REPLY,
                FeedbackEvent::KIND_REJECTED,
            ]))
            ->latest('id')
            ->first();

        if ($run === null) {
            return null;
        }

        $final = PlainText::from($thread->body_plain ?: $thread->body);
        $draft = (string) $run->draft;
        $percent = PlainText::percentChanged(mb_substr($draft, 0, self::COMPARE_CHARS), mb_substr($final, 0, self::COMPARE_CHARS));
        $derived = $percent <= self::DERIVED_MAX_CHANGE;

        $kind = match (true) {
            $fromGmail => FeedbackEvent::KIND_GMAIL_REPLY,
            ! $derived => FeedbackEvent::KIND_REJECTED,
            $percent === 0 => FeedbackEvent::KIND_SENT_AS_IS,
            default => FeedbackEvent::KIND_SENT_EDITED,
        };

        try {
            return FeedbackEvent::create([
                'workspace_id' => $run->workspace_id,
                'run_id' => $run->id,
                'conversation_id' => $thread->conversation_id,
                'thread_id' => $thread->id,
                'user_id' => $thread->user_id,
                'kind' => $kind,
                'draft_before' => $draft,
                'final_text' => $final,
                'percent_changed' => $derived ? $percent : null,
                'meta' => [
                    'derived' => $derived,
                    'similarity_change' => $percent,
                    'origin' => $thread->origin,
                    'reason' => $kind === FeedbackEvent::KIND_REJECTED ? 'wrote_own_reply' : null,
                ],
            ]);
        } catch (UniqueConstraintViolationException) {
            // thread.created fired twice for the same thread: already recorded.
            return FeedbackEvent::where('thread_id', $thread->id)->first();
        }
    }
}
