<?php

use App\Domains\Commerce\Models\CommerceSetting;
use App\Domains\Commerce\Models\ShopifySnapshot;
use App\Domains\Commerce\Models\TrackingSnapshot;
use App\Domains\Commerce\Services\ShopifyAccessTokenService;
use App\Domains\Commerce\Services\ShopifyLookupService;
use App\Domains\Commerce\Services\TrackingLookupService;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Customer\Models\Customer;
use App\Domains\Mailbox\Models\Mailbox;
use App\Models\Workspace;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/*
 * Shopify lookup, access tokens and carrier tracking (app/Domains/Commerce).
 * Ported from the deleted Modules/CommerceAssist test suite: this behaviour
 * is live and used by the agent pipeline's evidence step and the order panel.
 */

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    $this->mailbox = Mailbox::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->customer = Customer::factory()->create([
        'workspace_id' => $this->workspace->id,
        'email' => 'buyer@example.com',
        'name' => 'Alex Buyer',
    ]);
    $this->conversation = Conversation::factory()->create([
        'workspace_id' => $this->workspace->id,
        'mailbox_id' => $this->mailbox->id,
        'customer_id' => $this->customer->id,
        'subject' => 'Where is order SD8421?',
    ]);
    Thread::factory()->create([
        'conversation_id' => $this->conversation->id,
        'customer_id' => $this->customer->id,
        'user_id' => null,
        'body' => '<p>Hi, where is order SD8421? Tracking has not updated.</p>',
        'body_plain' => 'Hi, where is order SD8421? Tracking has not updated.',
    ]);
});

function cmsTShopifyOrderPayload(): array
{
    return [
        'data' => [
            'customers' => [
                'edges' => [[
                    'node' => [
                        'id' => 'gid://shopify/Customer/1',
                        'firstName' => 'Alex',
                        'lastName' => 'Buyer',
                        'email' => 'buyer@example.com',
                        'tags' => ['vip'],
                        'note' => 'Prefers email',
                        'defaultAddress' => ['country' => 'United States', 'countryCodeV2' => 'US'],
                    ],
                ]],
            ],
            'orders' => [
                'edges' => [[
                    'node' => [
                        'id' => 'gid://shopify/Order/99',
                        'name' => 'SD8421',
                        'createdAt' => now()->subDays(3)->toIso8601String(),
                        'displayFinancialStatus' => 'PAID',
                        'displayFulfillmentStatus' => 'UNFULFILLED',
                        'cancelledAt' => null,
                        'tags' => ['preorder'],
                        'note' => 'Hold for bundle',
                        'shippingAddress' => ['country' => 'United States', 'countryCodeV2' => 'US'],
                        'fulfillments' => [],
                        'refunds' => [],
                        'lineItems' => [
                            'edges' => [[
                                'node' => [
                                    'title' => 'Arcade Cabinet',
                                    'quantity' => 1,
                                    'variantTitle' => 'Black',
                                    'sku' => 'CAB-PREORDER',
                                    'customAttributes' => [['key' => 'preorder', 'value' => 'true']],
                                ],
                            ]],
                        ],
                    ],
                ]],
            ],
        ],
    ];
}

function cmsTEnableShopify(array $overrides = []): void
{
    CommerceSetting::forWorkspace(test()->workspace->id)->update(array_merge([
        'shopify_shop_domain' => 'test-shop.myshopify.com',
        'shopify_client_id' => '11111111111111111111111111111111',
        'shopify_client_secret' => Crypt::encryptString('shpss_secret'),
        'shopify_access_token' => Crypt::encryptString('shpca_test'),
        'shopify_access_token_expires_at' => now()->addHours(23),
        'shopify_granted_scopes' => 'read_orders,read_customers,read_products',
    ], $overrides));
}

function cmsTEnableTrack123(): void
{
    cmsTEnableShopify([
        'tracking_provider' => 'track123',
        'tracking_api_key' => Crypt::encryptString('t123_secret'),
    ]);
}

function cmsTSnapshotWithTracking(array $overrides = []): ShopifySnapshot
{
    return ShopifySnapshot::create([
        'workspace_id' => test()->workspace->id,
        'conversation_id' => test()->conversation->id,
        'customer_email' => 'buyer@example.com',
        'shopify_order_id' => 'gid://shopify/Order/99',
        'order_number' => 'SD8421',
        'payload' => array_merge([
            'found' => true,
            'order_number' => 'SD8421',
            'shopify_order_id' => 'gid://shopify/Order/99',
            'tracking_number' => '4PX00112233',
            'tracking_company' => '4PX',
            'shipping_country' => 'Australia',
        ], $overrides),
        'fetched_at' => now(),
    ]);
}

/** Mirrors the Track123 Shopify App API order payload. */
function cmsTTrack123OrderPayload(array $fulfillment = []): array
{
    return [
        'order_name' => '#SD8421',
        'order_number' => 8421,
        'order_id' => 99,
        'status' => 'open',
        'tracking_link' => 'https://track.example.test/SD8421',
        'fulfillments' => [array_merge([
            'tracking_company' => '4PX',
            'tracking_number' => '4PX00112233',
            'carrier_code' => '4px',
            'transit_status' => 'Delivered',
            'transit_sub_status' => 'Sign by customer',
            'last_event' => 'Delivered, signed by customer',
            'last_event_time' => now()->subDays(2)->toIso8601String(),
            'courier' => [
                'code' => '4px',
                'name' => '4PX Express',
                'query_link' => 'https://track.4px.com',
            ],
            'tracking_details' => [[
                'event_time' => now()->subDays(2)->toIso8601String(),
                'event_time_utc' => now()->subDays(2)->toIso8601String(),
                'event_detail' => 'Delivered, signed by customer',
                'event_location' => 'Sydney NSW',
            ]],
            'last_mile_info' => [
                'lm_track_no' => 'AP99887766',
                'lm_track_no_provider_code' => 'auspost',
                'lm_track_no_provider_name' => 'Australia Post',
                'details' => [[
                    'event_time_utc' => now()->subDays(3)->toIso8601String(),
                    'event_detail' => 'Out for delivery',
                    'event_location' => 'Sydney NSW',
                ]],
            ],
        ], $fulfillment)],
    ];
}

test('extracts order numbers from customer email text', function () {
    $numbers = app(ShopifyLookupService::class)->extractOrderNumbers('Where is #SD8421 and order 12345?');

    expect($numbers)->toContain('SD8421')->toContain('12345');
});

test('shopify lookup stores verified order facts and does not invent tracking', function () {
    cmsTEnableShopify();

    Http::fake([
        'https://test-shop.myshopify.com/admin/api/*' => Http::response(cmsTShopifyOrderPayload()),
    ]);

    $snapshot = app(ShopifyLookupService::class)->lookup($this->conversation->load(['customer', 'threads']));

    expect($snapshot->found())->toBeTrue()
        ->and($snapshot->order_number)->toBe('SD8421')
        ->and($snapshot->payload['fulfilment_status'])->toBe('UNFULFILLED')
        ->and($snapshot->payload['payment_status'])->toBe('PAID')
        ->and($snapshot->payload['tracking_number'])->toBeNull()
        ->and($snapshot->payload['products'][0]['title'])->toBe('Arcade Cabinet')
        ->and($snapshot->payload['products'][0]['preorder'])->toBeTrue();
});

test('shopify lookup without credentials records not found', function () {
    $snapshot = app(ShopifyLookupService::class)->lookup($this->conversation->load(['customer', 'threads']));

    expect($snapshot->found())->toBeFalse()
        ->and($snapshot->payload['reason'])->toContain('not configured');
});

test('shopify reuses a cached access token until it is near expiry', function () {
    cmsTEnableShopify();
    Http::fake();

    $token = app(ShopifyAccessTokenService::class)
        ->tokenFor(CommerceSetting::forWorkspace($this->workspace->id));

    expect($token)->toBe('shpca_test');
    Http::assertNothingSent();
});

test('shopify requests a new access token when the cached one has expired', function () {
    cmsTEnableShopify(['shopify_access_token_expires_at' => now()->subMinute()]);

    Http::fake([
        'https://test-shop.myshopify.com/admin/oauth/access_token' => Http::response([
            'access_token' => 'shpca_rotated',
            'scope' => 'read_orders',
            'expires_in' => 86399,
        ]),
    ]);

    $token = app(ShopifyAccessTokenService::class)
        ->tokenFor(CommerceSetting::forWorkspace($this->workspace->id));

    expect($token)->toBe('shpca_rotated')
        ->and(CommerceSetting::forWorkspace($this->workspace->id)->decryptAccessToken())->toBe('shpca_rotated');
});

test('the refresh command renews tokens that expire within two hours', function () {
    cmsTEnableShopify(['shopify_access_token_expires_at' => now()->addMinutes(30)]);

    Http::fake([
        'https://test-shop.myshopify.com/admin/oauth/access_token' => Http::response([
            'access_token' => 'shpca_scheduled',
            'scope' => 'read_orders',
            'expires_in' => 86399,
        ]),
    ]);

    $this->artisan('commerce:refresh-shopify-tokens')->assertSuccessful();

    expect(CommerceSetting::forWorkspace($this->workspace->id)->decryptAccessToken())->toBe('shpca_scheduled');
});

test('the refresh command skips a token that is still fresh', function () {
    cmsTEnableShopify();
    Http::fake();

    $this->artisan('commerce:refresh-shopify-tokens')->assertSuccessful();

    Http::assertNothingSent();
    expect(CommerceSetting::forWorkspace($this->workspace->id)->decryptAccessToken())->toBe('shpca_test');
});

test('track123 lookup normalises status, last mile handoff and proof of delivery', function () {
    cmsTEnableTrack123();
    Http::fake(['https://shp.track123.com/*' => Http::response(cmsTTrack123OrderPayload())]);

    $tracking = app(TrackingLookupService::class)->lookup(
        $this->conversation->load(['customer', 'threads']),
        cmsTSnapshotWithTracking(),
    );

    expect($tracking)->not->toBeNull()
        ->and($tracking->found())->toBeTrue()
        ->and($tracking->status)->toBe('delivered')
        ->and($tracking->carrier_name)->toBe('4PX Express')
        ->and($tracking->last_mile_carrier)->toBe('Australia Post')
        ->and($tracking->last_mile_tracking_number)->toBe('AP99887766')
        ->and($tracking->days_since_last_scan)->toBe(2)
        ->and($tracking->proof()['type'])->toBe('signature')
        ->and($tracking->proofIsAttributable())->toBeTrue()
        // Both legs of the journey are kept, flagged by which carrier scanned.
        ->and(collect($tracking->payload['events'])->pluck('last_mile')->all())->toContain(true, false);
});

test('track123 uses the numeric shopify order id rather than the graphql gid', function () {
    cmsTEnableTrack123();
    Http::fake(['https://shp.track123.com/*' => Http::response(cmsTTrack123OrderPayload())]);

    app(TrackingLookupService::class)->lookup(
        $this->conversation->load(['customer', 'threads']),
        cmsTSnapshotWithTracking(),
    );

    Http::assertSent(fn ($request) => str_contains($request->url(), '/test-shop/orders/99.json')
        && $request->hasHeader('X-Api-Key', 't123_secret'));
});

test('carrier lookup is skipped entirely when no provider is configured', function () {
    $tracking = app(TrackingLookupService::class)->lookup(
        $this->conversation->load(['customer', 'threads']),
        cmsTSnapshotWithTracking(),
    );

    expect($tracking)->toBeNull();
});

test('a stored carrier reading is reused inside the freshness window', function () {
    cmsTEnableTrack123();
    Http::fake(['https://shp.track123.com/*' => Http::response(cmsTTrack123OrderPayload())]);

    $conversation = $this->conversation->load(['customer', 'threads']);
    $snapshot = cmsTSnapshotWithTracking();

    $first = app(TrackingLookupService::class)->lookup($conversation, $snapshot);
    $second = app(TrackingLookupService::class)->lookup($conversation, $snapshot);

    expect($second->id)->toBe($first->id)
        ->and(TrackingSnapshot::count())->toBe(1);

    $forced = app(TrackingLookupService::class)->lookup($conversation, $snapshot, force: true);

    expect($forced->id)->not->toBe($first->id);
});

test('carrier lookup failure is recorded without breaking the snapshot', function () {
    cmsTEnableTrack123();
    Http::fake(['https://shp.track123.com/*' => Http::response('boom', 500)]);

    $tracking = app(TrackingLookupService::class)->lookup(
        $this->conversation->load(['customer', 'threads']),
        cmsTSnapshotWithTracking(),
    );

    expect($tracking->found())->toBeFalse()
        ->and($tracking->error)->not->toBeNull();
});
