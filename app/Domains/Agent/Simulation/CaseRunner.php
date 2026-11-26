<?php

namespace App\Domains\Agent\Simulation;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\FrozenCase;
use App\Domains\Agent\Pipeline\AgentPipeline;
use App\Domains\Agent\Pipeline\EvidenceCollector;
use App\Domains\Agent\Pipeline\EvidenceLedger;
use App\Domains\Agent\Pipeline\InstructionSet;
use App\Domains\Agent\Pipeline\PipelineInput;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Runs the pipeline on a frozen case with purpose `simulation`: the frozen
 * transcript, "now" = the cut, and only the evidence stored on the case.
 * No conversation is passed in, so nothing live is read or written, and no
 * Shopify / carrier / mail call can happen.
 */
class CaseRunner
{
    public function __construct(
        private readonly AgentPipeline $pipeline,
        private readonly EvidenceCollector $evidence,
    ) {}

    public function run(FrozenCase $case, InstructionSet $instructions, string $purpose = AgentRun::PURPOSE_SIMULATION): AgentRun
    {
        $input = $this->input($case, $purpose);
        $result = $this->pipeline->run($input, $instructions);

        if ($result->run === null || $result->wasSkipped()) {
            throw new RuntimeException('The pipeline skipped this case: '.($result->skipped ?? 'no run'));
        }

        return $result->run;
    }

    public function input(FrozenCase $case, string $purpose = AgentRun::PURPOSE_SIMULATION): PipelineInput
    {
        $now = CarbonImmutable::instance($case->cut_at);
        $replyTo = array_values(array_map('strval', (array) ($case->reply_to ?? [])));
        $base = fn (EvidenceLedger $ledger) => PipelineInput::fromTranscript(
            workspaceId: $case->workspace_id,
            purpose: $purpose,
            turns: $case->transcript,
            evidence: $ledger,
            now: $now,
            subject: (string) $case->subject,
            customerName: $case->customer_name,
            customerEmail: $case->customer_email,
            replyToEmails: $replyTo,
            frozenCaseId: $case->id,
        );

        $evidence = (array) ($case->evidence ?? []);
        $ledger = $this->evidence->ledgerFromPayloads(
            $base(new EvidenceLedger),
            $case->evidence_quality === FrozenCase::QUALITY_NONE ? null : (is_array($evidence['shopify'] ?? null) ? $evidence['shopify'] : null),
            is_array($evidence['tracking'] ?? null) ? $evidence['tracking'] : null,
            isset($evidence['shopify_snapshot_id']) ? (int) $evidence['shopify_snapshot_id'] : null,
            isset($evidence['tracking_snapshot_id']) ? (int) $evidence['tracking_snapshot_id'] : null,
        );
        $ledger->setMeta('evidence_quality', $case->evidence_quality);
        foreach ((array) ($evidence['notes'] ?? []) as $note) {
            $ledger->note((string) $note);
        }
        if ($case->evidence_quality === FrozenCase::QUALITY_RECONSTRUCTED) {
            $ledger->note('Order and carrier facts were reconstructed from the order\'s own timestamps as of this message; details may differ from what staff saw.');
        }

        return $base($ledger);
    }
}
