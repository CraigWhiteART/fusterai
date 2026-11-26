<?php

namespace App\Domains\Mailbox\Sync\Backfill;

use App\Domains\Mailbox\Models\Mailbox;
use App\Domains\Mailbox\Sync\Backfill\Jobs\RunMailBackfillJob;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Lifecycle of history imports: start, pause, resume, cancel, undo, and the
 * watchdog that re-queues a chain whose job was lost.
 *
 * Only one unfinished run per mailbox (checked here, enforced by a partial
 * unique index). Every (re)start gives the run a new job_token; queued steps
 * carrying an older token drop themselves, so a resume or the watchdog never
 * leaves two job chains working on one run.
 */
final class BackfillService
{
    public function start(
        Mailbox $mailbox,
        ?int $months = null,
        bool $dryRun = false,
        ?bool $includeUnanswered = null,
        ?User $user = null,
        bool $dispatch = true,
    ): MailBackfillRun {
        $months = max(1, min(120, $months ?? (int) config('mailsync.backfill.default_months', 18)));

        if (MailBackfillRun::query()->where('mailbox_id', $mailbox->id)->whereIn('status', MailBackfillRun::ACTIVE_STATUSES)->exists()) {
            throw BackfillAlreadyRunning::forMailbox($mailbox->id);
        }

        try {
            $run = MailBackfillRun::query()->create([
                'workspace_id' => $mailbox->workspace_id,
                'mailbox_id' => $mailbox->id,
                'user_id' => $user?->id,
                'months' => $months,
                'since' => now()->subMonthsNoOverflow($months)->startOfDay(),
                'options' => [
                    'dry_run' => $dryRun,
                    'include_unanswered' => $includeUnanswered ?? (bool) config('mailsync.backfill.include_unanswered', false),
                    'active_window_days' => max(0, (int) config('mailsync.backfill.active_window_days', 14)),
                ],
                'status' => MailBackfillRun::STATUS_QUEUED,
                'stage' => MailBackfillRun::STAGE_METADATA,
                'counters' => [],
                'job_token' => (string) Str::uuid(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw BackfillAlreadyRunning::forMailbox($mailbox->id);
        }

        if ($dispatch) {
            $this->dispatch($run);
        }

        return $run;
    }

    public function pause(MailBackfillRun $run): bool
    {
        return $this->transition($run, [MailBackfillRun::STATUS_QUEUED, MailBackfillRun::STATUS_RUNNING], MailBackfillRun::STATUS_PAUSED);
    }

    /** Paused or failed runs carry on from their last checkpoint. */
    public function resume(MailBackfillRun $run, bool $dispatch = true): bool
    {
        if (! in_array($run->status, [MailBackfillRun::STATUS_PAUSED, MailBackfillRun::STATUS_FAILED], true)) {
            return false;
        }

        try {
            $ok = MailBackfillRun::query()->whereKey($run->id)
                ->whereIn('status', [MailBackfillRun::STATUS_PAUSED, MailBackfillRun::STATUS_FAILED])
                ->update([
                    'status' => MailBackfillRun::STATUS_RUNNING,
                    'consecutive_errors' => 0,
                    'finished_at' => null,
                    'heartbeat_at' => now(),
                    'job_token' => (string) Str::uuid(),
                ]) === 1;
        } catch (UniqueConstraintViolationException) {
            throw BackfillAlreadyRunning::forMailbox($run->mailbox_id);
        }

        $run->refresh();
        if ($ok && $dispatch) {
            $this->dispatch($run);
        }

        return $ok;
    }

    /** Stops the run for good. What it already imported stays (see undo()). */
    public function cancel(MailBackfillRun $run): bool
    {
        return $this->transition(
            $run,
            [MailBackfillRun::STATUS_QUEUED, MailBackfillRun::STATUS_RUNNING, MailBackfillRun::STATUS_PAUSED, MailBackfillRun::STATUS_FAILED],
            MailBackfillRun::STATUS_CANCELLED,
            ['finished_at' => now()],
        );
    }

    /** Removes everything the run created (BackfillRunner::undoSlice()). */
    public function undo(MailBackfillRun $run, bool $dispatch = true): bool
    {
        if ($run->isDryRun()) {
            return false;
        }

        try {
            $ok = $this->transition(
                $run,
                [MailBackfillRun::STATUS_COMPLETED, MailBackfillRun::STATUS_CANCELLED, MailBackfillRun::STATUS_FAILED],
                MailBackfillRun::STATUS_UNDOING,
                ['finished_at' => null, 'consecutive_errors' => 0, 'heartbeat_at' => now(), 'job_token' => (string) Str::uuid()],
            );
        } catch (UniqueConstraintViolationException) {
            throw BackfillAlreadyRunning::forMailbox($run->mailbox_id);
        }

        if ($ok && $dispatch) {
            $this->dispatch($run);
        }

        return $ok;
    }

    /**
     * Re-queue runs that should be working but have not checkpointed for
     * mailsync.backfill.stall_minutes (job lost, Redis flushed, Horizon down).
     */
    public function recoverStalled(): int
    {
        $cutoff = now()->subMinutes(max(1, (int) config('mailsync.backfill.stall_minutes', 20)));

        $runs = MailBackfillRun::query()
            ->whereIn('status', [MailBackfillRun::STATUS_QUEUED, MailBackfillRun::STATUS_RUNNING, MailBackfillRun::STATUS_UNDOING])
            ->where(fn ($q) => $q->where('heartbeat_at', '<', $cutoff)
                ->orWhere(fn ($q) => $q->whereNull('heartbeat_at')->where('created_at', '<', $cutoff)))
            ->get();

        foreach ($runs as $run) {
            $run->forceFill(['job_token' => (string) Str::uuid(), 'heartbeat_at' => now()])->save();
            $this->dispatch($run);
        }

        return $runs->count();
    }

    public function dispatch(MailBackfillRun $run, int $delaySeconds = 0): void
    {
        $job = new RunMailBackfillJob($run->id, (string) $run->job_token);
        if ($delaySeconds > 0) {
            $job->delay($delaySeconds);
        }
        dispatch($job);
    }

    /** Queue the next step of a chain, unless the chain was superseded meanwhile. */
    public function dispatchNext(int $runId, string $token, int $delaySeconds): void
    {
        $run = MailBackfillRun::query()->find($runId);
        if ($run !== null && $run->job_token === $token) {
            $this->dispatch($run, $delaySeconds);
        }
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $extra
     */
    private function transition(MailBackfillRun $run, array $from, string $to, array $extra = []): bool
    {
        $ok = MailBackfillRun::query()->whereKey($run->id)->whereIn('status', $from)->update(['status' => $to] + $extra) === 1;
        $run->refresh();

        return $ok;
    }
}
