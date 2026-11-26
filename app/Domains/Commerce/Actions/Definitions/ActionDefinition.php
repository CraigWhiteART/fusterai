<?php

namespace App\Domains\Commerce\Actions\Definitions;

use App\Domains\Commerce\Actions\Gateway\ShopifyGateway;
use App\Domains\Commerce\Actions\Models\ActionSettings;
use App\Domains\Commerce\Actions\OrderState;
use App\Domains\Commerce\Actions\OrderStateLoader;
use App\Domains\Commerce\Actions\Support\Scopes;
use Illuminate\Support\Facades\Validator;

/**
 * One action the agent may propose. Subclasses declare what Shopify needs
 * (scopes, mutation), what must be true first (preconditions against fresh
 * order state), how to explain it to the approver, and how to confirm it
 * happened (verify, also used by reconcile after an unknown outcome).
 */
abstract class ActionDefinition
{
    public const RISK_LOW = 'low';

    public const RISK_MEDIUM = 'medium';

    public const RISK_HIGH = 'high';

    abstract public function key(): string;

    abstract public function label(): string;

    /** One sentence for the catalogue. */
    abstract public function summary(): string;

    /**
     * Scope groups; every group must be met, any scope within a group will do.
     *
     * @return list<list<string>>
     */
    abstract public function requiredScopes(): array;

    /** Laravel validation rules for params. @return array<string, mixed> */
    abstract public function rules(ActionSettings $settings): array;

    abstract public function describe(array $params, ?OrderState $state, ActionSettings $settings): string;

    abstract public function preconditions(array $params, OrderState $state, ActionContext $ctx): Checks;

    abstract public function execute(ShopifyGateway $gateway, array $params, OrderState $state, Checks $checks, ActionContext $ctx): Outcome;

    abstract public function verify(ShopifyGateway $gateway, array $params, ActionContext $ctx, OrderStateLoader $loader): Outcome;

    /**
     * Extra read-only queries an action needs before its preconditions
     * (stored in $ctx->extra). Default: none.
     */
    public function prepare(ShopifyGateway $gateway, array $params, OrderState $state, ActionContext $ctx): void {}

    /** Moves money or changes where goods go: higher approver role, sender match mandatory. */
    public function isMoney(): bool
    {
        return false;
    }

    public function reversible(): bool
    {
        return true;
    }

    public function riskTier(): string
    {
        return self::RISK_LOW;
    }

    /** The customer reply must not be sent before this succeeds. */
    public function replyWaits(): bool
    {
        return false;
    }

    /** False = approval-only, whatever the policy says. */
    public function unattendedAllowed(): bool
    {
        return true;
    }

    public function touchesShopify(): bool
    {
        return true;
    }

    /** Subject to the 4PX warehouse guard. */
    public function warehouseSensitive(): bool
    {
        return false;
    }

    /** Free-text hint for the writer about the params shape. */
    public function paramsHint(): string
    {
        return '{}';
    }

    /**
     * Validate and canonicalise params.
     *
     * @param  array<string, mixed>  $params
     * @return array{params: array<string, mixed>, errors: array<string, list<string>>}
     */
    public function validate(array $params, ActionSettings $settings): array
    {
        $validator = Validator::make($params, $this->rules($settings));
        if ($validator->fails()) {
            /** @var array<string, list<string>> $errors */
            $errors = $validator->errors()->toArray();

            return ['params' => $params, 'errors' => $errors];
        }

        $clean = $this->normalize($validator->validated(), $settings);
        $extra = $this->extraErrors($clean, $settings);

        return ['params' => $clean, 'errors' => $extra];
    }

    /** @param  array<string, mixed>  $params  @return array<string, mixed> */
    protected function normalize(array $params, ActionSettings $settings): array
    {
        ksort($params);

        return $params;
    }

    /** @param  array<string, mixed>  $params  @return array<string, list<string>> */
    protected function extraErrors(array $params, ActionSettings $settings): array
    {
        return [];
    }

    /** Amount (cents, shop currency) for limits, from params alone. */
    public function amountFromParams(array $params): ?int
    {
        return null;
    }

    public function percentage(array $params): ?float
    {
        return null;
    }

    /** @return array<string, mixed>|null how a human would undo it */
    public function undo(array $params, ?OrderState $state, Outcome $outcome): ?array
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function catalogueEntry(Scopes $scopes): array
    {
        $missing = $this->touchesShopify() ? $scopes->missing($this->requiredScopes()) : [];

        return [
            'key' => $this->key(),
            'label' => $this->label(),
            'summary' => $this->summary(),
            'required_scopes' => $this->requiredScopes(),
            'missing_scopes' => $missing,
            'available' => $missing === [],
            'unavailable_reason' => $missing === [] ? null : $this->missingText($missing),
            'money' => $this->isMoney(),
            'reversible' => $this->reversible(),
            'risk' => $this->riskTier(),
            'reply_waits' => $this->replyWaits(),
            'unattended_allowed' => $this->unattendedAllowed(),
            'warehouse_sensitive' => $this->warehouseSensitive(),
            'touches_shopify' => $this->touchesShopify(),
            'params_hint' => $this->paramsHint(),
        ];
    }

    /** @param  list<list<string>>  $missing */
    public function missingText(array $missing): string
    {
        $groups = array_map(fn (array $g) => implode(' or ', $g), $missing);

        return 'Missing Shopify access scope: '.implode(', ', $groups).'. Add it to the app in the Shopify Dev Dashboard (Versions > new version > Access scopes), release the version, approve the update on the store, then press "Test connection" so fusterai records the new scopes.';
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    protected function orderPresent(Checks $checks, OrderState $state): void
    {
        $checks->check('order_open', ! $state->cancelled(), $state->cancelled() ? "Order {$state->name()} is cancelled." : "Order {$state->name()} is not cancelled.");
    }

    protected function auditedNote(string $existing, string $line): string
    {
        $existing = rtrim($existing);

        return $existing === '' ? $line : $existing."\n".$line;
    }
}
