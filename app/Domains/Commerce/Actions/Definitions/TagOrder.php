<?php

namespace App\Domains\Commerce\Actions\Definitions;

use App\Domains\Commerce\Actions\Gateway\ShopifyGateway;
use App\Domains\Commerce\Actions\Models\ActionSettings;
use App\Domains\Commerce\Actions\OrderState;
use App\Domains\Commerce\Actions\OrderStateLoader;

/**
 * Adds and/or removes order tags (tagsAdd / tagsRemove on an Order id).
 * Scope: write_orders. Naturally idempotent.
 */
class TagOrder extends ActionDefinition
{
    public const ADD = <<<'GQL'
    mutation ActionTagsAdd($id: ID!, $tags: [String!]!) {
      tagsAdd(id: $id, tags: $tags) {
        node { id }
        userErrors { field message }
      }
    }
    GQL;

    public const REMOVE = <<<'GQL'
    mutation ActionTagsRemove($id: ID!, $tags: [String!]!) {
      tagsRemove(id: $id, tags: $tags) {
        node { id }
        userErrors { field message }
      }
    }
    GQL;

    public function key(): string
    {
        return 'tag_order';
    }

    public function label(): string
    {
        return 'Tag the order';
    }

    public function summary(): string
    {
        return 'Adds or removes Shopify order tags. The customer never sees them.';
    }

    public function requiredScopes(): array
    {
        return [['write_orders']];
    }

    public function paramsHint(): string
    {
        return '{"add": ["tag"], "remove": ["tag"]}';
    }

    public function rules(ActionSettings $settings): array
    {
        $tag = ['string', 'min:1', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9 _:\-]*$/'];

        return [
            'add' => ['nullable', 'array', 'max:5'],
            'add.*' => $tag,
            'remove' => ['nullable', 'array', 'max:5'],
            'remove.*' => $tag,
        ];
    }

    protected function normalize(array $params, ActionSettings $settings): array
    {
        $clean = fn ($list) => array_values(array_unique(array_map(fn ($t) => trim((string) $t), (array) ($list ?? []))));
        $add = $clean($params['add'] ?? []);
        sort($add);
        $remove = array_values(array_diff($clean($params['remove'] ?? []), $add));
        sort($remove);

        return ['add' => $add, 'remove' => $remove];
    }

    protected function extraErrors(array $params, ActionSettings $settings): array
    {
        return $params['add'] === [] && $params['remove'] === [] ? ['add' => ['Name at least one tag to add or remove.']] : [];
    }

    public function describe(array $params, ?OrderState $state, ActionSettings $settings): string
    {
        $parts = [];
        if (($params['add'] ?? []) !== []) {
            $parts[] = 'add tag(s) '.implode(', ', $params['add']);
        }
        if (($params['remove'] ?? []) !== []) {
            $parts[] = 'remove tag(s) '.implode(', ', $params['remove']);
        }

        return ucfirst(implode(' and ', $parts)).' on '.($state?->name() ?: 'the order').'. Internal only; the customer will not see it.';
    }

    public function preconditions(array $params, OrderState $state, ActionContext $ctx): Checks
    {
        return (new Checks)->check('order_found', $state->id() !== '', "Order {$state->name()} found.");
    }

    public function execute(ShopifyGateway $gateway, array $params, OrderState $state, Checks $checks, ActionContext $ctx): Outcome
    {
        $outcome = null;
        if ($params['add'] !== []) {
            $outcome = Outcome::fromResult($gateway->mutate(self::ADD, ['id' => $state->id(), 'tags' => $params['add']]), 'tagsAdd');
            if ($outcome->status !== Outcome::SUCCEEDED) {
                return $outcome;
            }
        }
        if ($params['remove'] !== []) {
            $removed = Outcome::fromResult($gateway->mutate(self::REMOVE, ['id' => $state->id(), 'tags' => $params['remove']]), 'tagsRemove');
            $outcome = $outcome === null ? $removed : $outcome->then($removed);
        }

        return $outcome ?? new Outcome(Outcome::FAILED, 'Nothing to change.');
    }

    public function verify(ShopifyGateway $gateway, array $params, ActionContext $ctx, OrderStateLoader $loader): Outcome
    {
        $state = $loader->load($gateway, $ctx->scopes, $ctx->execution->order_id, $ctx->execution->order_number);
        if ($state === null) {
            return new Outcome(Outcome::UNKNOWN, $loader->last['error']);
        }

        $tags = array_map('mb_strtolower', $state->tags());
        $missing = array_diff(array_map('mb_strtolower', $params['add'] ?? []), $tags);
        $still = array_intersect(array_map('mb_strtolower', $params['remove'] ?? []), $tags);

        return $missing === [] && $still === []
            ? new Outcome(Outcome::SUCCEEDED, data: ['tags' => $state->tags()])
            : new Outcome(Outcome::FAILED, 'Tags on the order do not match: missing '.implode(', ', $missing).'; still present '.implode(', ', $still).'.');
    }

    public function undo(array $params, ?OrderState $state, Outcome $outcome): ?array
    {
        return ['how' => 'Reverse the tag change in Shopify.', 'add' => $params['remove'] ?? [], 'remove' => $params['add'] ?? []];
    }
}
