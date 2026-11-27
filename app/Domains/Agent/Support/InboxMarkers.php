<?php

namespace App\Domains\Agent\Support;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Pipeline\HandoverReasons;
use App\Domains\Conversation\Models\Conversation;
use App\Enums\ConversationStatus;
use App\Enums\ThreadType;
use Illuminate\Support\Carbon;

/**
 * Where the agent got to, per conversation of an inbox page: "draft_ready"
 * (a draft is waiting) or "needs_you" (the agent handed over, or its draft
 * says a person still has to decide or do something).
 *
 * It reads the latest completed live run for the conversation's latest
 * customer message, in one query for the whole page. No marker once a
 * person replied after that run, or the draft was used or dismissed.
 */
final class InboxMarkers
{
    public const DRAFT_READY = 'draft_ready';

    public const NEEDS_YOU = 'needs_you';

    /**
     * @param  iterable<int, Conversation>  $conversations
     * @return array<int, array{state: string, label: string|null}> keyed by conversation id
     */
    public static function for(iterable $conversations): array
    {
        $ids = [];
        foreach ($conversations as $conversation) {
            if (in_array($conversation->status, [ConversationStatus::Open, ConversationStatus::Pending], true)) {
                $ids[] = $conversation->id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $message = ThreadType::Message->value;
        $runs = AgentRun::query()
            ->whereIn('conversation_id', $ids)
            ->where('purpose', AgentRun::PURPOSE_LIVE)
            ->where('status', AgentRun::STATUS_COMPLETED)
            ->whereRaw('customer_thread_id = (select t.id from threads t where t.conversation_id = agent_runs.conversation_id and t.type = ? and t.customer_id is not null order by coalesce(t.sent_at, t.created_at) desc, t.id desc limit 1)', [$message])
            ->select(['id', 'conversation_id', 'decision', 'handover_reason', 'auto_send', 'created_at', 'completed_at'])
            ->selectRaw('(select max(t.created_at) from threads t where t.conversation_id = agent_runs.conversation_id and t.type = ? and t.customer_id is null) as last_staff_at', [$message])
            ->selectRaw('(select s.accepted from ai_suggestions s where s.id = agent_runs.ai_suggestion_id) as suggestion_accepted')
            ->orderByDesc('id')
            ->get();

        $markers = [];
        foreach ($runs as $run) {
            $conversationId = (int) $run->conversation_id;
            if (array_key_exists($conversationId, $markers)) {
                continue; // an older run for the same message
            }
            $markers[$conversationId] = self::marker($run);
        }

        return array_filter($markers);
    }

    /** @return array{state: string, label: string|null}|null */
    private static function marker(AgentRun $run): ?array
    {
        $finished = $run->completed_at ?? $run->created_at;
        $lastStaffAt = $run->getAttribute('last_staff_at');
        if ($lastStaffAt !== null && $finished !== null && Carbon::parse($lastStaffAt)->gt($finished)) {
            return null; // a person has replied since
        }

        if ($run->decision === 'handover') {
            return ['state' => self::NEEDS_YOU, 'label' => $run->handover_reason !== null ? HandoverReasons::label($run->handover_reason) : null];
        }
        if ($run->decision !== 'draft' || $run->getAttribute('suggestion_accepted') !== null) {
            return null; // sent automatically, or the draft was used or dismissed
        }
        if (in_array('needs_human', (array) ($run->auto_send['blockers'] ?? []), true)) {
            return ['state' => self::NEEDS_YOU, 'label' => 'the draft has a note about something you need to decide or do'];
        }

        return ['state' => self::DRAFT_READY, 'label' => null];
    }
}
