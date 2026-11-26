<?php

require_once __DIR__.'/ActionTestHelpers.php';

use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\FeedbackEvent;
use App\Domains\Agent\Models\Playbook;
use App\Domains\AI\Jobs\GenerateReplySuggestionJob;
use App\Domains\Commerce\Actions\ActionApprovals;
use App\Domains\Commerce\Actions\ActionExecutor;
use App\Domains\Commerce\Actions\ActionGate;
use App\Domains\Commerce\Actions\ActionRecorder;
use App\Domains\Commerce\Actions\ActionRegistry;
use App\Domains\Commerce\Actions\Gateway\DryRunShopifyGateway;
use App\Domains\Commerce\Actions\Gateway\ShopifyGatewayFactory;
use App\Domains\Commerce\Actions\Jobs\ExecuteActionJob;
use App\Domains\Commerce\Actions\Jobs\ReconcileActionJob;
use App\Domains\Commerce\Actions\Models\ActionExecution;
use App\Domains\Commerce\Actions\Models\ActionPolicy;
use App\Domains\Commerce\Actions\Models\ActionSettings;
use App\Domains\Commerce\Actions\Policy\PolicyResolver;
use App\Domains\Conversation\Models\Thread;
use App\Enums\ThreadType;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

$refund = ['amount' => '20.00', 'shipping' => 'none', 'notify_customer' => true, 'reason' => 'Arrived scratched'];

function actTRefundHandlers(array $suggested = [], array $refundPayload = []): array
{
    return [
        'ActionSuggestedRefund' => ['data' => ['order' => ['id' => 'gid://shopify/Order/99', 'suggestedRefund' => array_replace_recursive([
            'amountSet' => ['shopMoney' => ['amount' => '90.00', 'currencyCode' => 'AUD']],
            'maximumRefundableSet' => ['shopMoney' => ['amount' => '100.00', 'currencyCode' => 'AUD']],
            'shipping' => ['amountSet' => ['shopMoney' => ['amount' => '10.00']], 'maximumRefundableSet' => ['shopMoney' => ['amount' => '10.00']]],
        ], $suggested)]]],
        'ActionRefund' => ['data' => ['refundCreate' => array_replace(['refund' => ['id' => 'gid://shopify/Refund/5', 'note' => 'x', 'totalRefundedSet' => ['shopMoney' => ['amount' => '20.00', 'currencyCode' => 'AUD']]], 'userErrors' => []], $refundPayload)]],
    ];
}

/** Order state after the refund (verify finds the marker). */
function actTRefundedOrder(int $executionId): array
{
    return actTOrder(['refunds' => [['id' => 'gid://shopify/Refund/5', 'note' => "Refund - fusterai-action-{$executionId}", 'createdAt' => now()->toIso8601String(), 'totalRefundedSet' => ['shopMoney' => ['amount' => '20.00', 'currencyCode' => 'AUD']]]]]);
}

// ── Defaults and policy resolution ───────────────────────────────────────────

test('a fresh install has every action set to never', function () {
    $case = actTCase();
    $resolver = app(PolicyResolver::class);

    foreach (array_keys(app(ActionRegistry::class)->all()) as $key) {
        expect($resolver->resolve($case['workspace']->id, $key, null)->mode)->toBe(ActionPolicy::NEVER);
    }
    expect(ActionPolicy::count())->toBe(0)
        ->and(array_keys(app(ActionRegistry::class)->all()))->toEqualCanonicalizing(array_keys(config('agent.actions')));

    $this->actingAs($case['admin'])->getJson('/agent/actions/catalogue')->assertOk()
        ->assertJsonPath('actions.0.workspace_mode', 'never');
});

test('a never policy cancels the proposal and sends nothing', function () use ($refund) {
    $case = actTCase();
    actTShopify();

    $e = actTRecord($case, 'refund', $refund);

    expect($e->status)->toBe(ActionExecution::CANCELLED)->and($e->error)->toContain('never');
    Http::assertNothingSent();
});

test('a playbook policy only tightens the workspace policy unless an admin overrides', function () {
    $case = actTCase();
    $playbook = Playbook::create(['workspace_id' => $case['workspace']->id, 'slug' => 'damaged', 'name' => 'Damaged', 'mode' => 'draft']);
    $resolver = app(PolicyResolver::class);
    $ws = $case['workspace']->id;

    actTPolicy($ws, 'refund', 'approval', ['max_amount' => 50, 'allowed_countries' => ['AU', 'NZ']]);
    actTPolicy($ws, 'refund', 'always', ['max_amount' => 500, 'allowed_countries' => ['AU', 'US']], $playbook->id);
    $p = $resolver->resolve($ws, 'refund', $playbook->id);
    expect($p->mode)->toBe('approval')
        ->and($p->limits['max_amount'])->toBe('50.00')
        ->and($p->limits['allowed_countries'])->toBe(['AU']);

    actTPolicy($ws, 'refund', 'always', ['max_amount' => 500], $playbook->id, override: true);
    $p = $resolver->resolve($ws, 'refund', $playbook->id);
    expect($p->mode)->toBe('always')->and($p->limits['max_amount'])->toBe('500.00')->and($p->source)->toBe('playbook_override');

    // Stricter playbook mode always wins.
    actTPolicy($ws, 'tag_order', 'always');
    actTPolicy($ws, 'tag_order', 'never', [], $playbook->id);
    expect($resolver->resolve($ws, 'tag_order', $playbook->id)->mode)->toBe('never');
});

test('replacement is approval-only and money actions always require the sender match', function () {
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'replacement', 'always');
    actTPolicy($case['workspace']->id, 'refund', 'always', ['require_sender_email_match' => false]);
    $resolver = app(PolicyResolver::class);

    expect($resolver->resolve($case['workspace']->id, 'replacement', null)->mode)->toBe('approval')
        ->and($resolver->resolve($case['workspace']->id, 'refund', null)->limits['require_sender_email_match'])->toBeTrue();

    $this->actingAs($case['admin'])->putJson('/agent/actions/policies/replacement', ['mode' => 'always'])->assertStatus(422);
    $this->actingAs($case['admin'])->putJson('/agent/actions/policies/refund', ['mode' => 'always', 'limits' => ['require_sender_email_match' => false]])->assertStatus(422);
});

// ── Approval path, per action ────────────────────────────────────────────────

test('refund: approval executes exactly one refundCreate with idempotency and the right input', function () use ($refund) {
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'refund', 'approval');
    actTShopify(actTRefundHandlers());

    $e = actTRecord($case, 'refund', $refund);
    expect($e->status)->toBe(ActionExecution::PENDING_APPROVAL)
        ->and($e->approval_reasons)->toContain('policy_requires_approval')
        ->and($e->amount)->toBe('20.00')
        ->and($e->description)->toContain('AUD 20.00')
        ->and($e->precondition_snapshot['passed'])->toBeTrue();
    expect(actTSent('refundCreate'))->toBe([]);

    actTShopify(actTRefundHandlers(), actTRefundedOrder($e->id));
    $this->actingAs($case['admin'])->postJson("/agent/actions/executions/{$e->id}/approve")->assertStatus(202);

    $e->refresh();
    $sent = actTSent('refundCreate');
    expect($e->status)->toBe(ActionExecution::SUCCEEDED)
        ->and($sent)->toHaveCount(1)
        ->and($e->approved_by)->toBe($case['admin']->id)
        ->and($e->verify_result['verify']['refund_id'])->toBe('gid://shopify/Refund/5')
        ->and($e->verify_result['audit_note'])->toBe('added');

    $body = $sent[0];
    expect($body['query'])->toContain('@idempotent(key: "'.$e->shopifyIdempotencyKey().'")')
        ->and($body['variables']['input']['transactions'][0])->toBe([
            'orderId' => 'gid://shopify/Order/99', 'parentId' => 'gid://shopify/OrderTransaction/1', 'kind' => 'REFUND', 'gateway' => 'shopify_payments', 'amount' => '20.00',
        ])
        ->and($body['variables']['input']['notify'])->toBeTrue()
        ->and($body['variables']['input']['note'])->toContain('fusterai-action-'.$e->id)
        ->and($body['variables']['input'])->not->toHaveKey('shipping');
});

test('refund by line items uses Shopify\'s suggested amount and explicit shipping', function () {
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'refund', 'approval');
    actTShopify(actTRefundHandlers(['amountSet' => ['shopMoney' => ['amount' => '100.00']]]));

    $e = actTRecord($case, 'refund', ['line_items' => [['line_item_id' => 'gid://shopify/LineItem/1', 'quantity' => 1, 'restock' => true]], 'shipping' => 'full', 'notify_customer' => false, 'reason' => 'Never shipped']);
    expect($e->amount)->toBe('100.00');

    actTShopify(actTRefundHandlers(['amountSet' => ['shopMoney' => ['amount' => '100.00']]]), actTRefundedOrder($e->id));
    app(ActionApprovals::class)->approve($e, $case['admin']);

    $input = actTSent('refundCreate')[0]['variables']['input'];
    $suggestQuery = actTSent('ActionSuggestedRefund')[0]['variables'];
    expect($input['refundLineItems'])->toBe([['lineItemId' => 'gid://shopify/LineItem/1', 'quantity' => 1, 'restockType' => 'CANCEL']])
        ->and($input['shipping'])->toBe(['fullRefund' => true])
        ->and($input['transactions'][0]['amount'])->toBe('100.00')
        ->and($suggestQuery['refundShipping'])->toBeTrue()
        ->and($e->fresh()->status)->toBe(ActionExecution::SUCCEEDED);
});

test('a refund is never more than Shopify says is refundable', function () {
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'refund', 'approval');
    actTShopify(actTRefundHandlers(['maximumRefundableSet' => ['shopMoney' => ['amount' => '30.00']]]));

    $e = actTRecord($case, 'refund', ['amount' => '45.00', 'shipping' => 'none', 'notify_customer' => true, 'reason' => 'Goodwill']);
    expect($e->status)->toBe(ActionExecution::PENDING_APPROVAL)
        ->and($e->approval_reasons)->toContain('preconditions_failed')
        ->and(collect($e->precondition_snapshot['checks'])->firstWhere('check', 'within_refundable')['ok'])->toBeFalse();

    app(ActionApprovals::class)->approve($e, $case['admin']);

    expect($e->fresh()->status)->toBe(ActionExecution::FAILED)
        ->and($e->fresh()->error)->toContain('only AUD 30.00 can be refunded')
        ->and(actTSent('refundCreate'))->toBe([]);
});

test('add_order_note appends to the existing note', function () {
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'add_order_note', 'approval');
    actTShopify();

    $e = actTRecord($case, 'add_order_note', ['note' => 'Customer will  collect']);
    expect($e->params)->toBe(['note' => 'Customer will collect']);

    actTShopify([], actTOrder(['note' => "Gift wrap\n[fusterai] Customer will collect - fusterai-action-{$e->id}"]));
    app(ActionApprovals::class)->approve($e, $case['agent']);

    $note = actTSent('orderUpdate')[0]['variables']['input']['note'];
    expect($note)->toStartWith("Gift wrap\n[")->toContain('Customer will collect')->toContain("fusterai-action-{$e->id}")
        ->and($e->fresh()->status)->toBe(ActionExecution::SUCCEEDED)
        ->and(actTSent('orderUpdate'))->toHaveCount(1); // no extra audit note for a note
});

test('tag_order adds and removes tags', function () {
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'tag_order', 'approval');
    actTShopify([
        'ActionTagsAdd' => ['data' => ['tagsAdd' => ['node' => ['id' => 'gid://shopify/Order/99'], 'userErrors' => []]]],
        'ActionTagsRemove' => ['data' => ['tagsRemove' => ['node' => ['id' => 'gid://shopify/Order/99'], 'userErrors' => []]]],
    ], actTOrder(['tags' => ['damaged']]));

    $e = actTRecord($case, 'tag_order', ['add' => ['damaged'], 'remove' => ['vip']]);
    app(ActionApprovals::class)->approve($e, $case['agent']);

    expect(actTSent('tagsAdd')[0]['variables'])->toBe(['id' => 'gid://shopify/Order/99', 'tags' => ['damaged']])
        ->and(actTSent('tagsRemove')[0]['variables']['tags'])->toBe(['vip'])
        ->and($e->fresh()->status)->toBe(ActionExecution::SUCCEEDED);
});

test('change_address sends orderUpdate.shippingAddress and warns about 4PX', function () {
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'change_address', 'approval');
    $address = ['first_name' => 'Alex', 'last_name' => 'Buyer', 'address1' => '9 New Rd', 'city' => 'Melbourne', 'province_code' => 'vic', 'zip' => '3000', 'country_code' => 'au'];
    actTShopify(['ActionChangeAddress' => ['data' => ['orderUpdate' => ['order' => ['id' => 'gid://shopify/Order/99'], 'userErrors' => []]]]]);

    $e = actTRecord($case, 'change_address', $address);
    expect($e->warnings[0]['text'])->toContain('4PX warehouse may already have this order')
        ->and($e->description)->toContain('9 New Rd');

    actTShopify([
        'ActionChangeAddress' => ['data' => ['orderUpdate' => ['order' => ['id' => 'gid://shopify/Order/99'], 'userErrors' => []]]],
        'ActionOrderState' => actTStates(actTOrder(), actTOrder(['shippingAddress' => ['address1' => '9 New Rd', 'zip' => '3000', 'countryCodeV2' => 'AU']])),
    ]);
    app(ActionApprovals::class)->approve($e, $case['admin']);

    expect(actTSent('ActionChangeAddress')[0]['variables']['input']['shippingAddress'])->toBe([
        'firstName' => 'Alex', 'lastName' => 'Buyer', 'address1' => '9 New Rd', 'city' => 'Melbourne', 'provinceCode' => 'VIC', 'zip' => '3000', 'countryCode' => 'AU',
    ])->and($e->fresh()->status)->toBe(ActionExecution::SUCCEEDED)
        ->and($e->fresh()->undo['previous_address']['address1'])->toBe('1 Old St');
});

test('cancel_order sends explicit restock, refund and notify choices', function () {
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'cancel_order', 'approval');
    actTShopify(['ActionCancelOrder' => ['data' => ['orderCancel' => ['job' => ['id' => 'gid://shopify/Job/1', 'done' => false], 'orderCancelUserErrors' => []]]]]);

    $e = actTRecord($case, 'cancel_order', ['reason' => 'customer', 'restock' => true, 'refund' => true, 'notify_customer' => false]);
    expect($e->amount)->toBe('100.00')->and($e->description)->toContain('Refund AUD 100.00')->toContain('cannot be undone');

    actTShopify([
        'ActionCancelOrder' => ['data' => ['orderCancel' => ['job' => ['id' => 'gid://shopify/Job/1', 'done' => false], 'orderCancelUserErrors' => []]]],
        'ActionOrderState' => actTStates(actTOrder(), actTOrder(['cancelledAt' => now()->toIso8601String()])),
    ]);
    app(ActionApprovals::class)->approve($e, $case['admin']);

    $vars = actTSent('orderCancel')[0]['variables'];
    expect($vars)->toMatchArray(['orderId' => 'gid://shopify/Order/99', 'reason' => 'CUSTOMER', 'restock' => true, 'notifyCustomer' => false, 'refundMethod' => ['originalPaymentMethodsRefund' => true]])
        ->and($e->fresh()->status)->toBe(ActionExecution::SUCCEEDED);
});

test('cancel_order is refused for a fulfilled order', function () {
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'cancel_order', 'approval');
    actTShopify([], actTOrder(['displayFulfillmentStatus' => 'FULFILLED', 'fulfillments' => [['id' => 'gid://shopify/Fulfillment/1', 'status' => 'SUCCESS', 'createdAt' => now()->toIso8601String()]]]));

    $e = actTRecord($case, 'cancel_order', ['reason' => 'CUSTOMER', 'restock' => false, 'refund' => false, 'notify_customer' => false]);
    app(ActionApprovals::class)->approve($e, $case['admin']);

    expect($e->fresh()->status)->toBe(ActionExecution::FAILED)->and(actTSent('orderCancel'))->toBe([]);
});

test('discount_code creates a single-use code from an approved template', function () {
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'discount_code', 'approval');
    ActionSettings::forWorkspace($case['workspace']->id)->update(['discount_templates' => [
        ['key' => 'sorry10', 'label' => 'Sorry 10%', 'type' => 'percentage', 'value' => 10, 'max_value' => 15, 'expires_days' => 30],
    ]]);
    actTShopify(['ActionDiscountCode' => ['data' => ['discountCodeBasicCreate' => ['codeDiscountNode' => ['id' => 'gid://shopify/DiscountCodeNode/3'], 'userErrors' => []]]]]);

    $run = actTRun($case, [['action' => 'discount_code', 'params' => ['template' => 'sorry10', 'code' => 'SD-ABCDEFGH']]]);
    $e = app(ActionRecorder::class)->recordForRun($run, $case['conversation'])[0]->fresh();
    expect($e->params['type'])->toBe('percentage')->and($e->description)->toContain('usable once');

    actTShopify([
        'ActionDiscountCode' => ['data' => ['discountCodeBasicCreate' => ['codeDiscountNode' => ['id' => 'gid://shopify/DiscountCodeNode/3'], 'userErrors' => []]]],
        'ActionDiscountByCode' => ['data' => ['codeDiscountNodeByCode' => ['id' => 'gid://shopify/DiscountCodeNode/3']]],
    ]);
    app(ActionApprovals::class)->approve($e, $case['admin']);

    $input = actTSent('discountCodeBasicCreate')[0]['variables']['basicCodeDiscount'];
    expect($input)->toMatchArray(['code' => 'SD-ABCDEFGH', 'usageLimit' => 1, 'appliesOncePerCustomer' => true, 'context' => ['customers' => ['add' => ['gid://shopify/Customer/1']]], 'customerGets' => ['value' => ['percentage' => 0.1], 'items' => ['all' => true]]])
        ->and($e->fresh()->status)->toBe(ActionExecution::SUCCEEDED);

    // Above the template maximum: invalid.
    $bad = app(ActionRegistry::class)->get('discount_code')->validate(['template' => 'sorry10', 'value' => 40, 'code' => 'SD-ABCDEFGH'], ActionSettings::forWorkspace($case['workspace']->id));
    expect($bad['errors'])->toHaveKey('value');
});

test('replacement creates a 100% discounted draft order and completes it', function () {
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'replacement', 'approval');
    $handlers = [
        'ActionReplacementDraft' => ['data' => ['draftOrderCreate' => ['draftOrder' => ['id' => 'gid://shopify/DraftOrder/8', 'name' => '#D8'], 'userErrors' => []]]],
        'ActionReplacementComplete' => ['data' => ['draftOrderComplete' => ['draftOrder' => ['id' => 'gid://shopify/DraftOrder/8', 'status' => 'COMPLETED', 'order' => ['id' => 'gid://shopify/Order/100', 'name' => '#SD8500']], 'userErrors' => []]]],
        'ActionReplacementFind' => ['data' => ['draftOrders' => ['nodes' => [['id' => 'gid://shopify/DraftOrder/8', 'name' => '#D8', 'status' => 'COMPLETED', 'order' => ['id' => 'gid://shopify/Order/100']]]]]],
    ];
    actTShopify($handlers);

    $e = actTRecord($case, 'replacement', ['line_items' => [['line_item_id' => 'gid://shopify/LineItem/1', 'quantity' => 1]], 'reason' => 'Lost in transit']);
    app(ActionApprovals::class)->approve($e, $case['admin']);

    $input = actTSent('draftOrderCreate')[0]['variables']['input'];
    expect($input['lineItems'])->toBe([['variantId' => 'gid://shopify/ProductVariant/5', 'quantity' => 1]])
        ->and($input['appliedDiscount'])->toMatchArray(['valueType' => 'PERCENTAGE', 'value' => 100])
        ->and($input['tags'])->toContain('fusterai-action-'.$e->id)
        ->and(actTSent('draftOrderComplete')[0]['variables'])->toBe(['id' => 'gid://shopify/DraftOrder/8'])
        ->and($e->fresh()->status)->toBe(ActionExecution::SUCCEEDED);
});

test('fulfilment_hold places a hold with our handle and release removes only ours', function () {
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'fulfilment_hold', 'approval');
    actTShopify(['ActionFulfilmentHold' => ['data' => ['fulfillmentOrderHold' => ['fulfillmentHold' => ['id' => 'gid://shopify/FulfillmentHold/1'], 'userErrors' => []]]]]);

    $e = actTRecord($case, 'fulfilment_hold', ['operation' => 'hold', 'reason' => 'incorrect_address', 'note' => 'Checking the address']);
    actTShopify(['ActionFulfilmentHold' => ['data' => ['fulfillmentOrderHold' => ['fulfillmentHold' => ['id' => 'gid://shopify/FulfillmentHold/1'], 'userErrors' => []]]]],
        actTOrder(['fulfillmentOrders' => ['nodes' => [['id' => 'gid://shopify/FulfillmentOrder/7', 'status' => 'ON_HOLD', 'requestStatus' => 'UNSUBMITTED', 'fulfillmentHolds' => [['id' => 'gid://shopify/FulfillmentHold/1', 'handle' => 'fusterai-action-'.ActionExecution::max('id'), 'reason' => 'INCORRECT_ADDRESS', 'heldByRequestingApp' => true]]]]]]));
    app(ActionApprovals::class)->approve($e, $case['agent']);

    expect(actTSent('fulfillmentOrderHold')[0]['variables'])->toMatchArray(['id' => 'gid://shopify/FulfillmentOrder/7', 'fulfillmentHold' => ['reason' => 'INCORRECT_ADDRESS', 'reasonNotes' => 'Checking the address - fusterai-action-'.$e->id, 'handle' => 'fusterai-action-'.$e->id, 'notifyMerchant' => false]])
        ->and($e->fresh()->status)->toBe(ActionExecution::SUCCEEDED);

    $held = actTOrder(['fulfillmentOrders' => ['nodes' => [['id' => 'gid://shopify/FulfillmentOrder/7', 'status' => 'ON_HOLD', 'requestStatus' => 'UNSUBMITTED', 'fulfillmentHolds' => [
        ['id' => 'gid://shopify/FulfillmentHold/1', 'handle' => 'fusterai-action-'.$e->id, 'reason' => 'INCORRECT_ADDRESS', 'heldByRequestingApp' => true],
        ['id' => 'gid://shopify/FulfillmentHold/2', 'handle' => null, 'reason' => 'OTHER', 'heldByRequestingApp' => false],
    ]]]]]);
    actTShopify(['ActionFulfilmentRelease' => ['data' => ['fulfillmentOrderReleaseHold' => ['fulfillmentOrder' => ['id' => 'gid://shopify/FulfillmentOrder/7', 'status' => 'OPEN'], 'userErrors' => []]]]], $held);
    $release = actTRecord($case, 'fulfilment_hold', ['operation' => 'release', 'note' => 'Address confirmed']);
    actTShopify([
        'ActionFulfilmentRelease' => ['data' => ['fulfillmentOrderReleaseHold' => ['fulfillmentOrder' => ['id' => 'gid://shopify/FulfillmentOrder/7', 'status' => 'OPEN'], 'userErrors' => []]]],
        'ActionOrderState' => actTStates($held, actTOrder(['fulfillmentOrders' => ['nodes' => [['id' => 'gid://shopify/FulfillmentOrder/7', 'status' => 'ON_HOLD', 'requestStatus' => 'UNSUBMITTED', 'fulfillmentHolds' => [['id' => 'gid://shopify/FulfillmentHold/2', 'handle' => null, 'reason' => 'OTHER', 'heldByRequestingApp' => false]]]]]])),
    ]);
    app(ActionApprovals::class)->approve($release->fresh(), $case['agent']);

    expect(actTSent('fulfillmentOrderReleaseHold')[0]['variables'])->toBe(['id' => 'gid://shopify/FulfillmentOrder/7', 'holdIds' => ['gid://shopify/FulfillmentHold/1']])
        ->and($release->fresh()->status)->toBe(ActionExecution::SUCCEEDED);
});

test('every action rejects invalid params', function (string $action, array $params, string $field) {
    $case = actTCase();
    ActionSettings::forWorkspace($case['workspace']->id)->update(['discount_templates' => [['key' => 't', 'label' => 'T', 'type' => 'fixed', 'value' => 10, 'expires_days' => 30]]]);
    $result = app(ActionRegistry::class)->get($action)->validate($params, ActionSettings::forWorkspace($case['workspace']->id)->fresh());

    expect($result['errors'])->toHaveKey($field);
})->with([
    'note empty' => ['add_order_note', ['note' => ''], 'note'],
    'tags none' => ['tag_order', ['add' => [], 'remove' => []], 'add'],
    'tag bad chars' => ['tag_order', ['add' => ['<script>']], 'add.0'],
    'address no zip' => ['change_address', ['address1' => '1 A St', 'city' => 'X', 'country_code' => 'AU'], 'zip'],
    'address bad country' => ['change_address', ['address1' => '1 A St', 'city' => 'X', 'zip' => '1', 'country_code' => 'Australia'], 'country_code'],
    'cancel implicit refund' => ['cancel_order', ['reason' => 'CUSTOMER', 'restock' => true, 'notify_customer' => false], 'refund'],
    'cancel fraud reason' => ['cancel_order', ['reason' => 'FRAUD', 'restock' => true, 'refund' => true, 'notify_customer' => false], 'reason'],
    'refund nothing' => ['refund', ['shipping' => 'none', 'notify_customer' => true, 'reason' => 'x y z'], 'amount'],
    'refund both modes' => ['refund', ['amount' => '5', 'line_items' => [['line_item_id' => 'gid://shopify/LineItem/1', 'quantity' => 1]], 'shipping' => 'none', 'notify_customer' => true, 'reason' => 'abc'], 'amount'],
    'refund implicit shipping' => ['refund', ['amount' => '5', 'notify_customer' => true, 'reason' => 'abc'], 'shipping'],
    'discount unknown template' => ['discount_code', ['template' => 'nope', 'code' => 'SD-ABCDEFGH'], 'template'],
    'discount model code' => ['discount_code', ['template' => 't', 'code' => 'FREEMONEY'], 'code'],
    'replacement no items' => ['replacement', ['line_items' => [], 'reason' => 'abc'], 'line_items'],
    'hold no reason' => ['fulfilment_hold', ['operation' => 'hold', 'note' => 'abc'], 'reason'],
]);

// ── Unattended path and limits ───────────────────────────────────────────────

test('within every limit a refund runs unattended', function () use ($refund) {
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'refund', 'always', ['max_amount' => 50, 'per_customer_30d_amount' => 100, 'per_day_amount' => 200, 'allowed_countries' => ['AU']]);
    $order = actTOrder();
    Http::fake(['https://test-shop.myshopify.com/admin/api/*' => function (Request $r) {
        $q = (string) $r->data()['query'];
        if (str_contains($q, 'ActionSuggestedRefund')) {
            return Http::response(actTRefundHandlers()['ActionSuggestedRefund']);
        }
        if (str_contains($q, 'refundCreate')) {
            return Http::response(actTRefundHandlers()['ActionRefund']);
        }
        if (str_contains($q, 'orderUpdate')) {
            return Http::response(['data' => ['orderUpdate' => ['order' => ['id' => 'x'], 'userErrors' => []]]]);
        }
        $id = ActionExecution::max('id');

        return Http::response(['data' => ['order' => actTRefundedOrder((int) $id)]]);
    }]);

    $e = actTRecord($case, 'refund', $refund);

    expect($e->route)->toBe(ActionExecution::ROUTE_UNATTENDED)
        ->and($e->status)->toBe(ActionExecution::SUCCEEDED)
        ->and($e->approved_by)->toBeNull()
        ->and(actTSent('refundCreate'))->toHaveCount(1);
});

test('each limit sends the action to approval with its reason', function (array $limits, array $order, string $reason, array $setup = []) use ($refund) {
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'refund', 'always', [...['max_amount' => 50], ...$limits]);
    if (isset($setup['prior'])) {
        ActionExecution::create(['workspace_id' => $case['workspace']->id, 'action_key' => 'refund', 'params' => [], 'status' => 'succeeded', 'amount' => $setup['prior'], 'customer_email' => $setup['email'] ?? 'buyer@example.com', 'idempotency_key' => 'prior-'.uniqid()]);
    }
    if (isset($setup['ceiling'])) {
        ActionSettings::forWorkspace($case['workspace']->id)->update(['refund_hard_ceiling' => $setup['ceiling']]);
    }
    actTShopify(actTRefundHandlers(), actTOrder($order));

    $e = actTRecord($case, 'refund', $setup['params'] ?? $refund);

    expect($e->status)->toBe(ActionExecution::PENDING_APPROVAL)
        ->and($e->approval_reasons)->toContain($reason)
        ->and(actTSent('refundCreate'))->toBe([]);
})->with([
    'max per action' => [['max_amount' => 10], [], 'over_max_amount'],
    'no max amount' => [['max_amount' => null], [], 'no_max_amount_limit'],
    'per customer rolling amount' => [['per_customer_30d_amount' => 30], [], 'over_customer_30d_amount', ['prior' => '15.00']],
    'per customer rolling count' => [['per_customer_30d_count' => 1], [], 'over_customer_30d_count', ['prior' => '1.00']],
    'daily aggregate' => [['per_day_amount' => 25], [], 'over_daily_amount', ['prior' => '10.00', 'email' => 'other@example.com']],
    'order age' => [['max_order_age_days' => 30], ['createdAt' => now()->subDays(45)->toIso8601String()], 'over_order_age'],
    'fulfilment state' => [['allowed_fulfilment_states' => ['FULFILLED']], [], 'fulfilment_state_not_allowed'],
    'country' => [['allowed_countries' => ['NZ']], [], 'country_not_allowed'],
    'hard ceiling' => [['max_amount' => 500], [], 'over_hard_ceiling', ['ceiling' => 10]],
    'sender mismatch' => [[], ['email' => 'someone@else.com'], 'sender_email_mismatch'],
]);

test('the rolling window ignores old executions', function () use ($refund) {
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'refund', 'always', ['max_amount' => 50, 'per_customer_30d_count' => 1]);
    $old = ActionExecution::create(['workspace_id' => $case['workspace']->id, 'action_key' => 'refund', 'params' => [], 'status' => 'succeeded', 'amount' => '5.00', 'customer_email' => 'buyer@example.com', 'idempotency_key' => 'old']);
    $old->forceFill(['created_at' => now()->subDays(40)])->save();
    Queue::fake();
    actTShopify(actTRefundHandlers());

    $e = actTRecord($case, 'refund', $refund);

    expect($e->status)->toBe(ActionExecution::APPROVED)->and($e->route)->toBe('unattended');
    Queue::assertPushed(ExecuteActionJob::class);
});

test('a sender email mismatch hands the conversation over', function () use ($refund) {
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'refund', 'always', ['max_amount' => 50]);
    actTShopify(actTRefundHandlers(), actTOrder(['email' => 'thief@example.com']));

    $e = actTRecord($case, 'refund', $refund);

    expect($e->status)->toBe(ActionExecution::PENDING_APPROVAL)
        ->and(collect($e->warnings)->pluck('level'))->toContain('danger')
        ->and($case['conversation']->fresh()->handover_reason)->toBe('action:sender_email_mismatch')
        ->and(Thread::where('conversation_id', $case['conversation']->id)->where('type', ThreadType::Note)->exists())->toBeTrue();
});

test('nothing runs unattended unless the agent is live', function () use ($refund) {
    $case = actTCase(mode: 'draft');
    actTPolicy($case['workspace']->id, 'refund', 'always', ['max_amount' => 50]);
    actTShopify(actTRefundHandlers());

    $e = actTRecord($case, 'refund', $refund);

    expect($e->status)->toBe('pending_approval')->and($e->approval_reasons)->toContain('agent_mode_not_live');
});

// ── 4PX guard ────────────────────────────────────────────────────────────────

test('the 4PX guard: address change unattended only when young, unfulfilled and not with the warehouse', function (array $order, bool $unattended) {
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'change_address', 'always');
    Queue::fake();
    actTShopify([], actTOrder($order));

    $e = actTRecord($case, 'change_address', ['address1' => '9 New Rd', 'city' => 'Sydney', 'zip' => '2000', 'country_code' => 'AU']);

    expect($e->status)->toBe($unattended ? 'approved' : 'pending_approval');
    if (! $unattended) {
        expect($e->approval_reasons)->toContain('warehouse_guard')
            ->and($e->warnings[0]['level'])->toBe('danger')
            ->and($e->warnings[0]['text'])->toContain('4PX');
    }
})->with([
    'young and unsubmitted' => [[], true],
    'accepted by the warehouse' => [['fulfillmentOrders' => ['nodes' => [['id' => 'gid://shopify/FulfillmentOrder/7', 'status' => 'IN_PROGRESS', 'requestStatus' => 'ACCEPTED', 'assignedLocation' => ['name' => '4PX'], 'fulfillmentHolds' => []]]]], false],
    'older than the window' => [['createdAt' => now()->subHours(3)->toIso8601String()], false],
]);

test('without a fulfilment-order scope the 4PX guard cannot clear', function () {
    $case = actTCase(scopes: 'read_orders,write_orders');
    actTPolicy($case['workspace']->id, 'cancel_order', 'always', ['max_amount' => 500]);
    actTShopify();

    $e = actTRecord($case, 'cancel_order', ['reason' => 'CUSTOMER', 'restock' => true, 'refund' => false, 'notify_customer' => false]);

    expect($e->approval_reasons)->toContain('warehouse_guard')
        ->and(actTSent('ActionOrderState')[0]['query'])->not->toContain('fulfillmentOrders');
});

// ── Executor safety ──────────────────────────────────────────────────────────

test('a double dispatch and a double approve send exactly one mutation', function () use ($refund) {
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'refund', 'approval');
    actTShopify(actTRefundHandlers());
    $e = actTRecord($case, 'refund', $refund);
    actTShopify(actTRefundHandlers(), actTRefundedOrder($e->id));

    Queue::fake();
    $first = $this->actingAs($case['admin'])->postJson("/agent/actions/executions/{$e->id}/approve");
    $second = $this->actingAs($case['admin'])->postJson("/agent/actions/executions/{$e->id}/approve");
    expect($first->status())->toBe(202)->and($second->status())->toBe(409);

    app(ActionExecutor::class)->execute($e->id);
    app(ActionExecutor::class)->execute($e->id);
    (new ExecuteActionJob($e->id))->handle(app(ActionExecutor::class));

    expect(actTSent('refundCreate'))->toHaveCount(1)
        ->and($e->fresh()->status)->toBe(ActionExecution::SUCCEEDED);
});

test('a timeout becomes unknown and reconcile finds the refund', function () use ($refund) {
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'refund', 'approval');
    actTShopify(actTRefundHandlers());
    $e = actTRecord($case, 'refund', $refund);

    Queue::fake();
    actTShopify([...actTRefundHandlers(), 'ActionRefund' => fn () => Http::failedConnection('cURL error 28: timed out')], actTOrder());
    app(ActionApprovals::class)->approve($e, $case['admin']);
    app(ActionExecutor::class)->execute($e->id);

    expect($e->fresh()->status)->toBe(ActionExecution::UNKNOWN)
        ->and($e->fresh()->error)->toContain('timed out');
    Queue::assertPushed(ReconcileActionJob::class);

    actTShopify(actTRefundHandlers(), actTRefundedOrder($e->id));
    app(ActionExecutor::class)->reconcile($e->id);

    expect($e->fresh()->status)->toBe(ActionExecution::SUCCEEDED)
        ->and($e->fresh()->verify_result['reconciled'])->toBeTrue()
        ->and(actTSent('refundCreate'))->toBe([]); // never re-sent
});

test('a 5xx becomes unknown and two empty reconciles mark it failed', function () use ($refund) {
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'refund', 'approval');
    actTShopify(actTRefundHandlers());
    $e = actTRecord($case, 'refund', $refund);

    Queue::fake();
    actTShopify([...actTRefundHandlers(), 'ActionRefund' => fn () => Http::response('Bad gateway', 502)]);
    app(ActionApprovals::class)->approve($e, $case['admin']);
    app(ActionExecutor::class)->execute($e->id);
    expect($e->fresh()->status)->toBe(ActionExecution::UNKNOWN);

    actTShopify(actTRefundHandlers(), actTOrder());
    app(ActionExecutor::class)->reconcile($e->id);
    expect($e->fresh()->status)->toBe(ActionExecution::UNKNOWN); // one empty look is not believed
    app(ActionExecutor::class)->reconcile($e->id);

    expect($e->fresh()->status)->toBe(ActionExecution::FAILED)
        ->and($case['conversation']->fresh()->handover_reason)->toBe('action:reconciled_failed')
        ->and(actTSent('refundCreate'))->toBe([]);
});

test('userErrors fail the action and hand over with the error', function () use ($refund) {
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'refund', 'approval');
    actTShopify(actTRefundHandlers());
    $e = actTRecord($case, 'refund', $refund);

    Queue::fake([GenerateReplySuggestionJob::class]);
    actTShopify(actTRefundHandlers([], ['refund' => null, 'userErrors' => [['field' => ['transactions'], 'message' => 'Amount exceeds refundable']]]));
    app(ActionApprovals::class)->approve($e, $case['admin']);

    $e->refresh();
    $note = Thread::where('conversation_id', $case['conversation']->id)->where('type', ThreadType::Note)->latest('id')->first();
    expect($e->status)->toBe(ActionExecution::FAILED)
        ->and($e->error)->toContain('Amount exceeds refundable')
        ->and($case['conversation']->fresh()->handover_reason)->toBe('action:user_errors')
        ->and($note->body_plain)->toContain('Amount exceeds refundable');
});

test('the kill switch cancels a queued action without any request', function () use ($refund) {
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'refund', 'approval');
    actTShopify(actTRefundHandlers());
    $e = actTRecord($case, 'refund', $refund);

    Queue::fake();
    app(ActionApprovals::class)->approve($e, $case['admin']);
    AgentSetting::forWorkspace($case['workspace']->id)->update(['kill_switch' => true]);
    Http::fake();

    app(ActionExecutor::class)->execute($e->id);

    expect($e->fresh()->status)->toBe(ActionExecution::CANCELLED)->and($e->fresh()->error)->toContain('kill switch');
    Http::assertNothingSent();
    expect(app(ActionApprovals::class)->approve(actTRecordPending($case), $case['admin'])['status'])->toBe(409);
});

function actTRecordPending(array $case): ActionExecution
{
    return ActionExecution::create(['workspace_id' => $case['workspace']->id, 'conversation_id' => $case['conversation']->id, 'action_key' => 'add_order_note', 'params' => ['note' => 'x y'], 'status' => 'pending_approval', 'idempotency_key' => 'p-'.uniqid()]);
}

test('non-live purposes can never reach HTTP', function (string $purpose) use ($refund) {
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'refund', 'always', ['max_amount' => 50]);
    Http::fake();

    $run = actTRun($case, [['action' => 'refund', 'params' => $refund]], $purpose);
    expect(fn () => app(ActionRecorder::class)->recordForRun($run, $case['conversation']))->toThrow(LogicException::class);
    expect(app(ShopifyGatewayFactory::class)->forRun($run))->toBeInstanceOf(DryRunShopifyGateway::class);

    // Even a row that claims to be approved never leaves the building.
    $e = ActionExecution::create(['workspace_id' => $case['workspace']->id, 'conversation_id' => $case['conversation']->id, 'run_id' => $run->id, 'purpose' => $purpose, 'action_key' => 'refund', 'params' => $refund, 'status' => 'approved', 'idempotency_key' => 'nl-'.$purpose]);
    expect(app(ShopifyGatewayFactory::class)->forExecution($e))->toBeInstanceOf(DryRunShopifyGateway::class);
    app(ActionExecutor::class)->execute($e->id);

    expect($e->fresh()->status)->toBe('cancelled')->and($e->fresh()->dry_run)->toBeTrue();
    Http::assertNothingSent();
})->with(['preview', 'simulation', 'shadow', 'test']);

test('a live-purpose row linked to a non-live run is still a dry run', function () use ($refund) {
    $case = actTCase();
    Http::fake();
    $run = actTRun($case, [], 'simulation');
    $e = ActionExecution::create(['workspace_id' => $case['workspace']->id, 'run_id' => $run->id, 'purpose' => 'live', 'action_key' => 'refund', 'params' => $refund, 'status' => 'approved', 'idempotency_key' => 'mix']);

    app(ActionExecutor::class)->execute($e->id);

    expect($e->fresh()->dry_run)->toBeTrue();
    Http::assertNothingSent();
});

test('state that changed between approval and execution stops the action', function () {
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'change_address', 'approval');
    actTShopify();
    $e = actTRecord($case, 'change_address', ['address1' => '9 New Rd', 'city' => 'Sydney', 'zip' => '2000', 'country_code' => 'AU']);

    actTShopify([], actTOrder(['displayFulfillmentStatus' => 'FULFILLED', 'fulfillments' => [['id' => 'f', 'status' => 'SUCCESS', 'createdAt' => now()->toIso8601String()]]]));
    app(ActionApprovals::class)->approve($e, $case['admin']);

    expect($e->fresh()->status)->toBe(ActionExecution::FAILED)
        ->and($e->fresh()->error)->toContain('changed since approval')
        ->and(actTSent('ActionChangeAddress'))->toBe([]);
});

test('an unattended action whose limits no longer hold goes to approval instead', function () use ($refund) {
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'refund', 'always', ['max_amount' => 50]);
    Queue::fake();
    actTShopify(actTRefundHandlers());
    $e = actTRecord($case, 'refund', $refund);
    expect($e->status)->toBe('approved');

    actTPolicy($case['workspace']->id, 'refund', 'always', ['max_amount' => 5]);
    app(ActionExecutor::class)->execute($e->id);

    expect($e->fresh()->status)->toBe('pending_approval')
        ->and($e->fresh()->approval_reasons)->toContain('over_max_amount')
        ->and(actTSent('refundCreate'))->toBe([]);
});

test('missing scopes make an action unavailable everywhere', function () use ($refund) {
    $case = actTCase(scopes: 'read_orders');
    actTPolicy($case['workspace']->id, 'refund', 'approval');
    actTShopify();

    $catalogue = $this->actingAs($case['admin'])->getJson('/agent/actions/catalogue')->assertOk()->json();
    $entry = collect($catalogue['actions'])->firstWhere('key', 'refund');
    expect($entry['available'])->toBeFalse()
        ->and($entry['missing_scopes'])->toBe([['write_orders']])
        ->and($entry['unavailable_reason'])->toContain('write_orders')
        ->and(collect($catalogue['advisories'])->pluck('scope'))->toContain('read_all_orders');

    $e = actTRecord($case, 'refund', $refund);
    expect($e->status)->toBe('cancelled')->and($e->error)->toContain('write_orders');

    $pending = ActionExecution::create(['workspace_id' => $case['workspace']->id, 'conversation_id' => $case['conversation']->id, 'action_key' => 'refund', 'params' => $refund, 'status' => 'pending_approval', 'idempotency_key' => 'ms']);
    $this->actingAs($case['admin'])->postJson("/agent/actions/executions/{$pending->id}/approve")->assertStatus(422);
    expect(actTSent('refundCreate'))->toBe([]);
});

test('stale approvals expire', function () {
    $case = actTCase();
    $old = actTRecordPending($case);
    $old->forceFill(['expires_at' => now()->subMinute()])->save();
    $fresh = actTRecordPending($case);
    $fresh->forceFill(['expires_at' => now()->addHour()])->save();

    $this->artisan('agent:actions-expire')->assertSuccessful();

    expect($old->fresh()->status)->toBe('expired')->and($fresh->fresh()->status)->toBe('pending_approval');
});

test('identical proposals reuse one execution', function () use ($refund) {
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'refund', 'approval');
    actTShopify(actTRefundHandlers());

    $a = actTRecord($case, 'refund', $refund);
    $b = actTRecord($case, 'refund', [...$refund, 'reason' => 'Arrived scratched']);

    expect($b->id)->toBe($a->id)->and(ActionExecution::count())->toBe(1);
});

// ── Reply coupling ───────────────────────────────────────────────────────────

test('a reply waits for its action, is sendable after success and needs a rewrite after rejection', function () use ($refund) {
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'refund', 'approval');
    actTPolicy($case['workspace']->id, 'add_order_note', 'approval');
    actTShopify(actTRefundHandlers());

    $run = actTRun($case, [['action' => 'refund', 'params' => $refund]]);
    app(ActionRecorder::class)->recordForRun($run, $case['conversation']);
    $run->refresh();
    $id = $run->proposed_actions[0]['execution_id'];

    expect(ActionGate::state($run)['state'])->toBe('waiting')->and(ActionGate::blocksSending($run))->toBeTrue();

    actTShopify(actTRefundHandlers(), actTRefundedOrder($id));
    app(ActionApprovals::class)->approve(ActionExecution::find($id), $case['admin']);
    expect(ActionGate::state($run)['state'])->toBe('ready')->and(ActionGate::blocksSending($run))->toBeFalse();

    // A second run: rejected -> needs rewrite + feedback event + regenerate requested.
    Queue::fake();
    $run2 = actTRun($case, [['action' => 'refund', 'params' => [...$refund, 'amount' => '25.00']], ['action' => 'add_order_note', 'params' => ['note' => 'Checked']]]);
    app(ActionRecorder::class)->recordForRun($run2, $case['conversation']);
    $run2->refresh();
    $e2 = ActionExecution::find($run2->proposed_actions[0]['execution_id']);
    $this->actingAs($case['admin'])->postJson("/agent/actions/executions/{$e2->id}/reject", ['reason' => 'Too generous'])->assertOk();

    expect(ActionGate::state($run2)['state'])->toBe('needs_rewrite')
        ->and($e2->fresh()->rewrite_requested)->toBeTrue()
        ->and(FeedbackEvent::where('kind', 'rejected')->where('meta->execution_id', $e2->id)->exists())->toBeTrue();
    Queue::assertPushed(GenerateReplySuggestionJob::class);

    // Non-blocking actions never hold a reply.
    $run3 = actTRun($case, [['action' => 'add_order_note', 'params' => ['note' => 'Only a note']]]);
    app(ActionRecorder::class)->recordForRun($run3, $case['conversation']);
    expect(ActionGate::blocksSending($run3->fresh()))->toBeFalse();
});

test('a live run with an unrecorded reply-blocking proposal fails closed', function () {
    $case = actTCase();
    $run = actTRun($case, [['action' => 'refund', 'params' => []]]);

    expect(ActionGate::blocksSending($run))->toBeTrue();
});

// ── Audit and secrets ────────────────────────────────────────────────────────

test('stored requests and responses never contain the access token', function () use ($refund) {
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'refund', 'approval');
    actTShopify(actTRefundHandlers());
    $e = actTRecord($case, 'refund', $refund);
    actTShopify([...actTRefundHandlers(), 'ActionRefund' => ['data' => ['refundCreate' => ['refund' => ['id' => 'r', 'note' => 'leak shpca_test_token_value'], 'userErrors' => []]], 'extensions' => ['access_token' => 'shpca_test_token_value']]], actTRefundedOrder($e->id));
    app(ActionApprovals::class)->approve($e, $case['admin']);

    $raw = json_encode(ActionExecution::find($e->id)->only(['request', 'response', 'verify_result', 'precondition_snapshot']));
    expect($raw)->not->toContain('shpca_test_token_value')->toContain('[redacted]')
        ->and(Http::recorded()->first()[0]->header('X-Shopify-Access-Token')[0])->toBe('shpca_test_token_value');
});
