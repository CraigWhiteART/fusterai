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
use Modules\CommerceAssist\Models\ApprovedResponse;
use Modules\CommerceAssist\Models\CommerceIntent;
use Modules\CommerceAssist\Models\CommerceSetting;
use Modules\CommerceAssist\Jobs\RefreshDraftsForFactJob;
use Modules\CommerceAssist\Models\Generation;
use Modules\CommerceAssist\Models\LiveFact;
use Modules\CommerceAssist\Models\ReplayRun;
use Modules\CommerceAssist\Models\ShopifySnapshot;
use Modules\CommerceAssist\Providers\CommerceAssistServiceProvider;
use Modules\CommerceAssist\Services\ContextBuilder;
use Modules\CommerceAssist\Services\EditLearningService;
use Modules\CommerceAssist\Services\ExampleService;
use Modules\CommerceAssist\Services\GenerationPipeline;
use Modules\CommerceAssist\Services\IntentCatalog;
use Modules\CommerceAssist\Services\LiveFactService;
use Modules\CommerceAssist\Services\ShopifyLookupService;
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
    CommerceSetting::forWorkspace($this->workspace->id)->update([
        'shopify_shop_domain' => 'test-shop.myshopify.com',
        'shopify_access_token' => Crypt::encryptString('shpat_test'),
    ]);

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

    $snapshot = new \Modules\CommerceAssist\Models\ShopifySnapshot([
        'payload' => [
            'found' => true,
            'order_number' => 'SD8421',
            'fulfilment_status' => 'UNFULFILLED',
            'tracking_number' => null,
            'products' => [],
        ],
    ]);

    $example = new ApprovedResponse([
        'id' => 31,
        'customer_message' => 'Where is SD1000?',
        'final_reply' => 'SD1000 shipped yesterday with tracking 1Z999.',
        'intent' => 'tracking_problem',
        'subtype' => 'tracking.no_updates',
    ]);

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
    $this->actingAs($this->admin)
        ->post('/settings/commerce-assist', [
            'shopify_shop_domain' => 'demo.myshopify.com',
            'shopify_access_token' => 'shpat_secret',
            'shopify_api_version' => '2025-01',
            'tracking_stale_days' => 10,
            'example_edit_threshold' => 30,
            'preorder_tags' => 'preorder, pre-order',
            'draft_only' => true,
        ])
        ->assertRedirect();

    $settings = CommerceSetting::forWorkspace($this->workspace->id);
    expect($settings->shopify_shop_domain)->toBe('demo.myshopify.com')
        ->and($settings->tracking_stale_days)->toBe(10)
        ->and($settings->decryptAccessToken())->toBe('shpat_secret');
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

