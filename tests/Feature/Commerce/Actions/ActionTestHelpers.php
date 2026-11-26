<?php

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Commerce\Actions\ActionRecorder;
use App\Domains\Commerce\Actions\Models\ActionExecution;
use App\Domains\Commerce\Actions\Models\ActionPolicy;
use App\Domains\Commerce\Actions\Models\ActionSettings;
use App\Domains\Commerce\Models\CommerceSetting;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Customer\Models\Customer;
use App\Domains\Mailbox\Models\Mailbox;
use App\Models\User;
use App\Models\Workspace;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Fixtures for tests/Feature/Commerce/Actions. Prefixed `actT`.
 *
 * @return array{workspace: Workspace, admin: User, agent: User, manager: User, conversation: Conversation, customer: Customer}
 */
function actTCase(string $mode = 'live', string $scopes = 'read_orders,write_orders,write_discounts,write_draft_orders,write_third_party_fulfillment_orders,read_all_orders'): array
{
    $workspace = Workspace::factory()->create();
    $mailbox = Mailbox::factory()->create(['workspace_id' => $workspace->id]);
    $customer = Customer::factory()->create(['workspace_id' => $workspace->id, 'email' => 'buyer@example.com', 'name' => 'Alex Buyer']);
    $conversation = Conversation::factory()->create([
        'workspace_id' => $workspace->id, 'mailbox_id' => $mailbox->id, 'customer_id' => $customer->id, 'subject' => 'Refund please', 'status' => 'open',
    ]);
    Thread::factory()->create([
        'conversation_id' => $conversation->id, 'customer_id' => $customer->id, 'user_id' => null, 'type' => 'message',
        'body' => '<p>Please refund order SD8421</p>', 'body_plain' => 'Please refund order SD8421',
    ]);

    CommerceSetting::forWorkspace($workspace->id)->update([
        'shopify_shop_domain' => 'test-shop.myshopify.com',
        'shopify_client_id' => '11111111111111111111111111111111',
        'shopify_client_secret' => Crypt::encryptString('shpss_secret'),
        'shopify_access_token' => Crypt::encryptString('shpca_test_token_value'),
        'shopify_access_token_expires_at' => now()->addHours(23),
        'shopify_granted_scopes' => $scopes,
    ]);
    AgentSetting::forWorkspace($workspace->id)->update(['mode' => $mode, 'kill_switch' => false, 'caps' => ['auto_send_per_day' => 0, 'auto_send_per_customer_per_day' => 0, 'actions_per_day' => 20]]);
    ActionSettings::forWorkspace($workspace->id);

    return [
        'workspace' => $workspace,
        'admin' => User::factory()->create(['workspace_id' => $workspace->id, 'role' => 'admin']),
        'agent' => User::factory()->create(['workspace_id' => $workspace->id, 'role' => 'agent']),
        'manager' => User::factory()->create(['workspace_id' => $workspace->id, 'role' => 'manager']),
        'conversation' => $conversation,
        'customer' => $customer,
    ];
}

function actTPolicy(int $workspaceId, string $action, string $mode, array $limits = [], ?int $playbookId = null, bool $override = false): ActionPolicy
{
    return ActionPolicy::updateOrCreate(
        ['workspace_id' => $workspaceId, 'action_key' => $action, 'playbook_id' => $playbookId],
        ['mode' => $mode, 'limits' => $limits, 'override_workspace' => $override],
    );
}

/** A completed live run that proposed $actions. */
function actTRun(array $case, array $actions, string $purpose = 'live', string $decision = 'draft'): AgentRun
{
    return AgentRun::create([
        'workspace_id' => $case['workspace']->id,
        'purpose' => $purpose,
        'conversation_id' => $case['conversation']->id,
        'idempotency_key' => $purpose.':'.Str::uuid7(),
        'status' => AgentRun::STATUS_COMPLETED,
        'config' => ['config_hash' => 'x'],
        'config_hash' => 'x',
        'decision' => $decision,
        'draft' => 'Hi Alex, I have arranged that for you.',
        'evidence' => ['facts' => [['id' => 'E1', 'source' => 'shopify', 'key' => 'shopify.order_number', 'label' => 'Order', 'value' => '#SD8421']], 'meta' => ['shopify_payload' => ['order_number' => '#SD8421', 'shopify_order_id' => 'gid://shopify/Order/99', 'order_email' => 'buyer@example.com']]],
        'proposed_actions' => array_map(fn ($a) => [
            'order_number' => 'SD8421', 'reason' => 'Customer asked (E1).', 'details' => null, 'status' => 'proposed', ...$a,
        ], $actions),
    ]);
}

/** Record one proposed action for a fresh live run and return its execution. */
function actTRecord(array $case, string $action, array $params, string $purpose = 'live', string $decision = 'draft'): ActionExecution
{
    $run = actTRun($case, [['action' => $action, 'params' => $params]], $purpose, $decision);
    $executions = app(ActionRecorder::class)->recordForRun($run, $case['conversation']);

    return $executions[0]->fresh();
}

/** Raw Shopify order node (what OrderStateLoader selects). */
function actTOrder(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 'gid://shopify/Order/99',
        'name' => '#SD8421',
        'email' => 'buyer@example.com',
        'createdAt' => now()->subMinutes(10)->toIso8601String(),
        'cancelledAt' => null,
        'currencyCode' => 'AUD',
        'presentmentCurrencyCode' => 'AUD',
        'refundable' => true,
        'displayFinancialStatus' => 'PAID',
        'displayFulfillmentStatus' => 'UNFULFILLED',
        'tags' => ['vip'],
        'note' => 'Gift wrap',
        'customer' => ['id' => 'gid://shopify/Customer/1'],
        'shippingAddress' => ['firstName' => 'Alex', 'lastName' => 'Buyer', 'company' => null, 'address1' => '1 Old St', 'address2' => null, 'city' => 'Sydney', 'province' => 'New South Wales', 'provinceCode' => 'NSW', 'zip' => '2000', 'country' => 'Australia', 'countryCodeV2' => 'AU', 'phone' => null],
        'totalPriceSet' => ['shopMoney' => ['amount' => '100.00', 'currencyCode' => 'AUD']],
        'netPaymentSet' => ['shopMoney' => ['amount' => '100.00', 'currencyCode' => 'AUD']],
        'totalRefundedSet' => ['shopMoney' => ['amount' => '0.00', 'currencyCode' => 'AUD']],
        'fulfillments' => [],
        'refunds' => [],
        'transactions' => [['id' => 'gid://shopify/OrderTransaction/1', 'kind' => 'SALE', 'status' => 'SUCCESS', 'gateway' => 'shopify_payments', 'amountSet' => ['shopMoney' => ['amount' => '100.00', 'currencyCode' => 'AUD']], 'maximumRefundableV2' => ['amount' => '100.00', 'currencyCode' => 'AUD']]],
        'lineItems' => ['nodes' => [[
            'id' => 'gid://shopify/LineItem/1', 'title' => 'Soul Dial Pro', 'variantTitle' => 'Black', 'sku' => 'SDP-1', 'quantity' => 1,
            'refundableQuantity' => 1, 'unfulfilledQuantity' => 1, 'variant' => ['id' => 'gid://shopify/ProductVariant/5'],
            'originalUnitPriceSet' => ['shopMoney' => ['amount' => '90.00', 'currencyCode' => 'AUD']],
            'discountedUnitPriceAfterAllDiscountsSet' => ['shopMoney' => ['amount' => '90.00', 'currencyCode' => 'AUD']],
        ]]],
        'fulfillmentOrders' => ['nodes' => [[
            'id' => 'gid://shopify/FulfillmentOrder/7', 'status' => 'OPEN', 'requestStatus' => 'UNSUBMITTED',
            'assignedLocation' => ['name' => '4PX Shenzhen'], 'fulfillmentHolds' => [],
        ]]],
    ], $overrides);
}

/**
 * Fake Shopify. $handlers: operation-name substring => response array |
 * callable(Request): mixed. Order-state queries default to actTOrder().
 */
function actTShopify(array $handlers = [], ?array $order = null): void
{
    $order ??= actTOrder();
    $handlers += [
        'ActionOrderState' => ['data' => ['order' => $order]],
        'ActionOrderByName' => ['data' => ['orders' => ['nodes' => [$order]]]],
        'ActionOrderNote' => ['data' => ['orderUpdate' => ['order' => ['id' => $order['id'], 'note' => 'x'], 'userErrors' => []]]],
    ];

    // Http::fake() stacks callbacks and the first match wins, so register one
    // dispatcher per test and swap the handlers it reads.
    app()->instance('actT.handlers', $handlers);
    Http::fake(['https://test-shop.myshopify.com/admin/api/*' => function (Request $request) {
        $query = (string) ($request->data()['query'] ?? '');
        foreach ((array) app('actT.handlers') as $operation => $response) {
            if (str_contains($query, (string) $operation)) {
                $value = is_callable($response) ? $response($request) : $response;

                return ($value instanceof PromiseInterface || $value instanceof Closure) ? $value : Http::response($value);
            }
        }

        return Http::response(['errors' => [['message' => 'unhandled in test']]]);
    }]);
}

/** Order-state handler returning each order in turn (the last one repeats): before, then after. */
function actTStates(array ...$orders): Closure
{
    $i = 0;

    return function () use (&$i, $orders) {
        $order = $orders[min($i++, count($orders) - 1)];

        return ['data' => ['order' => $order]];
    };
}

/**
 * Decoded JSON bodies of the requests whose document contains $needle.
 *
 * @return list<array{query: string, variables?: array<string, mixed>}>
 */
function actTSent(string $needle): array
{
    return Http::recorded(fn (Request $r) => str_contains((string) ($r->data()['query'] ?? ''), $needle))
        ->map(fn ($pair) => (array) json_decode($pair[0]->body(), true))->values()->all();
}
