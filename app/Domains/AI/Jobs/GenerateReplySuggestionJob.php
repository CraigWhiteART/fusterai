<?php

namespace App\Domains\AI\Jobs;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Pipeline\AgentPipeline;
use App\Domains\Agent\Pipeline\PipelineInput;
use App\Domains\Conversation\Models\Conversation;
use App\Events\AiSuggestionFailed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Drafts a reply for the latest customer message with the agent pipeline
 * (AgentPipeline writes the AiSuggestion and broadcasts AiSuggestionReady /
 * AiSuggestionFailed with the run id).
 *
 * agent_settings.mode decides what happens on its own (inbound mail, chat):
 *   draft / live  a live run (the visible draft; live may auto-send)
 *   shadow        a shadow run only (recorded, nobody sees anything)
 *   off           nothing
 *
 * A person clicking "AI Suggest" (`manual: true`, POST /ai/conversations/{id}/suggest-reply)
 * always gets a one-off live draft, also when the agent is off or in shadow:
 * a person is present and asked for it. It is never auto-sent (the gate's
 * `requested_by_human` blocker) and nothing runs unattended outside `live`.
 * With the kill switch on, nothing runs and the reason is broadcast.
 *
 * Safe to retry: a live run is keyed by conversation + latest customer
 * thread + config hash, so a second dispatch (or a retry after a crash)
 * reuses the completed run instead of drafting again; an explicit regenerate
 * or manual request carries its own token, stable across retries of the job.
 */
class GenerateReplySuggestionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Below the `ai` Horizon supervisor's 330s timeout (config/horizon.php). */
    public int $timeout = 300;

    /** @var list<int> */
    public array $backoff = [15, 60];

    /** Set for an explicit regenerate / manual request: makes a new run, stable across retries of this job. */
    public readonly ?string $regenerateToken;

    public function __construct(
        public readonly Conversation $conversation,
        bool $regenerate = false,
        public readonly bool $manual = false,
    ) {
        $this->regenerateToken = $regenerate || $manual ? (string) Str::uuid7() : null;
        $this->onQueue('ai');
    }

    public function handle(): void
    {
        $settings = AgentSetting::forWorkspace((int) $this->conversation->workspace_id);
        $conversation = $this->conversation->fresh() ?? $this->conversation;

        if ($settings->pipelineOwnsDrafts() || $this->manual) {
            if ($settings->kill_switch) {
                if ($this->manual) {
                    broadcast(new AiSuggestionFailed($conversation->id, 'The AI agent kill switch is on, so it cannot draft. Turn it off in Agent settings.'));
                }

                return;
            }

            app(AgentPipeline::class)->run(
                PipelineInput::fromConversation(
                    $conversation,
                    AgentRun::PURPOSE_LIVE,
                    force: $this->regenerateToken !== null,
                    manual: $this->manual && ! $settings->pipelineOwnsDrafts(),
                ),
                idempotencyKey: $this->regenerateToken !== null ? 'live:regen:'.$this->regenerateToken : null,
            );

            return;
        }

        if ($settings->mode === AgentSetting::MODE_SHADOW) {
            try {
                app(AgentPipeline::class)->run(PipelineInput::fromConversation($conversation, AgentRun::PURPOSE_SHADOW));
            } catch (\Throwable $e) {
                Log::warning('Agent shadow run failed', ['conversation_id' => $conversation->id, 'error' => $e->getMessage()]);
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        broadcast(new AiSuggestionFailed(
            $this->conversation->id,
            'Could not generate a suggestion. Please check your AI configuration.',
        ));
    }
}
