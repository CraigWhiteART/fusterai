<?php

namespace App\Domains\Agent\Mining;

use App\Domains\Agent\Mining\Models\MiningConversation;
use App\Domains\Agent\Mining\Models\MiningRun;
use App\Domains\Agent\Mining\Models\MiningSituation;
use App\Domains\Agent\Mining\Stages\Extractor;
use App\Domains\Agent\Mining\Stages\ProposalEmitter;
use App\Domains\Agent\Mining\Stages\SituationClusterer;
use App\Domains\Agent\Mining\Stages\Synthesiser;
use App\Domains\Agent\Mining\Stages\VoiceProfiler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Executes a mining run in short, checkpointed slices (same shape as the
 * mail backfill). Each RunMiningStepJob calls runStep() once: slices of the
 * current stage for up to agent_mining.queue.step_seconds, saving after
 * every slice, then the delay before the next job.
 *
 *   select      Stage A, one transaction (recomputed if interrupted)
 *   extract     Stage B, one conversation per slice (checkpoint per conversation)
 *   cluster     Stage C, one pass (recomputed if interrupted; model merge cached)
 *   synthesise  Stage D, one situation per slice
 *   voice       Stage E, one call (cached)
 *   emit        Stage F, one proposal per slice
 *
 * A run that would exceed its budget pauses (pause_reason budget) with a
 * message; resume after raising the cap continues where it stopped. Errors
 * back off 30 s, 60 s ... 15 min; after max_consecutive_errors the run fails
 * and resume carries on from the checkpoint.
 */
final class MiningRunner
{
    public const CONTINUE = 'continue';

    public const FINISHED = 'finished';

    public const STOPPED = 'stopped';

    public function __construct(
        private readonly MiningSelector $selector,
        private readonly Extractor $extractor,
        private readonly SituationClusterer $clusterer,
        private readonly Synthesiser $synthesiser,
        private readonly VoiceProfiler $voice,
        private readonly ProposalEmitter $emitter,
        private readonly PlaybookCatalogue $catalogue,
    ) {}

    public function runStep(int $runId, ?string $token = null): ?int
    {
        $run = MiningRun::query()->find($runId);
        if ($run === null || ! $this->owns($run, $token)) {
            return null;
        }

        if ($run->status === MiningRun::STATUS_QUEUED) {
            MiningRun::query()->whereKey($run->id)->where('status', MiningRun::STATUS_QUEUED)
                ->update(['status' => MiningRun::STATUS_RUNNING, 'started_at' => now(), 'heartbeat_at' => now()]);
            $run->refresh();
        }
        if ($run->status !== MiningRun::STATUS_RUNNING) {
            return null;
        }

        try {
            $outcome = $this->work($run);
        } catch (Throwable $e) {
            return $this->recordFailure($run->id, $e, $token);
        }

        return $outcome === self::CONTINUE ? max(0, (int) config('agent_mining.queue.throttle_seconds', 2)) : null;
    }

    public function recordFailure(int $runId, Throwable $e, ?string $token = null): ?int
    {
        $run = MiningRun::query()->find($runId);
        if ($run === null || ! $this->owns($run, $token) || ! in_array($run->status, [MiningRun::STATUS_RUNNING, MiningRun::STATUS_QUEUED], true)) {
            return null;
        }

        $n = $run->consecutive_errors + 1;
        $giveUp = $n >= max(1, (int) config('agent_mining.queue.max_consecutive_errors', 8));

        $run->forceFill([
            'error_count' => $run->error_count + 1,
            'consecutive_errors' => $n,
            'last_error' => mb_substr('['.$run->stage.'] '.get_class($e).': '.$e->getMessage(), 0, 2000),
            'last_error_at' => now(),
            'heartbeat_at' => now(),
        ]);
        if ($giveUp) {
            $run->status = MiningRun::STATUS_FAILED;
            $run->finished_at = now();
            $run->message = 'Stopped after '.$n.' errors in a row. Resume carries on from the last checkpoint.';
        }
        $run->save();

        Log::warning('Agent mining step failed', ['run_id' => $run->id, 'stage' => $run->stage, 'attempt' => $n, 'error' => $e->getMessage()]);

        return $giveUp ? null : min(900, 30 * 2 ** ($n - 1));
    }

    private function owns(MiningRun $run, ?string $token): bool
    {
        return $token === null || $run->job_token === null || hash_equals((string) $run->job_token, $token);
    }

    private function work(MiningRun $run): string
    {
        $deadline = microtime(true) + max(1, (int) config('agent_mining.queue.step_seconds', 120));
        $slicesLeft = max(1, (int) config('agent_mining.queue.max_slices_per_step', 200));

        do {
            $run->refresh();
            if ($run->status !== MiningRun::STATUS_RUNNING) {
                return self::STOPPED;
            }

            try {
                $outcome = $this->slice($run);
            } catch (BudgetExhausted $e) {
                $run->forceFill([
                    'status' => MiningRun::STATUS_PAUSED,
                    'pause_reason' => MiningRun::PAUSE_BUDGET,
                    'message' => $e->getMessage(),
                    'heartbeat_at' => now(),
                ])->save();

                return self::STOPPED;
            }

            $run->heartbeat_at = now();
            $run->consecutive_errors = 0;
            $run->save();

            if ($outcome !== self::CONTINUE) {
                return $outcome;
            }
        } while (--$slicesLeft > 0 && microtime(true) < $deadline);

        return self::CONTINUE;
    }

    private function slice(MiningRun $run): string
    {
        return match ($run->stage) {
            MiningRun::STAGE_SELECT => $this->selectSlice($run),
            MiningRun::STAGE_EXTRACT => $this->extractSlice($run),
            MiningRun::STAGE_CLUSTER => $this->clusterSlice($run),
            MiningRun::STAGE_SYNTHESISE => $this->synthesiseSlice($run),
            MiningRun::STAGE_VOICE => $this->voiceSlice($run),
            MiningRun::STAGE_EMIT => $this->emitSlice($run),
            default => $this->finish($run),
        };
    }

    private function finish(MiningRun $run): string
    {
        MiningRun::query()->whereKey($run->id)->where('status', MiningRun::STATUS_RUNNING)
            ->update(['status' => MiningRun::STATUS_COMPLETED, 'finished_at' => now(), 'heartbeat_at' => now(), 'message' => null]);

        return self::FINISHED;
    }

    private function selectSlice(MiningRun $run): string
    {
        $selected = $this->selector->select(
            $run->workspace_id,
            $run->since(),
            $run->until(),
            (int) $run->option('max_conversations'),
            (string) $run->option('sample_strategy', 'stratified'),
        );

        DB::transaction(function () use ($run, $selected) {
            MiningConversation::query()->where('run_id', $run->id)->delete();
            $now = now();
            foreach (array_chunk($selected, 500) as $chunk) {
                MiningConversation::query()->insert(array_map(fn ($c) => [
                    'run_id' => $run->id,
                    'conversation_id' => $c['id'],
                    'bucket' => $c['bucket'],
                    'last_at' => $c['last_at'],
                    'status' => MiningConversation::STATUS_PENDING,
                    'attempts' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }
        });

        $buckets = [];
        foreach ($selected as $c) {
            $buckets[$c['bucket']] = ($buckets[$c['bucket']] ?? 0) + 1;
        }
        ksort($buckets);
        $run->setCounters([
            'eligible' => $this->selector->countEligible($run->workspace_id, $run->since(), $run->until()),
            'selected' => count($selected),
            'selected_by_month' => $buckets,
        ]);
        $run->stage = MiningRun::STAGE_EXTRACT;

        return self::CONTINUE;
    }

    private function extractSlice(MiningRun $run): string
    {
        $row = MiningConversation::query()
            ->where('run_id', $run->id)
            ->where('status', MiningConversation::STATUS_PENDING)
            ->orderByDesc('last_at')
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            $run->stage = MiningRun::STAGE_CLUSTER;

            return self::CONTINUE;
        }

        $this->extractor->process($run, $row, $this->catalogue->forWorkspace($run->workspace_id));

        return self::CONTINUE;
    }

    private function clusterSlice(MiningRun $run): string
    {
        $this->clusterer->cluster($run);
        $run->stage = MiningRun::STAGE_SYNTHESISE;

        return self::CONTINUE;
    }

    private function synthesiseSlice(MiningRun $run): string
    {
        $situation = MiningSituation::query()
            ->where('run_id', $run->id)
            ->where('status', MiningSituation::STATUS_PENDING)
            ->orderByDesc('conversation_count')
            ->orderBy('id')
            ->first();

        if ($situation === null) {
            $run->stage = MiningRun::STAGE_VOICE;

            return self::CONTINUE;
        }

        $this->synthesiser->process($run, $situation);

        return self::CONTINUE;
    }

    private function voiceSlice(MiningRun $run): string
    {
        $this->voice->profile($run);
        $run->stage = MiningRun::STAGE_EMIT;

        return self::CONTINUE;
    }

    private function emitSlice(MiningRun $run): string
    {
        if (! $this->emitter->emitNext($run)) {
            $run->stage = MiningRun::STAGE_DONE;
        }

        return self::CONTINUE;
    }
}
