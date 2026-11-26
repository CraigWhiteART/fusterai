<?php

namespace App\Domains\Commerce;

use App\Domains\Commerce\Console\RefreshShopifyTokensCommand;
use App\Domains\Commerce\Services\Tracking\TrackingProviderFactory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Shopify and carrier tracking. Always registered (not a toggleable module):
 * the agent pipeline and the legacy Commerce Assist screens both read it.
 */
class CommerceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton so a provider registered with extend() (a test fake, or a
        // future first-party carrier client) is seen by every resolver.
        $this->app->singleton(TrackingProviderFactory::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RefreshShopifyTokensCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('commerce:refresh-shopify-tokens')
                ->hourly()
                ->withoutOverlapping();
        });
    }
}
