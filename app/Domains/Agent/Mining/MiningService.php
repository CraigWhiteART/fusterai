<?php

namespace App\Domains\Agent\Mining;

use App\Domains\Agent\Mining\Jobs\RunMiningStepJob;
use App\Domains\Agent\Mining\Models\MiningRun;
use App\Domains\Agent\Services\AgentBootstrapper;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Lifecycle of mining runs: estimate, start, pause, resume (optionally with a
 * new budget cap), cancel, and the watchdog that re-queues a lost chain.
 * One unfinished run per workspace (checked here, enforced by a partial
 * unique index). Every (re)start gets a new job_token; queued steps carrying
 * an older one drop themselves.
 */
final class MiningService
{
    public function __construct(
        private readonly MiningEstimator $estimator,
    ) {}

    /**
     * Options with defaults and limits applied.
     *
     * @param  array<string, mixed>  $input  months?, since?, until?, max_conversations?, budget_usd?, sample_strategy?
     * @return array{since: string, until: string, max_conversations: int, budget_usd: float, sample_strategy: string}
     */
    public function options(array $input): array
    {
        $until = filled($input['until'] ?? null) ? Carbon::parse((string) $input['until'])->endOfDay() : now();
        $months = max(1, min(120, (int) ($input['months'] ?? config('agent_mining.defaults.months', 18))));
        $since = filled($input['since'] ?? null) ? Carbon::parse((string) $input['since'])->startOfDay() : $until->copy()->subMonthsNoOverflow($months)->startOfDay();
        if ($since->gt($until)) {
            [$since, $until] = [$until->copy()->startOfDay(), $since->copy()->endOfDay()];
        }

        $strategy = (string) ($input['sample_strategy'] ?? config('agent_mining.defaults.sample_strategy', 'stratified'));

        return [
            'since' => $since->toIso8601String(),
            'until' => $until->toIso8601String(),
            'max_conversations' => max(1, min((int) config('agent_mining.limits.max_conversations', 20000), (int) ($input['max_conversations'] ?? config('agent_mining.defaults.max_conversations', 3000)))),
            'budget_usd' => round(max(0.01, min((float) config('agent_mining.limits.budget_usd', 500), (float) ($input['budget_usd'] ?? config('agent_mining.defaults.budget_usd', 25)))), 2),
            'sample_strategy' => in_array($strategy, MiningSelector::STRATEGIES, true) ? $strategy : 'stratified',
        ];
    }

    /** @param  array<string, mixed>  $input */
    public function estimate(int $workspaceId, array $input): array
    {
        return $this->estimator->estimate($workspaceId, $this->options($input));
    }

    /** @param  array<string, mixed>  $input */
    public function start(int $workspaceId, array $input, ?User $user = null, bool $dispatch = true): MiningRun
    {
        if (MiningRun::query()->where('workspace_id', $workspaceId)->whereIn('status', MiningRun::ACTIVE_STATUSES)->exists()) {
            throw MiningAlreadyRunning::forWorkspace($workspaceId);
        }

        // Voice and playbook catalogue must exist: they are what mining proposes changes to.
        app(AgentBootstrapper::class)->ensureForWorkspace($workspaceId);

        $options = $this->options($input);
        $estimate = $this->estimator->estimate($workspaceId, $options);

        try {
            $run = MiningRun::query()->create([
                'workspace_id' => $workspaceId,
                'user_id' => $user?->id,
                'options' => $options,
                'status' => MiningRun::STATUS_QUEUED,
                'stage' => MiningRun::STAGE_SELECT,
                'counters' => [],
                'usage' => [],
                'results' => ['estimate' => $estimate],
                'budget_micro_usd' => (int) round($options['budget_usd'] * 1_000_000),
                'job_token' => (string) Str::uuid(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw MiningAlreadyRunning::forWorkspace($workspaceId);
        }

        if ($dispatch) {
            $this->dispatch($run);
        }

        return $run;
    }

    public function pause(MiningRun $run): bool
    {
        return $this->transition($run, [MiningRun::STATUS_QUEUED, MiningRun::STATUS_RUNNING], MiningRun::STATUS_PAUSED, [
            'pause_reason' => MiningRun::PAUSE_USER,
            'message' => 'Paused by a user.',
        ]);
    }

    /** Paused or failed runs carry on from their checkpoint. A new budget cap may be given (USD). */
    public function resume(MiningRun $run, ?float $budgetUsd = null, bool $dispatch = true): bool
    {
        if (! in_array($run->status, [MiningRun::STATUS_PAUSED, MiningRun::STATUS_FAILED], true)) {
            return false;
        }

        $extra = [];
        if ($budgetUsd !== null) {
            $budget = round(max(0.01, min((float) config('agent_mining.limits.budget_usd', 500), $budgetUsd)), 2);
            $extra['budget_micro_usd'] = (int) round($budget * 1_000_000);
            $extra['options'] = json_encode(['budget_usd' => $budget] + (array) $run->options);
        }

        try {
            $ok = MiningRun::query()->whereKey($run->id)
                ->whereIn('status', [MiningRun::STATUS_PAUSED, MiningRun::STATUS_FAILED])
                ->update([
                    'status' => MiningRun::STATUS_RUNNING,
                    'pause_reason' => null,
                    'message' => null,
                    'consecutive_errors' => 0,
                    'finished_at' => null,
                    'heartbeat_at' => now(),
                    'job_token' => (string) Str::uuid(),
                ] + $extra) === 1;
        } catch (UniqueConstraintViolationException) {
            throw MiningAlreadyRunning::forWorkspace($run->workspace_id);
        }

        $run->refresh();
        if ($ok && $dispatch) {
            $this->dispatch($run);
        }

        return $ok;
    }

    public function cancel(MiningRun $run): bool
    {
        return $this->transition(
            $run,
            [MiningRun::STATUS_QUEUED, MiningRun::STATUS_RUNNING, MiningRun::STATUS_PAUSED, MiningRun::STATUS_FAILED],
            MiningRun::STATUS_CANCELLED,
            ['finished_at' => now()],
        );
    }

    /** Re-queue runs that should be working but have not checkpointed for queue.stall_minutes. */
    public function recoverStalled(): int
    {
        $cutoff = now()->subMinutes(max(1, (int) config('agent_mining.queue.stall_minutes', 20)));

        $runs = MiningRun::query()
            ->whereIn('status', [MiningRun::STATUS_QUEUED, MiningRun::STATUS_RUNNING])
            ->where(fn ($q) => $q->where('heartbeat_at', '<', $cutoff)
                ->orWhere(fn ($q) => $q->whereNull('heartbeat_at')->where('created_at', '<', $cutoff)))
            ->get();

        foreach ($runs as $run) {
            $run->forceFill(['job_token' => (string) Str::uuid(), 'heartbeat_at' => now()])->save();
            $this->dispatch($run);
        }

        return $runs->count();
    }

    public function dispatch(MiningRun $run, int $delaySeconds = 0): void
    {
        $job = new RunMiningStepJob($run->id, (string) $run->job_token);
        if ($delaySeconds > 0) {
            $job->delay($delaySeconds);
        }
        dispatch($job);
    }

    public function dispatchNext(int $runId, string $token, int $delaySeconds): void
    {
        $run = MiningRun::query()->find($runId);
        if ($run !== null && $run->job_token === $token) {
            $this->dispatch($run, $delaySeconds);
        }
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $extra
     */
    private function transition(MiningRun $run, array $from, string $to, array $extra = []): bool
    {
        $ok = MiningRun::query()->whereKey($run->id)->whereIn('status', $from)->update(['status' => $to] + $extra) === 1;
        $run->refresh();

        return $ok;
    }
}
