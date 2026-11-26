<?php

use App\Domains\Commerce\Models\CommerceSetting;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Customer\Models\Customer;
use App\Domains\Mailbox\Models\Mailbox;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Crypt;

/**
 * Shared fixtures for tests/Feature/Commerce. Functions are prefixed with
 * `commerceT` so they never collide with helpers in other Pest files.
 *
 * @return array{workspace: Workspace, admin: User, agent: User, mailbox: Mailbox, customer: Customer, conversation: Conversation}
 */
function commerceTCase(string $message = 'Hi, where is order SD8421? Tracking has not updated.'): array
{
    $workspace = Workspace::factory()->create();
    $mailbox = Mailbox::factory()->create(['workspace_id' => $workspace->id]);
    $customer = Customer::factory()->create([
        'workspace_id' => $workspace->id,
        'email' => 'buyer@example.com',
        'name' => 'Alex Buyer',
    ]);
    $conversation = Conversation::factory()->create([
        'workspace_id' => $workspace->id,
        'mailbox_id' => $mailbox->id,
        'customer_id' => $customer->id,
        'subject' => 'Where is my order?',
    ]);
    Thread::factory()->create([
        'conversation_id' => $conversation->id,
        'customer_id' => $customer->id,
        'user_id' => null,
        'body' => '<p>'.e($message).'</p>',
        'body_plain' => $message,
    ]);

    return [
        'workspace' => $workspace,
        'admin' => User::factory()->create(['workspace_id' => $workspace->id, 'role' => 'admin']),
        'agent' => User::factory()->create(['workspace_id' => $workspace->id, 'role' => 'agent']),
        'mailbox' => $mailbox,
        'customer' => $customer,
        'conversation' => $conversation,
    ];
}

function commerceTEnableShopify(int $workspaceId, array $overrides = []): CommerceSetting
{
    $settings = CommerceSetting::forWorkspace($workspaceId);
    $settings->update(array_merge([
        'shopify_shop_domain' => 'test-shop.myshopify.com',
        'shopify_client_id' => '11111111111111111111111111111111',
        'shopify_client_secret' => Crypt::encryptString('shpss_secret'),
        'shopify_access_token' => Crypt::encryptString('shpca_test'),
        'shopify_access_token_expires_at' => now()->addHours(23),
        'shopify_granted_scopes' => 'read_orders,read_customers,read_products',
    ], $overrides));

    return $settings->fresh();
}

function commerceTEnableTrack123(int $workspaceId): CommerceSetting
{
    return commerceTEnableShopify($workspaceId, [
        'tracking_provider' => 'track123',
        'tracking_api_key' => Crypt::encryptString('t123_secret'),
    ]);
}

/** A Shopify GraphQL response with one customer and one order (SD8421, buyer@example.com). */
function commerceTShopifyPayload(string $orderEmail = 'buyer@example.com', array $order = []): array
{
    return [
        'data' => [
            'customers' => ['edges' => [[
                'node' => [
                    'id' => 'gid://shopify/Customer/1',
                    'firstName' => 'Alex',
                    'lastName' => 'Buyer',
                    'email' => 'buyer@example.com',
                    'tags' => [],
                    'note' => null,
                    'defaultAddress' => ['country' => 'Australia', 'countryCodeV2' => 'AU'],
                ],
            ]]],
            'orders' => ['edges' => [[
                'node' => array_merge([
                    'id' => 'gid://shopify/Order/99',
                    'name' => 'SD8421',
                    'email' => $orderEmail,
                    'createdAt' => now()->subDays(3)->toIso8601String(),
                    'displayFinancialStatus' => 'PAID',
                    'displayFulfillmentStatus' => 'FULFILLED',
                    'cancelledAt' => null,
                    'tags' => [],
                    'note' => null,
                    'shippingAddress' => ['country' => 'Australia', 'countryCodeV2' => 'AU'],
                    'fulfillments' => [[
                        'createdAt' => now()->subDays(2)->toIso8601String(),
                        'trackingInfo' => [['number' => '4PX00112233', 'url' => 'https://track.example.test/4PX00112233', 'company' => '4PX']],
                    ]],
                    'refunds' => [],
                    'lineItems' => ['edges' => [[
                        'node' => ['title' => 'Arcade Cabinet', 'quantity' => 1, 'variantTitle' => 'Black', 'sku' => 'CAB-1', 'customAttributes' => []],
                    ]]],
                ], $order),
            ]]],
        ],
    ];
}

/** A Track123 Shopify-app order payload: in transit, last scanned $daysAgo days ago. */
function commerceTTrack123Payload(int $daysAgo = 2, string $status = 'InTransit'): array
{
    $at = now()->subDays($daysAgo)->toIso8601String();

    return [
        'order_name' => '#SD8421',
        'order_number' => 8421,
        'order_id' => 99,
        'status' => 'open',
        'tracking_link' => 'https://track.example.test/SD8421',
        'fulfillments' => [[
            'tracking_company' => '4PX',
            'tracking_number' => '4PX00112233',
            'carrier_code' => '4px',
            'transit_status' => $status,
            'transit_sub_status' => null,
            'last_event' => 'Departed facility',
            'last_event_time' => $at,
            'courier' => ['code' => '4px', 'name' => '4PX Express', 'query_link' => 'https://track.4px.com'],
            'tracking_details' => [[
                'event_time' => $at,
                'event_time_utc' => $at,
                'event_detail' => 'Departed facility',
                'event_location' => 'Shenzhen',
            ]],
        ]],
    ];
}
