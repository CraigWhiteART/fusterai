<?php

namespace App\Domains\Commerce\Actions;

use App\Domains\Commerce\Actions\Gateway\GatewayResult;
use App\Domains\Commerce\Actions\Gateway\ShopifyGateway;
use App\Domains\Commerce\Actions\Support\Scopes;

/**
 * Reads the order an action targets, fresh, through the gateway (so a dry
 * run never reads either). Fulfilment orders are only selected when the
 * token can read them: selecting them without the scope fails the whole
 * query.
 */
class OrderStateLoader
{
    public const ORDER_FIELDS = <<<'GQL'
      id
      name
      email
      createdAt
      cancelledAt
      currencyCode
      presentmentCurrencyCode
      refundable
      displayFinancialStatus
      displayFulfillmentStatus
      tags
      note
      customer { id }
      shippingAddress { firstName lastName company address1 address2 city province provinceCode zip country countryCodeV2 phone }
      totalPriceSet { shopMoney { amount currencyCode } }
      netPaymentSet { shopMoney { amount currencyCode } }
      totalRefundedSet { shopMoney { amount currencyCode } }
      fulfillments { id status createdAt }
      refunds { id note createdAt totalRefundedSet { shopMoney { amount currencyCode } } }
      transactions { id kind status gateway amountSet { shopMoney { amount currencyCode } } maximumRefundableV2 { amount currencyCode } }
      lineItems(first: 50) {
        nodes {
          id
          title
          variantTitle
          sku
          quantity
          refundableQuantity
          unfulfilledQuantity
          variant { id }
          originalUnitPriceSet { shopMoney { amount currencyCode } }
          discountedUnitPriceAfterAllDiscountsSet { shopMoney { amount currencyCode } }
        }
      }
    GQL;

    public const FULFILLMENT_ORDER_FIELDS = <<<'GQL'
      fulfillmentOrders(first: 10) {
        nodes {
          id
          status
          requestStatus
          assignedLocation { name }
          fulfillmentHolds { id handle reason heldByRequestingApp }
        }
      }
    GQL;

    /** @var array{state: OrderState|null, error: string|null, result: GatewayResult|null} */
    public array $last = ['state' => null, 'error' => null, 'result' => null];

    public function load(ShopifyGateway $gateway, Scopes $scopes, ?string $orderId, ?string $orderNumber): ?OrderState
    {
        $fields = self::ORDER_FIELDS.($scopes->canReadFulfillmentOrders() ? self::FULFILLMENT_ORDER_FIELDS : '');

        if (filled($orderId)) {
            $result = $gateway->query("query ActionOrderState(\$id: ID!) {\n  order(id: \$id) {\n{$fields}\n  }\n}", ['id' => $orderId]);
            $node = $result->ok() && is_array($result->data['order'] ?? null) ? $result->data['order'] : null;
        } elseif (filled($orderNumber)) {
            $number = strtoupper(ltrim(trim((string) $orderNumber), '#'));
            $result = $gateway->query("query ActionOrderByName(\$query: String!) {\n  orders(first: 5, query: \$query) {\n    nodes {\n{$fields}\n    }\n  }\n}", ['query' => 'name:'.$number.' OR name:#'.$number]);
            $node = null;
            foreach ((array) ($result->data['orders']['nodes'] ?? []) as $candidate) {
                if (is_array($candidate) && strtoupper(ltrim((string) ($candidate['name'] ?? ''), '#')) === $number) {
                    $node = $candidate;
                    break;
                }
            }
        } else {
            $this->last = ['state' => null, 'error' => 'No order was named for this action.', 'result' => null];

            return null;
        }

        if (! $result->ok()) {
            $this->last = ['state' => null, 'error' => 'Could not read the order from Shopify: '.$result->errorText(), 'result' => $result];

            return null;
        }
        if ($node === null) {
            $this->last = ['state' => null, 'error' => 'The order was not found in Shopify (orders older than 60 days need the read_all_orders scope).', 'result' => $result];

            return null;
        }

        $state = new OrderState($node, $scopes->canReadFulfillmentOrders());
        $this->last = ['state' => $state, 'error' => null, 'result' => $result];

        return $state;
    }
}
