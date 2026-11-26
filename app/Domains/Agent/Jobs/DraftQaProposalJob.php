<?php

namespace App\Domains\Agent\Jobs;

use App\Domains\Agent\Qa\Models\QaGap;
use App\Domains\Agent\Qa\QaProposalDrafter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** The manager drafts the one proposal of a QA gap group (`ai` queue). */
class DraftQaProposalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 320;

    public function __construct(public readonly int $gapId)
    {
        $this->onQueue((string) config('agent_qa.queue', 'ai'));
    }

    public function handle(QaProposalDrafter $drafter): void
    {
        $gap = QaGap::find($this->gapId);
        if ($gap !== null) {
            $drafter->draft($gap);
        }
    }
}
