<?php

namespace App\Domains\Commerce\Actions\Definitions;

use App\Domains\Commerce\Actions\Gateway\ShopifyGateway;
use App\Domains\Commerce\Actions\Models\ActionSettings;
use App\Domains\Commerce\Actions\OrderState;
use App\Domains\Commerce\Actions\OrderStateLoader;

/**
 * Appends a line to the order's staff note (orderUpdate.note replaces the
 * whole note, so the current note is read fresh and the line appended).
 * Scope: write_orders.
 */
class AddOrderNote extends ActionDefinition
{
    public const MUTATION = <<<'GQL'
    mutation ActionOrderNote($input: OrderInput!) {
      orderUpdate(input: $input) {
        order { id note }
        userErrors { field message }
      }
    }
    GQL;

    public function key(): string
    {
        return 'add_order_note';
    }

    public function label(): string
    {
        return 'Add a note to the order';
    }

    public function summary(): string
    {
        return 'Appends a staff-only line to the Shopify order note. The customer never sees it.';
    }

    public function requiredScopes(): array
    {
        return [['write_orders']];
    }

    public function paramsHint(): string
    {
        return '{"note": "text for staff, max 500 chars"}';
    }

    public function rules(ActionSettings $settings): array
    {
        return ['note' => ['required', 'string', 'min:2', 'max:500']];
    }

    protected function normalize(array $params, ActionSettings $settings): array
    {
        return ['note' => trim(preg_replace('/\s+/', ' ', (string) $params['note']) ?? '')];
    }

    public function describe(array $params, ?OrderState $state, ActionSettings $settings): string
    {
        $order = $state?->name() ?: 'the order';

        return "Add this staff note to {$order}: \"".($params['note'] ?? '').'". The customer will not see it.';
    }

    public function preconditions(array $params, OrderState $state, ActionContext $ctx): Checks
    {
        return (new Checks)->check('order_found', $state->id() !== '', "Order {$state->name()} found.");
    }

    public function execute(ShopifyGateway $gateway, array $params, OrderState $state, Checks $checks, ActionContext $ctx): Outcome
    {
        $note = $this->auditedNote($state->note(), $ctx->auditLine((string) $params['note']));
        $result = $gateway->mutate(self::MUTATION, ['input' => ['id' => $state->id(), 'note' => $note]]);

        return Outcome::fromResult($result, 'orderUpdate', data: ['previous_note' => $state->note()]);
    }

    public function verify(ShopifyGateway $gateway, array $params, ActionContext $ctx, OrderStateLoader $loader): Outcome
    {
        $state = $loader->load($gateway, $ctx->scopes, $ctx->execution->order_id, $ctx->execution->order_number);
        if ($state === null) {
            return new Outcome(Outcome::UNKNOWN, $loader->last['error']);
        }

        return $ctx->execution->hasMarker($state->note())
            ? new Outcome(Outcome::SUCCEEDED, data: ['note_contains_marker' => true])
            : new Outcome(Outcome::FAILED, 'The note line is not on the order.');
    }

    public function undo(array $params, ?OrderState $state, Outcome $outcome): ?array
    {
        return ['how' => 'Edit the order note in Shopify and remove the line ending in the fusterai marker.', 'previous_note' => $outcome->data['previous_note'] ?? null];
    }
}
