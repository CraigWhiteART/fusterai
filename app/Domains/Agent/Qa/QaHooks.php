<?php

namespace App\Domains\Agent\Qa;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Conversation\Models\Conversation;
use App\Support\Hooks;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Wires QA into the app (called once from AgentServiceProvider::boot):
 *  - conversation.closed -> QaService::onClosed (queues a delayed review);
 *  - a live / shadow run completing -> the unhappy-return check.
 * Neither may ever break the action that triggered it.
 */
final class QaHooks
{
    public static function register(): void
    {
        Hooks::addAction('conversation.closed', function ($conversation): void {
            if (! $conversation instanceof Conversation) {
                return;
            }
            try {
                app(QaService::class)->onClosed($conversation);
            } catch (Throwable $e) {
                Log::warning('QA close hook failed', ['conversation_id' => $conversation->id, 'error' => $e->getMessage()]);
            }
        });

        AgentRun::saved(function (AgentRun $run): void {
            if ($run->status !== AgentRun::STATUS_COMPLETED || ! ($run->wasRecentlyCreated || $run->wasChanged('status'))) {
                return;
            }
            if (! in_array($run->purpose, [AgentRun::PURPOSE_LIVE, AgentRun::PURPOSE_SHADOW], true)) {
                return;
            }
            try {
                app(QaService::class)->checkUnhappyReturn($run);
            } catch (Throwable $e) {
                Log::warning('QA unhappy-return check failed', ['run_id' => $run->id, 'error' => $e->getMessage()]);
            }
        });
    }
}
