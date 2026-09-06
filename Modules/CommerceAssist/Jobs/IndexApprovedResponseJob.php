<?php

namespace Modules\CommerceAssist\Jobs;

use App\Services\AiSettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\Lab;
use Modules\CommerceAssist\Models\ApprovedResponse;

class IndexApprovedResponseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly ApprovedResponse $example,
    ) {
        $this->onQueue('ai');
    }

    public function handle(): void
    {
        $text = $this->example->customer_message."\n\n".$this->example->final_reply;
        $text = mb_substr($text, 0, 8000);

        app(AiSettingsService::class)->withWorkspaceCredentials(
            $this->example->workspace_id,
            function (Lab $lab) use ($text): void {
                $embeddingsLab = ($lab === Lab::Anthropic) ? Lab::OpenAI : $lab;
                $response = Embeddings::for([$text])->generate($embeddingsLab);
                $this->example->embedding = $response->first();
                $this->example->indexed_at = now();
            }
        );

        $this->example->save();
    }
}
