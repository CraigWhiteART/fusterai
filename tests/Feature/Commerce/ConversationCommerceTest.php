<?php

require_once __DIR__.'/CommerceTestHelpers.php';

use App\Domains\Commerce\Models\ShopifySnapshot;
use App\Domains\Commerce\Models\TrackingSnapshot;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;

test('the panel reports nothing configured when Shopify is not connected', function () {
    $f = commerceTCase();
    Http::fake();

    $this->actingAs($f['agent'])
        ->getJson("/commerce/conversations/{$f['conversation']->id}")
        ->assertOk()
        ->assertJson(['configured' => ['shopify' => false, 'tracking' => false], 'shopify' => null, 'tracking' => null]);

    Http::assertNothingSent();
});

test('opening the panel shows stored snapshots and never calls Shopify', function () {
    $f = commerceTCase();
    commerceTEnableShopify($f['workspace']->id);
    Http::fake();

    $this->actingAs($f['agent'])
        ->getJson("/commerce/conversations/{$f['conversation']->id}")
        ->assertOk()
        ->assertJson(['configured' => ['shopify' => true, 'tracking' => false], 'shopify' => null]);

    Http::assertNothingSent();
});

test('refreshing Shopify shows the order and that it was matched by the order number in the mail', function () {
    $f = commerceTCase();
    commerceTEnableShopify($f['workspace']->id);
    Http::fake(['https://test-shop.myshopify.com/admin/api/*' => Http::response(commerceTShopifyPayload())]);

    $this->actingAs($f['agent'])
        ->postJson("/commerce/conversations/{$f['conversation']->id}/refresh-shopify")
        ->assertOk()
        ->assertJsonPath('shopify.has_order', true)
        ->assertJsonPath('shopify.order_number', 'SD8421')
        ->assertJsonPath('shopify.matched_by', 'order_number')
        ->assertJsonPath('shopify.order_email_matches', true)
        ->assertJsonPath('shopify.fulfilment_status', 'FULFILLED')
        ->assertJsonPath('shopify.tracking_number', '4PX00112233')
        ->assertJsonPath('shopify.products.0.title', 'Arcade Cabinet')
        ->assertJsonPath('shopify.order_admin_url', 'https://test-shop.myshopify.com/admin/orders/99')
        ->assertJsonPath('shopify.customer_admin_url', 'https://test-shop.myshopify.com/admin/customers/1');

    expect(ShopifySnapshot::where('conversation_id', $f['conversation']->id)->count())->toBe(1);
});

test('an order found by the sender email is reported as matched by email', function () {
    $f = commerceTCase('Hi, has my parcel shipped yet?');
    commerceTEnableShopify($f['workspace']->id);
    Http::fake(['https://test-shop.myshopify.com/admin/api/*' => Http::response(commerceTShopifyPayload())]);

    $this->actingAs($f['agent'])
        ->postJson("/commerce/conversations/{$f['conversation']->id}/refresh-shopify")
        ->assertOk()
        ->assertJsonPath('shopify.matched_by', 'email');
});

test('an order whose email is not the sender is flagged', function () {
    $f = commerceTCase();
    commerceTEnableShopify($f['workspace']->id);
    Http::fake(['https://test-shop.myshopify.com/admin/api/*' => Http::response(commerceTShopifyPayload('someone.else@example.com'))]);

    $this->actingAs($f['agent'])
        ->postJson("/commerce/conversations/{$f['conversation']->id}/refresh-shopify")
        ->assertOk()
        ->assertJsonPath('shopify.matched_by', 'order_number')
        ->assertJsonPath('shopify.order_email_matches', false);
});

test('no order for the customer is a clear state', function () {
    $f = commerceTCase('Hello there');
    commerceTEnableShopify($f['workspace']->id);
    Http::fake(['https://test-shop.myshopify.com/admin/api/*' => Http::response([
        'data' => ['customers' => ['edges' => []], 'orders' => ['edges' => []]],
    ])]);

    $this->actingAs($f['agent'])
        ->postJson("/commerce/conversations/{$f['conversation']->id}/refresh-shopify")
        ->assertOk()
        ->assertJsonPath('shopify.found', false)
        ->assertJsonPath('shopify.has_order', false)
        ->assertJsonPath('shopify.matched_by', null)
        ->assertJsonPath('shopify.order_admin_url', null)
        ->assertJsonPath('shopify.customer_admin_url', null)
        ->assertJsonPath('shopify.reason', 'No Shopify customer matched this email.');
});

test('refreshing tracking forces a carrier read and reports days since the last scan', function () {
    $f = commerceTCase();
    commerceTEnableTrack123($f['workspace']->id);
    ShopifySnapshot::create([
        'workspace_id' => $f['workspace']->id,
        'conversation_id' => $f['conversation']->id,
        'customer_email' => 'buyer@example.com',
        'shopify_order_id' => 'gid://shopify/Order/99',
        'order_number' => 'SD8421',
        'payload' => [
            'found' => true, 'order_number' => 'SD8421', 'order_email' => 'buyer@example.com',
            'shopify_order_id' => 'gid://shopify/Order/99', 'tracking_number' => '4PX00112233', 'tracking_company' => '4PX',
        ],
        'fetched_at' => now(),
    ]);
    Http::fake(['https://shp.track123.com/*' => Http::response(commerceTTrack123Payload(20))]);

    $this->actingAs($f['agent'])
        ->postJson("/commerce/conversations/{$f['conversation']->id}/refresh-tracking")
        ->assertOk()
        ->assertJsonPath('configured.tracking', true)
        ->assertJsonPath('tracking.found', true)
        ->assertJsonPath('tracking.carrier_name', '4PX Express')
        ->assertJsonPath('tracking.days_since_last_scan', 20)
        ->assertJsonPath('tracking.stalled', true)
        ->assertJsonPath('stale_days', 14);

    // A second refresh calls the carrier again (forced, not the cached reading).
    $this->actingAs($f['agent'])->postJson("/commerce/conversations/{$f['conversation']->id}/refresh-tracking")->assertOk();
    expect(TrackingSnapshot::where('conversation_id', $f['conversation']->id)->count())->toBe(2);
});

test('the panel is limited to people who can see the conversation', function () {
    $f = commerceTCase();
    commerceTEnableShopify($f['workspace']->id);
    $outsider = User::factory()->create(['workspace_id' => Workspace::factory()->create()->id, 'role' => 'admin']);
    Http::fake();

    $this->getJson("/commerce/conversations/{$f['conversation']->id}")->assertUnauthorized();
    $this->actingAs($outsider)->getJson("/commerce/conversations/{$f['conversation']->id}")->assertForbidden();
    $this->actingAs($outsider)->postJson("/commerce/conversations/{$f['conversation']->id}/refresh-shopify")->assertForbidden();
    Http::assertNothingSent();
});
