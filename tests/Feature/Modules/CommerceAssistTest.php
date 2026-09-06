<?php

use App\Domains\AI\Jobs\GenerateReplySuggestionJob;
use App\Domains\AI\Models\KbDocument;
use App\Domains\AI\Models\KnowledgeBase;
use App\Domains\AI\Models\Module;
use App\Domains\Conversation\Models\AiSuggestion;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Customer\Models\Customer;
use App\Domains\Mailbox\Models\Mailbox;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Hooks;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Modules\CommerceAssist\Agents\ClassifyIntentAgent;
use Modules\CommerceAssist\Agents\ValidateFactsAgent;
use Modules\CommerceAssist\Agents\WriteReplyAgent;
use Modules\CommerceAssist\Jobs\IndexApprovedResponseJob;
use Modules\CommerceAssist\Jobs\RefreshDraftsForFactJob;
use Modules\CommerceAssist\Models\ApprovedResponse;
use Modules\CommerceAssist\Models\CommerceIntent;
use Modules\CommerceAssist\Models\CommerceSetting;
use Modules\CommerceAssist\Models\Generation;
use Modules\CommerceAssist\Models\LiveFact;
use Modules\CommerceAssist\Models\ReplayRun;
use Modules\CommerceAssist\Models\ShopifySnapshot;
use Modules\CommerceAssist\Models\TrackingSnapshot;
use Modules\CommerceAssist\Providers\CommerceAssistServiceProvider;
use Modules\CommerceAssist\Services\ConfidenceScorer;
use Modules\CommerceAssist\Services\ContextBuilder;
use Modules\CommerceAssist\Services\EditLearningService;
use Modules\CommerceAssist\Services\ExampleService;
use Modules\CommerceAssist\Services\GenerationPipeline;
use Modules\CommerceAssist\Services\IntentCatalog;
use Modules\CommerceAssist\Services\LiveFactService;
use Modules\CommerceAssist\Services\ShopifyLookupService;
use Modules\CommerceAssist\Services\TrackingLookupService;
use Modules\CommerceAssist\Support\PlainText;

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    $this->mailbox = Mailbox::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->customer = Customer::factory()->create([
        'workspace_id' => $this->workspace->id,
        'email' => 'buyer@example.com',
        'name' => 'Alex Buyer',
    ]);
    $this->admin = User::factory()->create([
        'workspace_id' => $this->workspace->id,
        'role' => 'admin',
    ]);

    Module::create([
        'alias' => 'CommerceAssist',
        'name' => 'Commerce Assist',
        'active' => true,
        'version' => '1.0.0',
        'config' => [],
    ]);
    Cache::forget('module.active.CommerceAssist');

    app()->register(CommerceAssistServiceProvider::class, true);

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

afterEach(function () {
    Hooks::reset();
});

function fakeCommerceAgents(array $claims = []): void
{
    ClassifyIntentAgent::fake([
        new StructuredAgentResponse(
            invocationId: 'classify',
            structured: [
                'intent' => 'tracking_problem',
                'subtype' => 'tracking.no_updates',
                'sentiment' => 'frustrated',
                'country' => 'US',
            ],
            text: '{}',
            usage: new Usage,
            meta: new Meta('anthropic', 'claude-haiku-4-5-20251001'),
        ),
    ]);

    WriteReplyAgent::fake(['Your order SD8421 is unfulfilled. I do not have a dispatch date.']);

    ValidateFactsAgent::fake([
        new StructuredAgentResponse(
            invocationId: 'validate',
            structured: ['unsupported_claims' => $claims],
            text: '{}',
            usage: new Usage,
            meta: new Meta('anthropic', 'claude-haiku-4-5-20251001'),
        ),
    ]);
}

function shopifyOrderPayload(): array
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

test('extracts order numbers from customer email text', function () {
    $numbers = app(ShopifyLookupService::class)->extractOrderNumbers('Where is #SD8421 and order 12345?');

    expect($numbers)->toContain('SD8421')->toContain('12345');
});

test('shopify lookup stores verified order facts and does not invent tracking', function () {
    enableShopify();

    Http::fake([
        'https://test-shop.myshopify.com/admin/api/*' => Http::response(shopifyOrderPayload()),
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

test('context builder separates knowledge from approved examples and forbids copying facts', function () {
    IntentCatalog::ensureForWorkspace($this->workspace->id);
    $intent = CommerceIntent::where('workspace_id', $this->workspace->id)->where('slug', 'tracking_problem')->first();

    $snapshot = new ShopifySnapshot([
        'payload' => [
            'found' => true,
            'order_number' => 'SD8421',
            'fulfilment_status' => 'UNFULFILLED',
            'tracking_number' => null,
            'products' => [],
        ],
    ]);

    $example = new ApprovedResponse([
        'customer_message' => 'Where is SD1000?',
        'final_reply' => 'SD1000 shipped yesterday with tracking 1Z999.',
        'intent' => 'tracking_problem',
        'subtype' => 'tracking.no_updates',
    ]);
    // Assigned directly: `id` is not fillable, so passing it to the constructor
    // silently drops it and the source label renders as a bare "#".
    $example->id = 31;

    $kb = new KbDocument(['id' => 1, 'title' => 'US Duties Policy', 'content' => 'Customers pay import duties.']);

    $built = app(ContextBuilder::class)->build(
        $this->conversation->load('customer'),
        'Where is SD8421?',
        $snapshot,
        ['subtype' => 'tracking.no_updates'],
        $intent,
        $intent,
        collect([$example]),
        collect([$kb]),
        false,
        14,
    );

    expect($built['system'])
        ->toContain('VERIFIED SHOPIFY DATA')
        ->toContain('VERIFIED KNOWLEDGE BASE FACTS')
        ->toContain('RELEVANT APPROVED RESPONSE EXAMPLES')
        ->toContain('US Duties Policy')
        ->toContain('Never copy customer-specific facts from examples')
        ->toContain('SD8421')
        ->and($built['sources']['knowledge'])->toContain('US Duties Policy')
        ->and($built['sources']['examples'][0])->toContain('#31');
});

test('pipeline classifies, validates, stores a generation, and creates an AI suggestion', function () {
    fakeCommerceAgents();

    $generation = app(GenerationPipeline::class)->run($this->conversation->fresh(['customer', 'mailbox', 'threads']));

    expect($generation->intent)->toBe('tracking_problem')
        ->and($generation->subtype)->toBe('tracking.no_updates')
        ->and($generation->validator_passed)->toBeTrue()
        ->and($generation->safe_to_send)->toBeFalse()
        ->and($generation->ai_draft)->toContain('unfulfilled')
        ->and(AiSuggestion::where('conversation_id', $this->conversation->id)->exists())->toBeTrue();
});

test('validator flags unsupported claims and marks the generation flagged after a failed rewrite', function () {
    ClassifyIntentAgent::fake([
        new StructuredAgentResponse(
            invocationId: 'classify',
            structured: ['intent' => 'order_status', 'subtype' => 'order_status.unfulfilled', 'sentiment' => 'calm', 'country' => null],
            text: '{}',
            usage: new Usage,
            meta: new Meta('anthropic', 'claude-haiku-4-5-20251001'),
        ),
    ]);
    WriteReplyAgent::fake([
        'Your order should ship tomorrow.',
        'Your order should ship tomorrow.',
    ]);
    ValidateFactsAgent::fake([
        new StructuredAgentResponse(
            invocationId: 'v1',
            structured: ['unsupported_claims' => ['Your order should ship tomorrow.']],
            text: '{}',
            usage: new Usage,
            meta: new Meta('anthropic', 'claude-haiku-4-5-20251001'),
        ),
        new StructuredAgentResponse(
            invocationId: 'v2',
            structured: ['unsupported_claims' => ['Your order should ship tomorrow.']],
            text: '{}',
            usage: new Usage,
            meta: new Meta('anthropic', 'claude-haiku-4-5-20251001'),
        ),
    ]);

    $generation = app(GenerationPipeline::class)->run($this->conversation->fresh(['customer', 'mailbox', 'threads']));

    expect($generation->validator_passed)->toBeFalse()
        ->and($generation->status)->toBe('flagged')
        ->and($generation->unsupported_claims)->toContain('Your order should ship tomorrow.')
        ->and($generation->requires_human)->toBeTrue();
});

test('generate reply job is intercepted when the module is active', function () {
    fakeCommerceAgents();

    (new GenerateReplySuggestionJob($this->conversation))->handle();

    expect(Generation::where('conversation_id', $this->conversation->id)->exists())->toBeTrue()
        ->and(AiSuggestion::where('conversation_id', $this->conversation->id)->exists())->toBeTrue();
});

test('refund intents always require a human and stay draft-only', function () {
    ClassifyIntentAgent::fake([
        new StructuredAgentResponse(
            invocationId: 'classify',
            structured: ['intent' => 'refund_request', 'subtype' => 'refund.change_of_mind', 'sentiment' => 'calm', 'country' => null],
            text: '{}',
            usage: new Usage,
            meta: new Meta('anthropic', 'claude-haiku-4-5-20251001'),
        ),
    ]);
    WriteReplyAgent::fake(['I have passed this to the team to review your refund request.']);
    ValidateFactsAgent::fake([
        new StructuredAgentResponse(
            invocationId: 'validate',
            structured: ['unsupported_claims' => []],
            text: '{}',
            usage: new Usage,
            meta: new Meta('anthropic', 'claude-haiku-4-5-20251001'),
        ),
    ]);

    $generation = app(GenerationPipeline::class)->run($this->conversation->fresh(['customer', 'mailbox', 'threads']));

    expect($generation->intent)->toBe('refund_request')
        ->and($generation->requires_human)->toBeTrue()
        ->and($generation->safe_to_send)->toBeFalse();
});

test('sent replies are compared to the AI draft and nominated when the edit is large', function () {
    $generation = Generation::create([
        'workspace_id' => $this->workspace->id,
        'conversation_id' => $this->conversation->id,
        'ai_draft' => 'Your order is unfulfilled. I do not have a dispatch date.',
        'intent' => 'order_status',
        'status' => 'draft',
        'validator_passed' => true,
        'requires_human' => false,
        'safe_to_send' => false,
    ]);

    $reply = Thread::factory()->create([
        'conversation_id' => $this->conversation->id,
        'user_id' => $this->admin->id,
        'customer_id' => null,
        'body' => '<p>Totally different wording about a replacement we will send next week.</p>',
        'body_plain' => 'Totally different wording about a replacement we will send next week.',
    ]);

    $updated = app(EditLearningService::class)->captureSentReply($reply);

    expect($updated)->not->toBeNull()
        ->and($updated->status)->toBe('sent')
        ->and($updated->reply_thread_id)->toBe($reply->id)
        ->and($updated->percent_changed)->toBeGreaterThan(25)
        ->and($updated->nominated_as_example)->toBeTrue()
        ->and($updated->sent_unchanged)->toBeFalse();
});

test('use as AI example stores the customer message and final reply separately from the knowledge base', function () {
    Queue::fake();

    $reply = Thread::factory()->create([
        'conversation_id' => $this->conversation->id,
        'user_id' => $this->admin->id,
        'customer_id' => null,
        'body' => '<p>SD8421 is still being packed. Tracking appears after dispatch.</p>',
        'body_plain' => 'SD8421 is still being packed. Tracking appears after dispatch.',
    ]);

    $example = app(ExampleService::class)->fromThread($reply, $this->admin);

    expect($example->customer_message)->toContain('SD8421')
        ->and($example->final_reply)->toContain('still being packed')
        ->and($example->source)->toBe('manual');

    Queue::assertPushed(IndexApprovedResponseJob::class);
    expect(KbDocument::count())->toBe(0);
});

test('admins can save commerce assist settings', function () {
    Http::fake([
        'https://demo.myshopify.com/admin/oauth/access_token' => Http::response([
            'access_token' => 'shpca_live',
            'scope' => 'read_orders,read_customers',
            'expires_in' => 86399,
        ]),
    ]);

    $this->actingAs($this->admin)
        ->post('/settings/commerce-assist', [
            'shopify_shop_domain' => 'demo.myshopify.com',
            'shopify_client_id' => '11111111111111111111111111111111',
            'shopify_client_secret' => 'shpss_secret',
            'shopify_api_version' => '2025-01',
            'tracking_stale_days' => 10,
            'example_edit_threshold' => 30,
            'preorder_tags' => 'preorder, pre-order',
            'draft_only' => true,
        ])
        ->assertRedirect();

    $settings = CommerceSetting::forWorkspace($this->workspace->id);
    expect($settings->shopify_shop_domain)->toBe('demo.myshopify.com')
        ->and($settings->shopify_client_id)->toBe('11111111111111111111111111111111')
        ->and($settings->tracking_stale_days)->toBe(10)
        ->and($settings->decryptClientSecret())->toBe('shpss_secret')
        ->and($settings->decryptAccessToken())->toBe('shpca_live')
        ->and($settings->shopify_granted_scopes)->toBe('read_orders,read_customers')
        ->and($settings->shopify_access_token_expires_at)->not->toBeNull();
});

test('shopify test-connection returns shop name when credentials work', function () {
    enableShopify(['shopify_access_token' => null, 'shopify_access_token_expires_at' => null]);

    Http::fake([
        'https://test-shop.myshopify.com/admin/oauth/access_token' => Http::response([
            'access_token' => 'shpca_test',
            'scope' => 'read_orders,read_customers,read_products',
            'expires_in' => 86399,
        ]),
        'https://test-shop.myshopify.com/admin/api/*' => Http::response([
            'data' => [
                'shop' => [
                    'name' => 'The Soul Dial',
                    'myshopifyDomain' => 'test-shop.myshopify.com',
                    'email' => 'owner@example.com',
                    'plan' => ['displayName' => 'Basic'],
                ],
            ],
        ]),
    ]);

    $this->actingAs($this->admin)
        ->postJson('/settings/commerce-assist/test-shopify')
        ->assertOk()
        ->assertJson([
            'ok' => true,
            'shop' => [
                'name' => 'The Soul Dial',
                'domain' => 'test-shop.myshopify.com',
            ],
        ])
        ->assertJsonPath('message', 'Connected to The Soul Dial (test-shop.myshopify.com). Scopes: read_orders,read_customers,read_products.');
});

test('shopify test-connection fails when no credentials are saved', function () {
    $this->actingAs($this->admin)
        ->postJson('/settings/commerce-assist/test-shopify')
        ->assertOk()
        ->assertJson([
            'ok' => false,
            'message' => 'Save a shop domain, client ID, and client secret first.',
        ]);
});

test('shopify test-connection reports rejected client credentials', function () {
    enableShopify(['shopify_access_token' => null, 'shopify_access_token_expires_at' => null]);

    Http::fake([
        'https://test-shop.myshopify.com/admin/oauth/access_token' => Http::response([
            'error' => 'invalid_client',
            'error_description' => 'Invalid client',
        ], 401),
    ]);

    $response = $this->actingAs($this->admin)
        ->postJson('/settings/commerce-assist/test-shopify')
        ->assertOk()
        ->assertJson(['ok' => false]);

    expect($response->json('message'))->toContain('rejected the client ID or secret');
});

test('shopify test-connection is forbidden for non-admin users', function () {
    $agent = User::factory()->create([
        'workspace_id' => $this->workspace->id,
        'role' => 'agent',
    ]);

    $this->actingAs($agent)
        ->postJson('/settings/commerce-assist/test-shopify')
        ->assertForbidden();
});

test('commerce assist settings page reports whether shopify is configured', function () {
    enableShopify();

    $this->actingAs($this->admin)
        ->get('/settings/commerce-assist')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/CommerceAssist')
            ->where('settings.shopify_configured', true)
            ->where('settings.shopify_client_id', '11111111111111111111111111111111')
            ->where('settings.shopify_secret_set', true)
            ->where('settings.shopify_resolved_domain', 'test-shop.myshopify.com'));
});

test('shopify reuses a cached access token until it is near expiry', function () {
    enableShopify();
    Http::fake();

    $token = app(\Modules\CommerceAssist\Services\ShopifyAccessTokenService::class)
        ->tokenFor(CommerceSetting::forWorkspace($this->workspace->id));

    expect($token)->toBe('shpca_test');
    Http::assertNothingSent();
});

test('shopify requests a new access token when the cached one has expired', function () {
    enableShopify(['shopify_access_token_expires_at' => now()->subMinute()]);

    Http::fake([
        'https://test-shop.myshopify.com/admin/oauth/access_token' => Http::response([
            'access_token' => 'shpca_rotated',
            'scope' => 'read_orders',
            'expires_in' => 86399,
        ]),
    ]);

    $token = app(\Modules\CommerceAssist\Services\ShopifyAccessTokenService::class)
        ->tokenFor(CommerceSetting::forWorkspace($this->workspace->id));

    expect($token)->toBe('shpca_rotated')
        ->and(CommerceSetting::forWorkspace($this->workspace->id)->decryptAccessToken())->toBe('shpca_rotated');
});

test('the refresh command renews tokens that expire within two hours', function () {
    enableShopify(['shopify_access_token_expires_at' => now()->addMinutes(30)]);

    Http::fake([
        'https://test-shop.myshopify.com/admin/oauth/access_token' => Http::response([
            'access_token' => 'shpca_scheduled',
            'scope' => 'read_orders',
            'expires_in' => 86399,
        ]),
    ]);

    $this->artisan('commerce-assist:refresh-shopify-tokens')->assertSuccessful();

    expect(CommerceSetting::forWorkspace($this->workspace->id)->decryptAccessToken())->toBe('shpca_scheduled');
});

test('the refresh command skips a token that is still fresh', function () {
    enableShopify();
    Http::fake();

    $this->artisan('commerce-assist:refresh-shopify-tokens')->assertSuccessful();

    Http::assertNothingSent();
    expect(CommerceSetting::forWorkspace($this->workspace->id)->decryptAccessToken())->toBe('shpca_test');
});

test('admins can update intent rules', function () {
    IntentCatalog::ensureForWorkspace($this->workspace->id);
    $intent = CommerceIntent::where('workspace_id', $this->workspace->id)->where('slug', 'tracking.no_updates')->first();

    $this->actingAs($this->admin)
        ->put("/settings/commerce-assist/intents/{$intent->id}", [
            'rules' => 'Never say the parcel is lost.',
            'always_human' => false,
            'auto_send_allowed' => true,
        ])
        ->assertRedirect();

    expect($intent->fresh()->rules)->toContain('Never say the parcel is lost.');
});

test('replay stores original reply vs current AI reply without creating a live suggestion', function () {
    fakeCommerceAgents();

    Thread::factory()->create([
        'conversation_id' => $this->conversation->id,
        'user_id' => $this->admin->id,
        'customer_id' => null,
        'body' => '<p>Original Craig reply about tracking.</p>',
        'body_plain' => 'Original Craig reply about tracking.',
    ]);

    $this->actingAs($this->admin)
        ->post("/settings/commerce-assist/replay/{$this->conversation->id}")
        ->assertRedirect();

    $run = ReplayRun::first();
    expect($run)->not->toBeNull()
        ->and($run->original_reply)->toContain('Original Craig reply')
        ->and($run->current_ai_reply)->toContain('unfulfilled')
        ->and(Generation::where('status', 'replay')->exists())->toBeTrue();
});

test('plain text percent changed is zero for identical replies', function () {
    expect(PlainText::percentChanged('<p>Hello there</p>', 'Hello there'))->toBe(0);
});

test('knowledge base documents stay separate from approved responses', function () {
    $kb = KnowledgeBase::create(['workspace_id' => $this->workspace->id, 'name' => 'Policies', 'active' => true]);
    $kb->documents()->create(['title' => 'US Duties Policy', 'content' => 'Buyer pays duties.']);

    Queue::fake();
    ApprovedResponse::create([
        'workspace_id' => $this->workspace->id,
        'customer_message' => 'Do I pay duty?',
        'final_reply' => 'Yes, the buyer pays import duties in the US.',
        'intent' => 'duties_tariffs',
        'source' => 'manual',
    ]);

    expect(ApprovedResponse::count())->toBe(1)
        ->and(KbDocument::count())->toBe(1)
        ->and(ApprovedResponse::first()->customer_message)->not->toBe(KbDocument::first()->content);
});

function seedUnsentDraft(array $overrides = []): Conversation
{
    $customer = Customer::factory()->create([
        'workspace_id' => test()->workspace->id,
        'email' => fake()->unique()->safeEmail(),
    ]);

    $conversation = Conversation::factory()->create([
        'workspace_id' => test()->workspace->id,
        'mailbox_id' => test()->mailbox->id,
        'customer_id' => $customer->id,
        'status' => 'open',
        'subject' => $overrides['subject'] ?? 'Preorder timing for Arcade Cabinet',
    ]);

    Generation::create([
        'workspace_id' => test()->workspace->id,
        'conversation_id' => $conversation->id,
        'ai_draft' => $overrides['draft'] ?? 'Cabinets ship in March.',
        'intent' => $overrides['intent'] ?? 'preorder_status',
        'subtype' => $overrides['subtype'] ?? 'preorder_status',
        'status' => 'draft',
        'validator_passed' => true,
        'requires_human' => false,
        'safe_to_send' => false,
    ]);

    ShopifySnapshot::create([
        'workspace_id' => test()->workspace->id,
        'conversation_id' => $conversation->id,
        'payload' => [
            'found' => true,
            'order_number' => $overrides['order'] ?? 'SD1001',
            'products' => [[
                'title' => $overrides['product'] ?? 'Arcade Cabinet',
                'sku' => $overrides['sku'] ?? 'CAB-PREORDER',
                'variant' => 'Black',
                'quantity' => 1,
            ]],
        ],
        'fetched_at' => now(),
    ]);

    return $conversation;
}

test('live facts match related unsent drafts by product and skip sent or closed tickets', function () {
    $related = seedUnsentDraft();
    $otherProduct = seedUnsentDraft([
        'subject' => 'When does the poster ship?',
        'product' => 'Art Poster',
        'sku' => 'POSTER-1',
        'intent' => 'preorder_status',
    ]);
    $sent = seedUnsentDraft(['subject' => 'Already answered cabinet']);
    Generation::where('conversation_id', $sent->id)->update(['reply_thread_id' => Thread::factory()->create([
        'conversation_id' => $sent->id,
        'user_id' => test()->admin->id,
    ])->id]);
    $closed = seedUnsentDraft(['subject' => 'Closed cabinet ticket']);
    $closed->update(['status' => 'closed']);

    $matches = app(LiveFactService::class)->previewDrafts(
        test()->workspace->id,
        ['preorder_status'],
        ['Arcade Cabinet', 'CAB-PREORDER'],
        test()->conversation->id,
    )->pluck('id');

    expect($matches)->toContain($related->id)
        ->and($matches)->toContain(test()->conversation->id)
        ->and($matches)->not->toContain($otherProduct->id)
        ->and($matches)->not->toContain($sent->id)
        ->and($matches)->not->toContain($closed->id);
});

test('a live fact without product keywords matches by intent', function () {
    $related = seedUnsentDraft(['intent' => 'preorder_status']);
    $unrelated = seedUnsentDraft([
        'intent' => 'duties_tariffs',
        'subtype' => 'duties_tariffs',
        'product' => 'Arcade Cabinet',
        'subject' => 'Do I pay duty on the cabinet?',
    ]);

    $matches = app(LiveFactService::class)->previewDrafts(
        test()->workspace->id,
        ['preorder_status'],
        [],
        null,
    )->pluck('id');

    expect($matches)->toContain($related->id)
        ->and($matches)->not->toContain($unrelated->id);
});

test('publishing a live fact queues a rewrite of related unsent drafts', function () {
    Queue::fake();
    $related = seedUnsentDraft();

    $this->actingAs($this->admin)
        ->postJson('/commerce-assist/facts', [
            'body' => 'Cabinet preorders now ship the week of 21 April. Do not quote March.',
            'conversation_id' => $related->id,
            'intent_slugs' => ['preorder_status'],
            'product_keywords' => ['Arcade Cabinet'],
        ])
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect(LiveFact::count())->toBe(1)
        ->and(LiveFact::first()->body)->toContain('21 April');

    Queue::assertPushed(RefreshDraftsForFactJob::class, function (RefreshDraftsForFactJob $job) use ($related) {
        return in_array($related->id, $job->conversationIds, true);
    });
});

test('current business facts are injected into the writer context and override older knowledge', function () {
    IntentCatalog::ensureForWorkspace($this->workspace->id);
    $intent = CommerceIntent::where('workspace_id', $this->workspace->id)->where('slug', 'preorder_status')->first();
    $snapshot = new ShopifySnapshot(['payload' => ['found' => true, 'order_number' => 'SD1', 'products' => []]]);
    $fact = new LiveFact([
        'title' => 'Cabinet ship date',
        'body' => 'Cabinet preorders now ship the week of 21 April.',
    ]);

    $built = app(ContextBuilder::class)->build(
        $this->conversation->load('customer'),
        'When does my cabinet ship?',
        $snapshot,
        ['subtype' => 'preorder_status'],
        $intent,
        $intent,
        collect(),
        collect(),
        false,
        14,
        collect([$fact]),
    );

    expect($built['system'])
        ->toContain('CURRENT BUSINESS FACTS')
        ->toContain('week of 21 April')
        ->toContain('Current business facts override older knowledge base articles')
        ->and($built['sources']['facts'])->toContain('Cabinet ship date');
});

test('retired live facts are not applied to new drafts', function () {
    $related = seedUnsentDraft();
    LiveFact::create([
        'workspace_id' => $this->workspace->id,
        'body' => 'Old March date',
        'product_keywords' => ['Arcade Cabinet'],
        'retired_at' => now(),
    ]);

    $applied = app(LiveFactService::class)->forConversation($related->load('customer'));

    expect($applied)->toHaveCount(0);
});

// ---------------------------------------------------------------------------
// Carrier tracking
// ---------------------------------------------------------------------------

function enableShopify(array $overrides = []): void
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

function enableTrack123(): void
{
    enableShopify([
        'tracking_provider' => 'track123',
        'tracking_api_key' => Crypt::encryptString('t123_secret'),
    ]);
}

function shopifySnapshotWithTracking(array $overrides = []): ShopifySnapshot
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
function track123OrderPayload(array $fulfillment = []): array
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

test('track123 lookup normalises status, last mile handoff and proof of delivery', function () {
    enableTrack123();
    Http::fake(['https://shp.track123.com/*' => Http::response(track123OrderPayload())]);

    $tracking = app(TrackingLookupService::class)->lookup(
        $this->conversation->load(['customer', 'threads']),
        shopifySnapshotWithTracking(),
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
    enableTrack123();
    Http::fake(['https://shp.track123.com/*' => Http::response(track123OrderPayload())]);

    app(TrackingLookupService::class)->lookup(
        $this->conversation->load(['customer', 'threads']),
        shopifySnapshotWithTracking(),
    );

    Http::assertSent(fn ($request) => str_contains($request->url(), '/test-shop/orders/99.json')
        && $request->hasHeader('X-Api-Key', 't123_secret'));
});

test('carrier lookup is skipped entirely when no provider is configured', function () {
    $tracking = app(TrackingLookupService::class)->lookup(
        $this->conversation->load(['customer', 'threads']),
        shopifySnapshotWithTracking(),
    );

    expect($tracking)->toBeNull();
});

test('a stored carrier reading is reused inside the freshness window', function () {
    enableTrack123();
    Http::fake(['https://shp.track123.com/*' => Http::response(track123OrderPayload())]);

    $conversation = $this->conversation->load(['customer', 'threads']);
    $snapshot = shopifySnapshotWithTracking();

    $first = app(TrackingLookupService::class)->lookup($conversation, $snapshot);
    $second = app(TrackingLookupService::class)->lookup($conversation, $snapshot);

    expect($second->id)->toBe($first->id)
        ->and(TrackingSnapshot::count())->toBe(1);

    $forced = app(TrackingLookupService::class)->lookup($conversation, $snapshot, force: true);

    expect($forced->id)->not->toBe($first->id);
});

test('carrier lookup failure is recorded without breaking the snapshot', function () {
    enableTrack123();
    Http::fake(['https://shp.track123.com/*' => Http::response('boom', 500)]);

    $tracking = app(TrackingLookupService::class)->lookup(
        $this->conversation->load(['customer', 'threads']),
        shopifySnapshotWithTracking(),
    );

    expect($tracking->found())->toBeFalse()
        ->and($tracking->error)->not->toBeNull();
});

test('delivered without an attributable signature requires a human', function () {
    enableTrack123();
    Http::fake(['https://shp.track123.com/*' => Http::response(track123OrderPayload([
        'transit_sub_status' => 'Delivered to the front door',
        'last_event' => 'Delivered to the front door',
        'tracking_details' => [[
            'event_time_utc' => now()->subDay()->toIso8601String(),
            'event_detail' => 'Delivered to the front door',
            'event_location' => 'Sydney NSW',
        ]],
    ]))]);

    $tracking = app(TrackingLookupService::class)->lookup(
        $this->conversation->load(['customer', 'threads']),
        shopifySnapshotWithTracking(),
    );

    expect($tracking->proof()['type'])->toBe('left_at_location')
        ->and($tracking->proofIsAttributable())->toBeFalse();

    IntentCatalog::ensureForWorkspace($this->workspace->id);
    $intent = CommerceIntent::where('workspace_id', $this->workspace->id)->where('slug', 'tracking_problem')->first();

    $score = app(ConfidenceScorer::class)->score(
        CommerceSetting::forWorkspace($this->workspace->id),
        $intent,
        null,
        ShopifySnapshot::latest('id')->first(),
        [],
        [],
        [],
        $tracking,
    );

    expect($score['requires_human'])->toBeTrue()
        ->and($score['safe_to_send'])->toBeFalse();
});

test('a stalled parcel escalates on last scan age rather than fulfilment age', function () {
    enableTrack123();
    Http::fake(['https://shp.track123.com/*' => Http::response(track123OrderPayload([
        'transit_status' => 'In transit',
        'transit_sub_status' => null,
        'last_event' => 'Departed facility',
        'last_event_time' => now()->subDays(30)->toIso8601String(),
        'tracking_details' => [[
            'event_time_utc' => now()->subDays(30)->toIso8601String(),
            'event_detail' => 'Departed facility',
            'event_location' => 'Shenzhen',
        ]],
        'last_mile_info' => [],
    ]))]);

    $settings = CommerceSetting::forWorkspace($this->workspace->id);
    $tracking = app(TrackingLookupService::class)->lookup(
        $this->conversation->load(['customer', 'threads']),
        shopifySnapshotWithTracking(),
    );

    expect($tracking->days_since_last_scan)->toBe(30)
        ->and($tracking->isStalled($settings->tracking_stale_days))->toBeTrue();

    IntentCatalog::ensureForWorkspace($this->workspace->id);
    $intent = CommerceIntent::where('workspace_id', $this->workspace->id)->where('slug', 'tracking_problem')->first();

    $score = app(ConfidenceScorer::class)->score(
        $settings,
        $intent,
        null,
        ShopifySnapshot::latest('id')->first(),
        [],
        [],
        [],
        $tracking,
    );

    expect($score['requires_human'])->toBeTrue();
});

test('a carrier exception always requires a human', function () {
    enableTrack123();
    Http::fake(['https://shp.track123.com/*' => Http::response(track123OrderPayload([
        'transit_status' => 'Exception',
        'transit_sub_status' => 'Returned to sender',
        'last_event' => 'Returned to sender',
        'last_event_time' => now()->subDay()->toIso8601String(),
    ]))]);

    $tracking = app(TrackingLookupService::class)->lookup(
        $this->conversation->load(['customer', 'threads']),
        shopifySnapshotWithTracking(),
    );

    IntentCatalog::ensureForWorkspace($this->workspace->id);
    $intent = CommerceIntent::where('workspace_id', $this->workspace->id)->where('slug', 'tracking_problem')->first();

    $score = app(ConfidenceScorer::class)->score(
        CommerceSetting::forWorkspace($this->workspace->id),
        $intent,
        null,
        ShopifySnapshot::latest('id')->first(),
        [],
        [],
        [],
        $tracking,
    );

    expect($tracking->needsAttention())->toBeTrue()
        ->and($score['requires_human'])->toBeTrue();
});

test('the prompt carries verified carrier data and forbids inventing scans', function () {
    enableTrack123();
    Http::fake(['https://shp.track123.com/*' => Http::response(track123OrderPayload())]);

    $conversation = $this->conversation->load(['customer', 'threads']);
    $tracking = app(TrackingLookupService::class)->lookup($conversation, shopifySnapshotWithTracking());

    IntentCatalog::ensureForWorkspace($this->workspace->id);
    $intent = CommerceIntent::where('workspace_id', $this->workspace->id)->where('slug', 'tracking_problem')->first();

    $built = app(ContextBuilder::class)->build(
        $conversation,
        'Where is my parcel?',
        ShopifySnapshot::latest('id')->first(),
        ['intent' => 'tracking_problem', 'subtype' => 'tracking_problem'],
        $intent,
        $intent,
        collect(),
        collect(),
        false,
        14,
        collect(),
        $tracking,
    );

    expect($built['system'])
        ->toContain('VERIFIED CARRIER TRACKING DATA')
        ->toContain('Australia Post')
        ->toContain('AP99887766')
        ->toContain('Never describe a scan, a delivery attempt, or a signature that is not in the carrier data')
        ->and($built['sources']['tracking'])->toContain('4PX Express');
});

test('with no carrier source the prompt says so instead of implying a problem', function () {
    $block = app(ContextBuilder::class)->formatTracking(null);

    expect($block)->toContain('No carrier tracking source configured')
        ->and($block)->toContain('do not describe carrier scans');
});

test('a carrier with no record must not be read as a lost parcel', function () {
    enableTrack123();
    Http::fake(['https://shp.track123.com/*' => Http::response(null, 404)]);

    $tracking = app(TrackingLookupService::class)->lookup(
        $this->conversation->load(['customer', 'threads']),
        shopifySnapshotWithTracking(),
    );

    expect($tracking->found())->toBeFalse()
        ->and(app(ContextBuilder::class)->formatTracking($tracking))
        ->toContain('Do not infer from this that the parcel is lost');
});

test('admins can select a tracking provider and the key is stored encrypted', function () {
    $this->actingAs($this->admin)
        ->post('/settings/commerce-assist', [
            'shopify_shop_domain' => 'demo.myshopify.com',
            'shopify_api_version' => '2025-01',
            'tracking_provider' => 'track123',
            'tracking_api_key' => 't123_secret',
            'tracking_stale_days' => 10,
            'example_edit_threshold' => 30,
            'preorder_tags' => 'preorder',
            'draft_only' => true,
        ])
        ->assertRedirect();

    $settings = CommerceSetting::forWorkspace($this->workspace->id);

    expect($settings->tracking_provider)->toBe('track123')
        ->and($settings->tracking_api_key)->not->toBe('t123_secret')
        ->and($settings->decryptTrackingApiKey())->toBe('t123_secret')
        ->and($settings->trackingEnabled())->toBeTrue()
        // Falls back to the Shopify subdomain so most stores never set this.
        ->and($settings->trackingStoreUuid())->toBe('demo');
});

test('switching the tracking provider off clears the stored key', function () {
    enableTrack123();

    $this->actingAs($this->admin)
        ->post('/settings/commerce-assist', [
            'shopify_shop_domain' => 'demo.myshopify.com',
            'shopify_api_version' => '2025-01',
            'tracking_provider' => 'none',
            'tracking_stale_days' => 10,
            'example_edit_threshold' => 30,
            'preorder_tags' => 'preorder',
            'draft_only' => true,
        ])
        ->assertRedirect();

    $settings = CommerceSetting::forWorkspace($this->workspace->id);

    expect($settings->tracking_api_key)->toBeNull()
        ->and($settings->trackingEnabled())->toBeFalse();
});

test('an unknown tracking provider is rejected', function () {
    $this->actingAs($this->admin)
        ->post('/settings/commerce-assist', [
            'tracking_provider' => 'definitely-not-a-provider',
            'tracking_stale_days' => 10,
            'example_edit_threshold' => 30,
            'draft_only' => true,
        ])
        ->assertSessionHasErrors('tracking_provider');
});

test('agents can force a carrier refresh from the conversation panel', function () {
    enableTrack123();
    Http::fake(['https://shp.track123.com/*' => Http::response(track123OrderPayload())]);
    shopifySnapshotWithTracking();

    $this->actingAs($this->admin)
        ->postJson("/commerce-assist/conversations/{$this->conversation->id}/tracking-refresh")
        ->assertOk()
        ->assertJsonPath('tracking.status', 'delivered')
        ->assertJsonPath('tracking.last_mile_carrier', 'Australia Post');
});

test('the generation pipeline stores the carrier snapshot it drafted against', function () {
    enableTrack123();
    fakeCommerceAgents();
    Http::fake([
        'https://test-shop.myshopify.com/admin/api/*' => Http::response(shopifyOrderPayload()),
        'https://shp.track123.com/*' => Http::response(track123OrderPayload()),
    ]);

    $generation = app(GenerationPipeline::class)->run($this->conversation->load(['customer', 'threads']));

    expect($generation->tracking_snapshot_id)->not->toBeNull()
        ->and($generation->sources['tracking'])->toContain('4PX Express');
});
