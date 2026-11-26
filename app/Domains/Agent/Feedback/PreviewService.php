<?php

namespace App\Domains\Agent\Feedback;

use App\Domains\Agent\Http\Controllers\RunController;
use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\Proposal;
use App\Domains\Agent\Models\ProposalChange;
use App\Domains\Agent\Pipeline\AgentPipeline;
use App\Domains\Agent\Pipeline\EvidenceLedger;
use App\Domains\Agent\Pipeline\HandoverReasons;
use App\Domains\Agent\Pipeline\InstructionSet;
use App\Domains\Agent\Pipeline\PipelineInput;
use App\Domains\Agent\Pipeline\Transcript;
use App\Domains\Agent\Proposals\ProposalService;
use App\Domains\Conversation\Models\Conversation;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * Re-runs THIS conversation with an instruction change overlaid, purpose
 * `preview`: the transcript as the original run saw it (cut at the message
 * it answered) and the evidence that run recorded. Nothing is written but
 * the preview run row: no AiSuggestion, no broadcast to the conversation,
 * no conversation change, no Shopify / carrier / mail call.
 */
class PreviewService
{
    public function __construct(
        private readonly AgentPipeline $pipeline,
        private readonly ProposalService $proposals,
    ) {}

    /** @param  array<string, mixed>  $overlay  InstructionSet overlay */
    public function run(AgentRun $baseline, array $overlay): AgentRun
    {
        $conversation = $baseline->conversation_id !== null ? Conversation::find($baseline->conversation_id) : null;
        if ($conversation === null) {
            throw new RuntimeException('The conversation for this draft no longer exists.');
        }

        $live = PipelineInput::fromConversation($conversation, AgentRun::PURPOSE_PREVIEW);
        $turns = $live->transcript->turns;
        if ($baseline->customer_thread_id !== null) {
            $cut = null;
            foreach ($turns as $i => $turn) {
                if ($turn->threadId === $baseline->customer_thread_id) {
                    $cut = $i;
                }
            }
            if ($cut !== null) {
                $turns = array_slice($turns, 0, $cut + 1);
            }
        }

        $evidence = (array) ($baseline->evidence ?? []);
        unset($evidence['knowledge']);

        $input = new PipelineInput(
            workspaceId: $live->workspaceId,
            purpose: AgentRun::PURPOSE_PREVIEW,
            transcript: new Transcript(array_values($turns)),
            now: CarbonImmutable::now(),
            subject: $live->subject,
            customerName: $live->customerName,
            customerEmail: $live->customerEmail,
            replyToEmails: $live->replyToEmails,
            conversation: $conversation,
            suppliedEvidence: $evidence !== [] ? EvidenceLedger::fromArray($evidence) : null,
        );

        $result = $this->pipeline->run($input, InstructionSet::live($live->workspaceId)->withOverlay($overlay));
        if ($result->run === null || $result->wasSkipped()) {
            throw new RuntimeException('The preview did not run: '.($result->skipped ?? 'unknown'));
        }

        return $result->run;
    }

    /**
     * Preview a proposal on its conversation and store the outcome in
     * proposal.preview.
     *
     * @return array<string, mixed> proposal.preview
     */
    public function previewProposal(Proposal $proposal): array
    {
        $baseline = $proposal->run_id !== null ? AgentRun::find($proposal->run_id) : null;
        if ($baseline === null) {
            $preview = ['status' => 'skipped', 'run_id' => null, 'baseline_run_id' => null, 'error' => 'This proposal has no draft to preview against.', 'at' => now()->toIso8601String()];
            $proposal->forceFill(['preview' => $preview])->save();

            return $preview;
        }

        $proposal->forceFill(['preview' => ['status' => 'running', 'run_id' => null, 'baseline_run_id' => $baseline->id, 'error' => null, 'at' => now()->toIso8601String()]])->save();

        try {
            $run = $this->run($baseline, $this->proposals->overlay($proposal->loadMissing('changes')));
            $preview = ['status' => 'ready', 'run_id' => $run->id, 'baseline_run_id' => $baseline->id, 'error' => null, 'at' => now()->toIso8601String()];
        } catch (Throwable $e) {
            $preview = ['status' => 'failed', 'run_id' => null, 'baseline_run_id' => $baseline->id, 'error' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 500), 'at' => now()->toIso8601String()];
        }

        // The changes were rewritten meanwhile (exclusion, conflict resolution,
        // rebase): this preview is for ops that no longer exist, so drop it.
        $previewed = $proposal->changes->pluck('id')->sort()->values()->all();
        if (ProposalChange::where('proposal_id', $proposal->id)->orderBy('id')->pluck('id')->all() !== $previewed) {
            return [...$preview, 'status' => 'superseded'];
        }

        $proposal->forceFill(['preview' => $preview])->save();

        return $preview;
    }

    /**
     * Ephemeral overlay for a one-off correction: the instruction becomes an
     * extra rule of the playbook the draft used (or a voice "do" when it used
     * none). Nothing is saved.
     *
     * @return array<string, mixed>
     */
    public function oneOffOverlay(AgentRun $run, string $instruction): array
    {
        $instruction = mb_substr(trim($instruction), 0, 1000);
        $set = InstructionSet::live($run->workspace_id);
        $playbook = $run->playbook_id !== null ? collect($set->playbooks)->first(fn ($p) => $p->id === $run->playbook_id) : null;

        if ($playbook !== null) {
            return ['playbooks' => [$playbook->slug => [
                'rules' => [...$playbook->rules, ['id' => 'one-off', 'if' => 'always', 'then' => 'For this reply only: '.$instruction]],
            ]]];
        }

        return ['voice' => ['do_phrases' => [...$set->voice->doPhrases, ['id' => 'one-off', 'text' => 'For this reply only: '.$instruction]]]];
    }

    /**
     * API shape of a preview: old vs new draft and the instruction diff.
     *
     * @param  array<string, mixed>|null  $preview  {status, run_id, baseline_run_id, error}
     * @param  list<array<string, mixed>>  $diff
     * @return array<string, mixed>|null
     */
    public static function present(?array $preview, array $diff = []): ?array
    {
        if ($preview === null) {
            return null;
        }
        $old = isset($preview['baseline_run_id']) ? AgentRun::find($preview['baseline_run_id']) : null;
        $new = isset($preview['run_id']) ? AgentRun::with(['playbook', 'playbookVersion', 'feedback'])->find($preview['run_id']) : null;

        return [
            'status' => $preview['status'] ?? 'pending',     // pending | running | ready | failed | skipped
            'error' => $preview['error'] ?? null,
            'baseline_run_id' => $old?->id,
            'run_id' => $new?->id,
            'old_draft' => $old?->draft,
            'old_decision' => $old?->decision,
            'new_draft' => $new?->draft,
            'new_decision' => $new?->decision,
            'new_handover_reason' => $new?->handover_reason,
            'new_handover_label' => $new?->handover_reason !== null ? HandoverReasons::label($new->handover_reason) : null,
            'instruction_diff' => $diff,
            'new_run' => $new !== null ? RunController::present($new) : null,
            'at' => $preview['at'] ?? null,
        ];
    }
}
