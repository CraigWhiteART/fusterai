<?php

namespace Modules\CommerceAssist\Providers;

use App\Domains\Conversation\Models\Conversation;
use App\Support\Hooks;
use Illuminate\Support\ServiceProvider;
use Modules\CommerceAssist\Models\Generation;
use Modules\CommerceAssist\Models\ShopifySnapshot;
use Modules\CommerceAssist\Services\EditLearningService;
use Modules\CommerceAssist\Services\GenerationPipeline;
use Modules\CommerceAssist\Services\LiveFactService;
use Modules\CommerceAssist\Services\Tracking\TrackingProviderFactory;
use Modules\CommerceAssist\Services\TrackingLookupService;

class CommerceAssistServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/commerce-assist.php', 'commerce-assist');

        // Singleton so a provider registered with extend() — a test fake, or a
        // future first-party carrier client — is seen by every resolver.
        $this->app->singleton(TrackingProviderFactory::class);
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
            $facts = app(LiveFactService::class);
            $applied = $facts->forConversation($conversation, $generation?->intent, $generation?->subtype, $snapshot);

            $extra['commerceAssist'] = [
                'shopify' => $snapshot?->payload,
                'tracking' => app(TrackingLookupService::class)->latestFor($conversation)?->toUiArray(),
                'generation' => $generation?->toUiArray(),
                'nominate' => ($generation && $generation->nominated_as_example && ! $generation->added_as_example)
                    ? $generation->toUiArray()
                    : null,
                'liveFacts' => $applied->map->toUiArray()->values()->all(),
                'composer' => $facts->inferFromConversation($conversation),
            ];

            return $extra;
        });
    }
}
