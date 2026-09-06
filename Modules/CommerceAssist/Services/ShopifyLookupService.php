<?php

namespace Modules\CommerceAssist\Services;

use App\Domains\Conversation\Models\Conversation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\CommerceAssist\Models\CommerceSetting;
use Modules\CommerceAssist\Models\ShopifySnapshot;
use Modules\CommerceAssist\Support\PlainText;
use Throwable;

class ShopifyLookupService
{
    private const CUSTOMER_QUERY = <<<'GQL'
    query CustomerLookup($query: String!) {
      customers(first: 1, query: $query) {
        edges {
          node {
            id
            firstName
            lastName
            email
            tags
            note
            defaultAddress { country countryCodeV2 }
          }
        }
      }
    }
    GQL;

    private const ORDERS_QUERY = <<<'GQL'
    query OrdersLookup($query: String!) {
      orders(first: 8, query: $query, sortKey: CREATED_AT, reverse: true) {
        edges {
          node {
            id
            name
            createdAt
            displayFinancialStatus
            displayFulfillmentStatus
            cancelledAt
            tags
            note
            shippingAddress { country countryCodeV2 }
            fulfillments { createdAt trackingInfo { number url company } }
            refunds { id createdAt }
            lineItems(first: 50) {
              edges {
                node {
                  title
                  quantity
                  variantTitle
                  sku
                  customAttributes { key value }
                }
              }
            }
          }
        }
      }
    }
    GQL;

    public function lookup(Conversation $conversation): ShopifySnapshot
    {
        $settings = CommerceSetting::forWorkspace($conversation->workspace_id);
        $email = $conversation->customer?->email;
        $haystack = trim(($conversation->subject ?? '')."\n".$this->latestCustomerText($conversation));
        $orderNumbers = $this->extractOrderNumbers($haystack);

        $snapshot = new ShopifySnapshot([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'customer_email' => $email,
        ]);

        if (! $settings->hasShopifyCredentials()) {
            $snapshot->payload = $this->notFound('Shopify is not configured.');
            $snapshot->error = 'not_configured';
            $snapshot->fetched_at = now();
            $snapshot->save();

            return $snapshot;
        }

        try {
            $client = new ShopifyClient($settings);
            $customer = $email ? $this->firstNode($client->graphql(self::CUSTOMER_QUERY, [
                'query' => 'email:'.$email,
            ])['customers']['edges'] ?? []) : null;

            $orders = [];
            foreach ($orderNumbers as $number) {
                $orders = array_merge($orders, $this->orderNodes($client, 'name:'.$number.' OR name:#'.$number));
            }
            if ($email) {
                $orders = array_merge($orders, $this->orderNodes($client, 'email:'.$email));
            }

            $orders = $this->uniqueOrders($orders);
            $order = $this->preferMatchingOrder($orders, $orderNumbers);

            $payload = $this->mapPayload($customer, $order, $settings, $orderNumbers);
            $snapshot->fill([
                'shopify_customer_id' => $payload['shopify_customer_id'] ?? null,
                'shopify_order_id' => $payload['shopify_order_id'] ?? null,
                'order_number' => $payload['order_number'] ?? null,
                'payload' => $payload,
                'fetched_at' => now(),
                'error' => null,
            ]);
        } catch (Throwable $e) {
            Log::warning('Commerce Assist Shopify lookup failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
            $snapshot->payload = $this->notFound('Shopify lookup failed.');
            $snapshot->error = $e->getMessage();
            $snapshot->fetched_at = now();
        }

        $snapshot->save();

        return $snapshot;
    }

    /** @return list<string> */
    public function extractOrderNumbers(string $text): array
    {
        $found = [];

        if (preg_match_all('/#\s*([A-Z]{0,6}\d{3,})/i', $text, $matches)) {
            $found = array_merge($found, $matches[1]);
        }
        if (preg_match_all('/\b([A-Z]{2,6}\d{3,})\b/', $text, $matches)) {
            $found = array_merge($found, $matches[1]);
        }
        if (preg_match_all('/\border(?:\s*(?:number|no\.?|#))?\s*[:#]?\s*([A-Z0-9-]{3,})\b/i', $text, $matches)) {
            $found = array_merge($found, $matches[1]);
        }

        return array_values(array_unique(array_map(
            fn (string $value) => strtoupper(ltrim($value, '#')),
            $found,
        )));
    }

    private function latestCustomerText(Conversation $conversation): string
    {
        $thread = $conversation->threads
            ->first(fn ($thread) => $thread->customer_id !== null)
            ?? $conversation->threads()->whereNotNull('customer_id')->latest()->first();

        return PlainText::from($thread?->body_plain ?: $thread?->body);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function firstNode(mixed $edges): ?array
    {
        if (! is_array($edges) || $edges === []) {
            return null;
        }

        $node = $edges[0]['node'] ?? null;

        return is_array($node) ? $node : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function orderNodes(ShopifyClient $client, string $query): array
    {
        $edges = $client->graphql(self::ORDERS_QUERY, ['query' => $query])['orders']['edges'] ?? [];
        $nodes = [];
        foreach ($edges as $edge) {
            if (is_array($edge['node'] ?? null)) {
                $nodes[] = $edge['node'];
            }
        }

        return $nodes;
    }

    /**
     * @param  list<array<string, mixed>>  $orders
     * @return list<array<string, mixed>>
     */
    private function uniqueOrders(array $orders): array
    {
        $unique = [];
        foreach ($orders as $order) {
            $id = (string) ($order['id'] ?? spl_object_hash((object) $order));
            $unique[$id] = $order;
        }

        return array_values($unique);
    }

    /**
     * @param  list<array<string, mixed>>  $orders
     * @param  list<string>  $orderNumbers
     * @return array<string, mixed>|null
     */
    private function preferMatchingOrder(array $orders, array $orderNumbers): ?array
    {
        $normalized = array_map(fn (string $n) => ltrim($n, '#'), $orderNumbers);

        foreach ($orders as $order) {
            $name = strtoupper(ltrim((string) ($order['name'] ?? ''), '#'));
            foreach ($normalized as $number) {
                if ($name === $number || str_ends_with($name, $number)) {
                    return $order;
                }
            }
        }

        return $orders[0] ?? null;
    }

    /**
     * @param  array<string, mixed>|null  $customer
     * @param  array<string, mixed>|null  $order
     * @param  list<string>  $searchedNumbers
     * @return array<string, mixed>
     */
    private function mapPayload(?array $customer, ?array $order, CommerceSetting $settings, array $searchedNumbers): array
    {
        if (! $customer && ! $order) {
            $reason = $searchedNumbers
                ? 'No Shopify customer or order matched this email/order number.'
                : 'No Shopify customer matched this email.';

            return $this->notFound($reason);
        }

        $name = trim(($customer['firstName'] ?? '').' '.($customer['lastName'] ?? ''));
        $lineItems = [];
        $hasPreorder = false;
        $hasCurrent = false;
        $preorderTags = $settings->preorderTagList();

        foreach ($order['lineItems']['edges'] ?? [] as $edge) {
            $item = $edge['node'] ?? [];
            $preorder = $this->itemIsPreorder($item, $order['tags'] ?? [], $preorderTags);
            if ($preorder === true) {
                $hasPreorder = true;
            } elseif ($preorder === false) {
                $hasCurrent = true;
            }
            $lineItems[] = [
                'title' => $item['title'] ?? null,
                'variant' => $item['variantTitle'] ?? null,
                'quantity' => $item['quantity'] ?? null,
                'sku' => $item['sku'] ?? null,
                'preorder' => $preorder,
            ];
        }

        $tracking = $this->firstTracking($order['fulfillments'] ?? []);
        $createdAt = isset($order['createdAt']) ? Carbon::parse($order['createdAt']) : null;
        $cancelled = filled($order['cancelledAt'] ?? null);
        $refunded = ! empty($order['refunds']);

        return [
            'found' => true,
            'customer_name' => $name !== '' ? $name : null,
            'customer_email' => $customer['email'] ?? null,
            'shopify_customer_id' => $customer['id'] ?? null,
            'customer_tags' => $this->asList($customer['tags'] ?? []),
            'customer_note' => $customer['note'] ?? null,
            'shopify_order_id' => $order['id'] ?? null,
            'order_number' => $order['name'] ?? null,
            'order_date' => $createdAt?->toDateString(),
            'order_age_days' => $createdAt ? $createdAt->diffInDays(now()) : null,
            'products' => $lineItems,
            'payment_status' => $order['displayFinancialStatus'] ?? null,
            'fulfilment_status' => $order['displayFulfillmentStatus'] ?? null,
            'tracking_number' => $tracking['number'] ?? null,
            'tracking_url' => $tracking['url'] ?? null,
            'tracking_company' => $tracking['company'] ?? null,
            'tracking_age_days' => $tracking['age_days'] ?? null,
            'shipping_country' => $order['shippingAddress']['country']
                ?? $customer['defaultAddress']['country']
                ?? null,
            'cancelled' => $cancelled,
            'refunded' => $refunded,
            'order_tags' => $this->asList($order['tags'] ?? []),
            'notes' => $order['note'] ?? null,
            'has_preorder_items' => $hasPreorder ?: null,
            'has_current_stock_items' => $hasCurrent ?: null,
            'preorder_determined' => $hasPreorder || $hasCurrent,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  mixed  $orderTags
     * @param  list<string>  $preorderTags
     */
    private function itemIsPreorder(array $item, mixed $orderTags, array $preorderTags): ?bool
    {
        $haystack = [];
        foreach ($item['customAttributes'] ?? [] as $attr) {
            $haystack[] = strtolower((string) ($attr['key'] ?? '').' '.(string) ($attr['value'] ?? ''));
        }
        $haystack[] = strtolower((string) ($item['sku'] ?? ''));
        foreach ($this->asList($orderTags) as $tag) {
            $haystack[] = strtolower($tag);
        }

        $blob = implode(' ', $haystack);
        foreach ($preorderTags as $tag) {
            if ($tag !== '' && str_contains($blob, $tag)) {
                return true;
            }
        }

        if (str_contains($blob, 'preorder') || str_contains($blob, 'pre-order')) {
            return true;
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $fulfillments
     * @return array{number: ?string, url: ?string, company: ?string, age_days: ?int}
     */
    private function firstTracking(array $fulfillments): array
    {
        foreach ($fulfillments as $fulfillment) {
            foreach ($fulfillment['trackingInfo'] ?? [] as $info) {
                if (! empty($info['number']) || ! empty($info['url'])) {
                    $created = isset($fulfillment['createdAt']) ? Carbon::parse($fulfillment['createdAt']) : null;

                    return [
                        'number' => $info['number'] ?? null,
                        'url' => $info['url'] ?? null,
                        'company' => $info['company'] ?? null,
                        'age_days' => $created ? $created->diffInDays(now()) : null,
                    ];
                }
            }
        }

        return ['number' => null, 'url' => null, 'company' => null, 'age_days' => null];
    }

    /**
     * @return list<string>
     */
    private function asList(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $value))));
        }
        if (is_array($value)) {
            return array_values(array_filter(array_map(fn ($item) => trim((string) $item), $value)));
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function notFound(string $reason): array
    {
        return [
            'found' => false,
            'reason' => $reason,
        ];
    }
}
