<?php

namespace App\Domains\Agent\Jobs;

use App\Domains\Agent\Events\AgentLoopUpdated;
use App\Domains\Agent\Feedback\PreviewService;
use App\Domains\Agent\Models\Proposal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Re-runs a proposal's conversation with its ops overlaid (purpose preview). `ai` queue. */
class PreviewProposalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 320;

    public function __construct(public readonly int $proposalId)
    {
        $this->onQueue('ai');
    }

    public function handle(PreviewService $preview): void
    {
        $proposal = Proposal::find($this->proposalId);
        if ($proposal === null) {
            return;
        }

        $state = $preview->previewProposal($proposal);
        AgentLoopUpdated::dispatchSafely($proposal->workspace_id, AgentLoopUpdated::PREVIEW_READY, [
            'feedback_id' => $proposal->feedback_event_id,
            'conversation_id' => $proposal->conversation_id,
            'proposal_id' => $proposal->id,
            'status' => $state['status'] ?? null,
            'run_id' => $state['run_id'] ?? null,
        ]);
    }
}
