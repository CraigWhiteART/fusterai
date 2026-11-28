<?php

namespace App\Domains\Agent;

use App\Domains\Agent\Console\ImportLegacyCommand;
use App\Domains\Agent\Services\AgentBootstrapper;
use App\Domains\Agent\Services\SentReplyCapture;
use App\Domains\Conversation\Models\Thread;
use App\Support\Hooks;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The agent domain: pipeline, instructions (voice / playbooks / knowledge),
 * runs and feedback. Always registered; agent_settings.mode decides whether
 * it drafts.
 */
class AgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AgentBootstrapper::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ImportLegacyCommand::class, Console\CategoriseCommand::class]);
            // History mining (app/Domains/Agent/Mining, docs/agent-mining.md).
            $this->commands([Mining\Console\MineCommand::class]);
        }

        Route::middleware('web')->group(base_path('routes/agent.php'));

        // Sent-reply capture: a staff reply from fusterai (ThreadService) or
        // from Gmail (mail ingest) closes the pending draft run with a
        // feedback event. Never allowed to break sending.
        Hooks::addAction('thread.created', function ($thread): void {
            if (! $thread instanceof Thread) {
                return;
            }

            try {
                app(SentReplyCapture::class)->capture($thread);
            } catch (\Throwable $e) {
                Log::warning('Agent sent-reply capture failed', ['thread_id' => $thread->id, 'error' => $e->getMessage()]);
            }
        });

        // ── Auto-send (Phase 8, docs/agent-autosend.md) ──────────────────────
        // A new customer message or a staff reply (app or Gmail) cancels any
        // scheduled auto-reply on that conversation (before the pipeline
        // re-runs for the new message); a staff reply to a message the agent
        // answered in shadow queues the shadow comparison.
        if ($this->app->runningInConsole()) {
            $this->commands([AutoSend\Console\AutoSendBreakersCommand::class]);
        }
        Hooks::addAction('thread.created', function ($thread): void {
            if (! $thread instanceof Thread) {
                return;
            }

            try {
                app(AutoSend\AutoSendService::class)->onNewMessage($thread);
            } catch (\Throwable $e) {
                Log::error('Cancelling pending auto-sends failed', ['thread_id' => $thread->id, 'error' => $e->getMessage()]);
            }

            try {
                $run = app(AutoSend\Shadow\ShadowComparer::class)->pendingRunFor($thread);
                if ($run !== null) {
                    AutoSend\Shadow\CompareShadowRunJob::dispatch($run->id, $thread->id);
                }
            } catch (\Throwable $e) {
                Log::warning('Queueing the shadow comparison failed', ['thread_id' => $thread->id, 'error' => $e->getMessage()]);
            }
        });
        // ── end: auto-send ───────────────────────────────────────────────────

        // ── QA reviews (Phase 7, docs/agent-qa.md) ───────────────────────────
        // conversation.closed queues a review; a completed run checks for an
        // unhappy return after an AI-drafted reply.
        if ($this->app->runningInConsole()) {
            $this->commands([Qa\Console\QaBackfillCommand::class]);
        }
        Qa\QaHooks::register();
        // ── end: QA ──────────────────────────────────────────────────────────
    }
}
