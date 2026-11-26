<?php

namespace App\Domains\Commerce\Actions;

use App\Domains\Commerce\Actions\Console\ExpireStaleActionsCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Shopify actions with approval and unattended actions (Phases 9-10).
 * Routes in routes/agent_actions.php (web group). See docs/agent-actions.md.
 */
class ActionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ActionRegistry::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExpireStaleActionsCommand::class]);
        }

        Route::middleware('web')->group(base_path('routes/agent_actions.php'));

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('agent:actions-expire')->everyFifteenMinutes()->withoutOverlapping();
        });
    }
}
