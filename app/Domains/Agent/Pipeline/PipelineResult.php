<?php

namespace App\Domains\Agent\Pipeline;

use App\Domains\Agent\Models\AgentRun;

final class PipelineResult
{
    private function __construct(
        public readonly ?AgentRun $run,
        public readonly ?string $skipped,
        public readonly bool $reused,
    ) {}

    public static function ran(AgentRun $run): self
    {
        return new self($run, null, false);
    }

    /** The idempotency key already has a run: nothing new was done. */
    public static function reused(AgentRun $run): self
    {
        return new self($run, null, true);
    }

    public static function skipped(string $reason, ?AgentRun $run = null): self
    {
        return new self($run, $reason, false);
    }

    public function wasSkipped(): bool
    {
        return $this->skipped !== null;
    }
}
