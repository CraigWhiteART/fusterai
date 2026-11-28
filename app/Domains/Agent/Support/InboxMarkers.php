<?php

namespace App\Domains\Agent\Support;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Pipeline\HandoverReasons;
use App\Domains\Conversation\Models\Conversation;
use App\Enums\ConversationStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * Where the agent got to, per conversation of an inbox page: "draft_ready"
 * (a draft is waiting) or "needs_you" (the agent handed over, or its draft
 * says a person still has to decide or do something).
 *
 * It reads the latest completed live run for the conversation's latest
 * customer message, in one query for the whole page. No marker once a
 * person replied after that run, or the draft was used or dismissed.
 * The rule itself is SQL (STATE_SQL) so the inbox can also filter by it.
 */
final class InboxMarkers
{
    public const DRAFT_READY = 'draft_ready';

    public const NEEDS_YOU = 'needs_you';

    /** Conversation statuses that can carry a marker. */
    private const STATUSES = [ConversationStatus::Open, ConversationStatus::Pending];

    /**
     * The one definition of a marker, as SQL over a row of `agent_runs`:
     * 'draft_ready', 'needs_you' or null. The row badges (for()) and the
     * inbox "Draft ready" filter (whereDraftReady()) both read it, so they
     * cannot disagree.
     */
    private const STATE_SQL = <<<'SQL'
case
    -- a person has replied since the run finished
    when (select max(t.created_at) from threads t where t.conversation_id = agent_runs.conversation_id and t.type = 'message' and t.customer_id is null)
        > coalesce(agent_runs.completed_at, agent_runs.created_at) then null
    when agent_runs.decision = 'handover' then 'needs_you'
    -- sent automatically, or the draft was used or dismissed
    when agent_runs.decision is distinct from 'draft' then null
    when (select s.accepted from ai_suggestions s where s.id = agent_runs.ai_suggestion_id) is not null then null
    when coalesce((agent_runs.auto_send -> 'blockers') @> '["needs_human"]'::jsonb, false) then 'needs_you'
    else 'draft_ready'
end
SQL;

    /**
     * SQL for the id of the run that decides a conversation's marker: the
     * newest completed live run for its latest customer message.
     *
     * @param  string  $conversationId  SQL expression for the conversation id
     */
    private static function decidingRunSql(string $conversationId): string
    {
        return <<<SQL
(select max(r.id) from agent_runs r
    where r.conversation_id = {$conversationId} and r.purpose = 'live' and r.status = 'completed'
    and r.customer_thread_id = (select t.id from threads t where t.conversation_id = {$conversationId} and t.type = 'message' and t.customer_id is not null order by coalesce(t.sent_at, t.created_at) desc, t.id desc limit 1))
SQL;
    }

    /**
     * Keep only conversations whose marker is "draft ready".
     *
     * @param  Builder<Conversation>  $query
     */
    public static function whereDraftReady(Builder $query): void
    {
        $query->whereIn('conversations.status', array_map(fn (ConversationStatus $status) => $status->value, self::STATUSES))
            ->whereRaw('exists (select 1 from agent_runs where agent_runs.id = '.self::decidingRunSql('conversations.id').' and ('.self::STATE_SQL.") = '".self::DRAFT_READY."')");
    }

    /**
     * @param  iterable<int, Conversation>  $conversations
     * @return array<int, array{state: string, label: string|null}> keyed by conversation id
     */
    public static function for(iterable $conversations): array
    {
        $ids = [];
        foreach ($conversations as $conversation) {
            if (in_array($conversation->status, self::STATUSES, true)) {
                $ids[] = $conversation->id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $runs = AgentRun::query()
            ->whereIn('conversation_id', $ids)
            ->whereRaw('agent_runs.id = '.self::decidingRunSql('agent_runs.conversation_id'))
            ->select(['id', 'conversation_id', 'decision', 'handover_reason'])
            ->selectRaw('('.self::STATE_SQL.') as marker_state')
            ->get();

        $markers = [];
        foreach ($runs as $run) {
            $state = $run->getAttribute('marker_state');
            if ($state === self::DRAFT_READY) {
                $markers[(int) $run->conversation_id] = ['state' => self::DRAFT_READY, 'label' => null];
            } elseif ($state === self::NEEDS_YOU) {
                $markers[(int) $run->conversation_id] = ['state' => self::NEEDS_YOU, 'label' => self::needsYouLabel($run)];
            }
        }

        return $markers;
    }

    private static function needsYouLabel(AgentRun $run): ?string
    {
        if ($run->decision !== 'handover') {
            return 'the draft has a note about something you need to decide or do';
        }

        return $run->handover_reason !== null ? HandoverReasons::label($run->handover_reason) : null;
    }
}
