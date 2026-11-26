<?php

namespace App\Domains\Agent\AutoSend;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Pipeline\PolicyGate;
use App\Domains\Conversation\Models\Thread;
use App\Enums\ThreadType;
use Illuminate\Database\Eloquent\Builder;

/**
 * Counts for caps and the hourly breaker. An auto-send decision counts from
 * the moment it is made, including ones later cancelled (conservative: a
 * cancel never frees up a cap).
 */
final class AutoSendCounts
{
    public static function today(int $workspaceId): int
    {
        return self::decisions($workspaceId)->where('created_at', '>=', now()->startOfDay())->count();
    }

    public static function lastHour(int $workspaceId): int
    {
        return self::decisions($workspaceId)->where('created_at', '>=', now()->subHour())->count();
    }

    public static function todayForCustomer(int $workspaceId, ?int $customerId): int
    {
        if ($customerId === null) {
            return PHP_INT_MAX;
        }

        return self::decisions($workspaceId)
            ->where('created_at', '>=', now()->startOfDay())
            ->whereHas('conversation', fn ($q) => $q->where('customer_id', $customerId))
            ->count();
    }

    /**
     * Auto-replies (sent or still scheduled) on this conversation since the
     * last message a human wrote.
     */
    public static function consecutive(int $conversationId): int
    {
        $lastHuman = Thread::where('conversation_id', $conversationId)
            ->where('type', ThreadType::Message->value)
            ->whereNull('customer_id')
            ->where(fn ($q) => $q->whereNull('origin')->orWhere('origin', '!=', Thread::ORIGIN_AI))
            ->max('id');

        return Thread::where('conversation_id', $conversationId)
            ->where('type', ThreadType::Message->value)
            ->where('origin', Thread::ORIGIN_AI)
            ->when($lastHuman !== null, fn ($q) => $q->where('id', '>', $lastHuman))
            ->count();
    }

    /** @return Builder<AgentRun> */
    private static function decisions(int $workspaceId): Builder
    {
        return AgentRun::where('workspace_id', $workspaceId)
            ->where('purpose', AgentRun::PURPOSE_LIVE)
            ->where('decision', PolicyGate::AUTO_SEND);
    }
}
