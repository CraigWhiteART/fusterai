<?php

namespace Modules\CommerceAssist\Providers;

use App\Domains\Conversation\Models\Conversation;
use App\Support\Hooks;
use Illuminate\Support\ServiceProvider;
use Modules\CommerceAssist\Models\Generation;
use Modules\CommerceAssist\Models\ShopifySnapshot;
use Modules\CommerceAssist\Services\EditLearningService;
use Modules\CommerceAssist\Services\GenerationPipeline;

class CommerceAssistServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/commerce-assist.php', 'commerce-assist');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        Hooks::addFilter('ai.generate_reply', function (mixed $handled, Conversation $conversation): mixed {
            if ($handled === true) {
                return true;
            }

            app(GenerationPipeline::class)->run($conversation);

            return true;
        });

        Hooks::addAction('thread.created', function ($thread): void {
            app(EditLearningService::class)->captureSentReply($thread);
        });

        Hooks::addFilter('conversation.show.extra', function (array $extra, Conversation $conversation): array {
            $generation = Generation::where('conversation_id', $conversation->id)->latest()->first();
            $snapshot = ShopifySnapshot::where('conversation_id', $conversation->id)->latest()->first();

            $extra['commerceAssist'] = [
                'shopify' => $snapshot?->payload,
                'generation' => $generation?->toUiArray(),
                'nominate' => ($generation && $generation->nominated_as_example && ! $generation->added_as_example)
                    ? $generation->toUiArray()
                    : null,
            ];

            return $extra;
        });
    }
}
