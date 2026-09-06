<?php

namespace Modules\CommerceAssist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\CommerceAssist\Models\HistoryScan;
use Modules\CommerceAssist\Services\HistoryMiner;
use Throwable;

class MineSentHistoryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly HistoryScan $scan,
    ) {
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return 'commerce-assist-history-scan-'.$this->scan->workspace_id;
    }

    public function handle(HistoryMiner $miner): void
    {
        try {
            $miner->process($this->scan);
        } catch (Throwable $e) {
            $this->scan->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            throw $e;
        }
    }
}
