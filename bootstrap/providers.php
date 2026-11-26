<?php

use App\Domains\Agent\AgentServiceProvider;
use App\Domains\Commerce\Actions\ActionsServiceProvider;
use App\Domains\Commerce\CommerceServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\ModuleServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    ModuleServiceProvider::class,
    CommerceServiceProvider::class,
    AgentServiceProvider::class,
    ActionsServiceProvider::class, // Shopify actions (docs/agent-actions.md)
];
