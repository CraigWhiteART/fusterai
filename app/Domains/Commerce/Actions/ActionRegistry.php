<?php

namespace App\Domains\Commerce\Actions;

use App\Domains\Commerce\Actions\Definitions\ActionDefinition;
use App\Domains\Commerce\Actions\Definitions\AddOrderNote;
use App\Domains\Commerce\Actions\Definitions\CancelOrder;
use App\Domains\Commerce\Actions\Definitions\ChangeAddress;
use App\Domains\Commerce\Actions\Definitions\DiscountCode;
use App\Domains\Commerce\Actions\Definitions\FulfilmentHold;
use App\Domains\Commerce\Actions\Definitions\Refund;
use App\Domains\Commerce\Actions\Definitions\Replacement;
use App\Domains\Commerce\Actions\Definitions\TagOrder;
use App\Domains\Commerce\Actions\Support\Scopes;
use App\Domains\Commerce\Models\CommerceSetting;

/**
 * Every action the agent may propose. Keys match config('agent.actions')
 * (what playbooks list in allowed_actions).
 */
class ActionRegistry
{
    /** @var array<string, ActionDefinition> */
    private array $definitions = [];

    public function __construct()
    {
        foreach ([new AddOrderNote, new TagOrder, new ChangeAddress, new CancelOrder, new Refund, new DiscountCode, new Replacement, new FulfilmentHold] as $definition) {
            $this->definitions[$definition->key()] = $definition;
        }
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    public function get(string $key): ActionDefinition
    {
        return $this->definitions[$key] ?? throw new \InvalidArgumentException("Unknown action [{$key}].");
    }

    /** @return array<string, ActionDefinition> */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * Null when available; otherwise why not (the missing scopes and how to grant them).
     */
    public function unavailableReason(string $key, int $workspaceId): ?string
    {
        if (! $this->has($key)) {
            return 'Unknown action.';
        }
        $definition = $this->get($key);
        if (! $definition->touchesShopify()) {
            return null;
        }

        $settings = CommerceSetting::forWorkspace($workspaceId);
        if (! $settings->hasShopifyCredentials()) {
            return 'Shopify is not connected.';
        }

        $missing = Scopes::for($settings)->missing($definition->requiredScopes());

        return $missing === [] ? null : $definition->missingText($missing);
    }
}
