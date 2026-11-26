<?php

require_once __DIR__.'/CommerceTestHelpers.php';

use App\Domains\Commerce\Models\CommerceSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

// Ported from the Commerce Assist module settings tests
// (tests/Feature/Modules/CommerceAssistTest.php) onto /agent/connections.

dataset('connection routes', [
    'page' => ['get', '/agent/connections'],
    'save' => ['patch', '/agent/connections'],
    'test shopify' => ['post', '/agent/connections/test-shopify'],
    'test tracking' => ['post', '/agent/connections/test-tracking'],
]);

test('connection routes need login', function (string $method, string $url) {
    $this->{$method}($url)->assertRedirect('/login');
})->with('connection routes');

test('connection routes are admin only', function (string $method, string $url) {
    $f = commerceTCase();

    $this->actingAs($f['agent'])->{$method.'Json'}($url, ['tracking_stale_days' => 10])->assertForbidden();
})->with('connection routes');

test('the page never sends stored secrets and never calls Shopify on load', function () {
    $f = commerceTCase();
    commerceTEnableTrack123($f['workspace']->id);
    Http::fake();

    $response = $this->actingAs($f['admin'])
        ->get('/agent/connections')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Agent/Connections')
            ->where('settings.shopify_configured', true)
            ->where('settings.has_shopify_client_secret', true)
            ->where('settings.has_tracking_api_key', true)
            ->where('settings.tracking_configured', true)
            ->where('settings.shopify_client_id', '11111111111111111111111111111111')
            ->where('settings.shopify_resolved_domain', 'test-shop.myshopify.com')
            ->where('settings.shopify_granted_scopes', ['read_orders', 'read_customers', 'read_products'])
            ->where('settings.tracking_store_uuid_default', 'test-shop')
            ->missing('settings.shopify_client_secret')
            ->missing('settings.shopify_access_token')
            ->missing('settings.tracking_api_key')
            ->missing('settings.example_edit_threshold')
            ->missing('settings.draft_only')
            ->has('providers'));

    expect($response->getContent())
        ->not->toContain('shpss_secret')
        ->not->toContain('shpca_test')
        ->not->toContain('t123_secret');
    Http::assertNothingSent();
});

test('admins can save Shopify credentials and a token is requested', function () {
    $f = commerceTCase();
    Http::fake([
        'https://demo.myshopify.com/admin/oauth/access_token' => Http::response([
            'access_token' => 'shpca_live',
            'scope' => 'read_orders,read_customers',
            'expires_in' => 86399,
        ]),
    ]);

    $this->actingAs($f['admin'])
        ->patch('/agent/connections', [
            'shopify_shop_domain' => 'https://Demo.myshopify.com/',
            'shopify_client_id' => '11111111111111111111111111111111',
            'shopify_client_secret' => 'shpss_secret',
            'shopify_api_version' => '2025-01',
            'tracking_stale_days' => 10,
            'preorder_tags' => 'Preorder, pre-order, preorder',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $settings = CommerceSetting::forWorkspace($f['workspace']->id);
    expect($settings->shopify_shop_domain)->toBe('demo.myshopify.com')
        ->and($settings->shopify_client_id)->toBe('11111111111111111111111111111111')
        ->and($settings->tracking_stale_days)->toBe(10)
        ->and($settings->preorder_tags)->toBe(['preorder', 'pre-order'])
        ->and($settings->decryptClientSecret())->toBe('shpss_secret')
        ->and($settings->decryptAccessToken())->toBe('shpca_live')
        ->and($settings->shopify_granted_scopes)->toBe('read_orders,read_customers')
        ->and($settings->shopify_access_token_expires_at)->not->toBeNull();
});

test('a blank secret keeps the stored one and does not request a new token', function () {
    $f = commerceTCase();
    commerceTEnableShopify($f['workspace']->id);
    Http::fake();

    $this->actingAs($f['admin'])
        ->patch('/agent/connections', [
            'shopify_shop_domain' => 'test-shop.myshopify.com',
            'shopify_client_id' => '11111111111111111111111111111111',
            'shopify_client_secret' => '',
            'tracking_stale_days' => 21,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $settings = CommerceSetting::forWorkspace($f['workspace']->id);
    expect($settings->decryptClientSecret())->toBe('shpss_secret')
        ->and($settings->decryptAccessToken())->toBe('shpca_test')
        ->and($settings->tracking_stale_days)->toBe(21);
    Http::assertNothingSent();
});

test('a failed token request still saves and reports the error', function () {
    $f = commerceTCase();
    Http::fake([
        'https://demo.myshopify.com/admin/oauth/access_token' => Http::response(['error' => 'invalid_client'], 401),
    ]);

    $this->actingAs($f['admin'])
        ->patch('/agent/connections', [
            'shopify_shop_domain' => 'demo.myshopify.com',
            'shopify_client_id' => 'abc',
            'shopify_client_secret' => 'wrong',
            'tracking_stale_days' => 14,
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(CommerceSetting::forWorkspace($f['workspace']->id)->decryptClientSecret())->toBe('wrong');
});

test('admins can select a tracking provider and the key is stored encrypted', function () {
    $f = commerceTCase();

    $this->actingAs($f['admin'])
        ->patch('/agent/connections', [
            'shopify_shop_domain' => 'demo.myshopify.com',
            'tracking_provider' => 'track123',
            'tracking_api_key' => 't123_secret',
            'tracking_stale_days' => 10,
        ])
        ->assertRedirect();

    $settings = CommerceSetting::forWorkspace($f['workspace']->id);
    expect($settings->tracking_provider)->toBe('track123')
        ->and($settings->tracking_api_key)->not->toBe('t123_secret')
        ->and($settings->decryptTrackingApiKey())->toBe('t123_secret')
        ->and($settings->trackingEnabled())->toBeTrue()
        ->and($settings->trackingStoreUuid())->toBe('demo');
});

test('switching the tracking provider off clears the stored key', function () {
    $f = commerceTCase();
    commerceTEnableTrack123($f['workspace']->id);

    $this->actingAs($f['admin'])
        ->patch('/agent/connections', [
            'shopify_shop_domain' => 'test-shop.myshopify.com',
            'tracking_provider' => 'none',
            'tracking_stale_days' => 10,
        ])
        ->assertRedirect();

    $settings = CommerceSetting::forWorkspace($f['workspace']->id);
    expect($settings->tracking_api_key)->toBeNull()
        ->and($settings->trackingEnabled())->toBeFalse();
});

test('omitting the provider keeps the current one', function () {
    $f = commerceTCase();
    commerceTEnableTrack123($f['workspace']->id);

    $this->actingAs($f['admin'])
        ->patch('/agent/connections', ['shopify_shop_domain' => 'test-shop.myshopify.com', 'tracking_stale_days' => 10])
        ->assertRedirect();

    expect(CommerceSetting::forWorkspace($f['workspace']->id)->trackingEnabled())->toBeTrue();
});

test('invalid settings are rejected with field errors', function () {
    $f = commerceTCase();

    $this->actingAs($f['admin'])
        ->patch('/agent/connections', [
            'shopify_shop_domain' => 'not a domain',
            'shopify_api_version' => 'latest',
            'tracking_provider' => 'definitely-not-a-provider',
            'tracking_stale_days' => 0,
        ])
        ->assertSessionHasErrors(['shopify_shop_domain', 'shopify_api_version', 'tracking_provider', 'tracking_stale_days']);
});

test('shopify test-connection returns the shop and granted scopes', function () {
    $f = commerceTCase();
    commerceTEnableShopify($f['workspace']->id, ['shopify_access_token' => null, 'shopify_access_token_expires_at' => null]);
    Http::fake([
        'https://test-shop.myshopify.com/admin/oauth/access_token' => Http::response([
            'access_token' => 'shpca_test',
            'scope' => 'read_orders,read_customers,read_products',
            'expires_in' => 86399,
        ]),
        'https://test-shop.myshopify.com/admin/api/*' => Http::response([
            'data' => ['shop' => [
                'name' => 'The Soul Dial',
                'myshopifyDomain' => 'test-shop.myshopify.com',
                'email' => 'owner@example.com',
                'plan' => ['displayName' => 'Basic'],
            ]],
        ]),
    ]);

    $this->actingAs($f['admin'])
        ->postJson('/agent/connections/test-shopify')
        ->assertOk()
        ->assertJson([
            'ok' => true,
            'message' => 'Connected to The Soul Dial (test-shop.myshopify.com).',
            'shop' => ['name' => 'The Soul Dial', 'domain' => 'test-shop.myshopify.com'],
            'scopes' => ['read_orders', 'read_customers', 'read_products'],
        ]);
});

test('shopify test-connection fails when no credentials are saved', function () {
    $f = commerceTCase();

    $this->actingAs($f['admin'])
        ->postJson('/agent/connections/test-shopify')
        ->assertOk()
        ->assertJson(['ok' => false, 'message' => 'Save a shop domain, client ID, and client secret first.']);
});

test('shopify test-connection reports rejected client credentials', function () {
    $f = commerceTCase();
    commerceTEnableShopify($f['workspace']->id, ['shopify_access_token' => null, 'shopify_access_token_expires_at' => null]);
    Http::fake([
        'https://test-shop.myshopify.com/admin/oauth/access_token' => Http::response([
            'error' => 'invalid_client',
            'error_description' => 'Invalid client',
        ], 401),
    ]);

    $response = $this->actingAs($f['admin'])
        ->postJson('/agent/connections/test-shopify')
        ->assertOk()
        ->assertJson(['ok' => false]);

    expect($response->json('message'))->toContain('rejected the client ID or secret');
});

test('tracking test-connection treats a track123 404 as a valid key', function () {
    $f = commerceTCase();
    commerceTEnableTrack123($f['workspace']->id);
    Http::fake(['https://shp.track123.com/*' => Http::response(null, 404)]);

    $this->actingAs($f['admin'])
        ->postJson('/agent/connections/test-tracking')
        ->assertOk()
        ->assertJson(['ok' => true, 'provider' => 'track123', 'message' => 'Connected to Track123 for test-shop.']);
});

test('tracking test-connection reports a rejected track123 key', function () {
    $f = commerceTCase();
    commerceTEnableTrack123($f['workspace']->id);
    Http::fake(['https://shp.track123.com/*' => Http::response(['error' => 'Unauthorized'], 401)]);

    $response = $this->actingAs($f['admin'])
        ->postJson('/agent/connections/test-tracking')
        ->assertOk()
        ->assertJson(['ok' => false]);

    expect($response->json('message'))->toContain('rejected the API key');
});

test('tracking test-connection fails when tracking is switched off', function () {
    $f = commerceTCase();

    $this->actingAs($f['admin'])
        ->postJson('/agent/connections/test-tracking')
        ->assertOk()
        ->assertJson(['ok' => false, 'message' => 'Carrier tracking is switched off. Choose a provider and save an API key first.']);
});

test('tracking test-connection fails when no api key is saved', function () {
    $f = commerceTCase();
    CommerceSetting::forWorkspace($f['workspace']->id)->update([
        'tracking_provider' => 'track123',
        'shopify_shop_domain' => 'test-shop.myshopify.com',
    ]);

    $this->actingAs($f['admin'])
        ->postJson('/agent/connections/test-tracking')
        ->assertOk()
        ->assertJson(['ok' => false, 'message' => 'Save a tracking API key first.']);
});

test('tracking test-connection accepts an aftership courier list as a valid key', function () {
    $f = commerceTCase();
    commerceTEnableShopify($f['workspace']->id, [
        'tracking_provider' => 'aftership',
        'tracking_api_key' => Crypt::encryptString('aftership_secret'),
    ]);
    Http::fake([
        'https://api.aftership.com/*' => Http::response(['data' => ['couriers' => [['slug' => 'fedex'], ['slug' => 'ups']]]]),
    ]);

    $this->actingAs($f['admin'])
        ->postJson('/agent/connections/test-tracking')
        ->assertOk()
        ->assertJson(['ok' => true, 'provider' => 'aftership', 'message' => 'Connected to AfterShip (2 couriers available).']);
});

test('connections work without the Commerce Assist module being active', function () {
    $f = commerceTCase();

    // No Module row for CommerceAssist exists in this test.
    $this->actingAs($f['admin'])->get('/agent/connections')->assertOk();
});
