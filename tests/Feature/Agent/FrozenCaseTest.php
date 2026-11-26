<?php

require_once __DIR__.'/AgentTestHelpers.php';
require_once __DIR__.'/LoopTestHelpers.php';

use App\Domains\Agent\Jobs\PrepareFrozenCasesJob;
use App\Domains\Agent\Simulation\EvidenceReconstructor;
use App\Domains\Agent\Simulation\FrozenCaseFactory;
use App\Domains\Commerce\Models\CommerceSetting;
use App\Domains\Commerce\Services\Tracking\TrackingEvent;
use App\Domains\Commerce\Services\Tracking\TrackingProvider;
use App\Domains\Commerce\Services\Tracking\TrackingProviderFactory;
use App\Domains\Commerce\Services\Tracking\TrackingQuery;
use App\Domains\Commerce\Services\Tracking\TrackingResult;
use App\Domains\Commerce\Services\Tracking\TrackingStatus;
use App\Domains\Conversation\Models\Thread;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/** Customer (t0) -> staff (t0+1h) -> customer (t0+1d) -> staff (t0+1d+2h). */
function loopTThread(array $f, ?int $customerId, string $text, CarbonImmutable $at, string $origin = 'fusterai'): Thread
{
    return Thread::factory()->create([
        'conversation_id' => $f['conversation']->id, 'customer_id' => $customerId,
        'user_id' => $customerId === null ? User::factory()->create(['workspace_id' => $f['workspace']->id, 'name' => 'Sam Staff'])->id : null,
        'type' => 'message', 'body' => '<p>'.e($text).'</p>', 'body_plain' => $text, 'origin' => $origin,
        'created_at' => $at, 'sent_at' => $at,
    ]);
}

function loopTOrderNode(array $overrides = []): array
{
    return array_merge([
        'id' => 'gid://shopify/Order/99', 'name' => '#SD8421', 'email' => 'buyer@example.com',
        'createdAt' => '2026-11-01T00:00:00Z', 'cancelledAt' => null,
        'displayFinancialStatus' => 'REFUNDED', 'displayFulfillmentStatus' => 'FULFILLED', 'tags' => [],
        'customer' => ['firstName' => 'Alex', 'lastName' => 'Buyer', 'email' => 'buyer@example.com'],
        'shippingAddress' => ['country' => 'Australia', 'countryCodeV2' => 'AU'],
        'fulfillments' => [['createdAt' => '2026-11-12T00:00:00Z', 'trackingInfo' => [['number' => 'TRK1', 'url' => 'https://4px.com/t/TRK1', 'company' => '4PX']]]],
        'refunds' => [['createdAt' => '2026-11-20T00:00:00Z']],
        'lineItems' => ['edges' => [['node' => ['title' => 'Soul Dial Pro', 'quantity' => 1, 'variantTitle' => 'Black', 'sku' => 'SDP-1']]]],
    ], $overrides);
}

test('a case is cut at the customer message, with the human reply that followed', function () {
    $f = loopTWorkspace();
    $t0 = CarbonImmutable::parse('2026-11-10 09:00:00');
    $f['thread']->forceFill(['created_at' => $t0, 'sent_at' => $t0])->save();
    $reply1 = loopTThread($f, null, 'Hi Alex, it ships Monday.', $t0->addHour(), 'gmail');
    $followUp = loopTThread($f, $f['customer']->id, 'Monday passed, where is it?', $t0->addDay());
    loopTThread($f, null, 'Sorry, it left today: TRK1.', $t0->addDay()->addHours(2));

    $case = app(FrozenCaseFactory::class)->fromConversation($f['conversation'], $f['thread'], 'mining', ['batch' => 1]);

    expect($case->transcript)->toHaveCount(1)
        ->and($case->transcript[0]['text'])->toContain('where is my order SD8421')
        ->and($case->cut_at->toIso8601String())->toBe($t0->toIso8601String())
        ->and($case->human_reply)->toBe('Hi Alex, it ships Monday.')
        ->and($case->human_thread_id)->toBe($reply1->id)
        ->and($case->human_reply_origin)->toBe('gmail')
        ->and($case->human_reply_by)->toBe('Sam Staff')
        ->and($case->evidence_quality)->toBe('none')
        ->and($case->source)->toBe('mining')
        ->and($case->customer_email)->toBe('buyer@example.com');

    // Idempotent.
    expect(app(FrozenCaseFactory::class)->fromConversation($f['conversation'], $f['thread'])->id)->toBe($case->id);

    $all = app(FrozenCaseFactory::class)->allFromConversation($f['conversation']);
    expect($all)->toHaveCount(2)
        ->and($all[0]->cut_thread_id)->toBe($followUp->id)
        ->and($all[0]->transcript)->toHaveCount(3)
        ->and($all[0]->human_reply)->toBe('Sorry, it left today: TRK1.')
        ->and($all[1]->id)->toBe($case->id);

    expect(fn () => app(FrozenCaseFactory::class)->fromConversation($f['conversation'], $reply1))->toThrow(InvalidArgumentException::class);
});

test('evidence is recorded when the live run stored a snapshot for the cut message', function () {
    $f = loopTWorkspace();
    $late = loopTSnapshot($f, ['fulfilment_status' => 'FULFILLED'], now()->addDays(30));
    $run = loopTLiveRun($f);
    $run->forceFill(['evidence' => [...$run->evidence, 'meta' => ['shopify_snapshot_id' => $late->id]]])->save();

    $case = loopTFrozenCase($f);

    expect($case->evidence_quality)->toBe('recorded')
        ->and($case->evidence['shopify_snapshot_id'])->toBe($late->id)
        ->and($case->evidence['shopify']['fulfilment_status'])->toBe('FULFILLED')
        ->and($case->evidence_hash)->toHaveLength(64)
        ->and($case->playbook_slug)->toBe('order_status');
});

test('a snapshot fetched between the cut and the human reply counts as recorded; a later one does not', function () {
    $f = loopTWorkspace();
    loopTSnapshot($f, [], now()->addSeconds(20));
    expect(loopTFrozenCase($f)->evidence_quality)->toBe('recorded');

    $g = loopTWorkspace();
    loopTSnapshot($g, [], now()->addDays(3));
    expect(loopTFrozenCase($g)->evidence_quality)->toBe('none');
});

test('prepare reconstructs Shopify evidence as of the cut from the order\'s own timestamps', function () {
    $f = loopTWorkspace();
    agentTEnableShopify($f['workspace']->id);
    $f['thread']->forceFill(['created_at' => '2026-11-10 09:00:00', 'sent_at' => '2026-11-10 09:00:00'])->save();
    loopTThread($f, null, 'It ships soon.', CarbonImmutable::parse('2026-11-10 10:00:00'));
    $case = app(FrozenCaseFactory::class)->fromConversation($f['conversation'], $f['thread']);
    Http::fake(['test-shop.myshopify.com/*' => Http::response(['data' => ['orders' => ['edges' => [['node' => loopTOrderNode()]]]]])]);

    $quality = app(EvidenceReconstructor::class)->prepare($case);

    $shopify = $case->fresh()->evidence['shopify'];
    expect($quality)->toBe('reconstructed')
        ->and($case->fresh()->evidence_quality)->toBe('reconstructed')
        // Fulfilled on the 12th and refunded on the 20th: neither had happened on the 10th.
        ->and($shopify['fulfilment_status'])->toBe('UNFULFILLED')
        ->and($shopify['payment_status'])->toBe('PAID')
        ->and($shopify['refunded'])->toBeFalse()
        ->and($shopify['tracking_number'])->toBeNull()
        ->and($shopify['order_age_days'])->toBe(9)
        ->and($shopify['notes'])->toBeNull();
    Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'SD8421'));
});

test('carrier events after the cut are dropped from reconstructed tracking', function () {
    $f = loopTWorkspace();
    agentTEnableShopify($f['workspace']->id);
    $f['thread']->forceFill(['created_at' => '2026-11-15 09:00:00', 'sent_at' => '2026-11-15 09:00:00'])->save();
    loopTThread($f, null, 'In transit.', CarbonImmutable::parse('2026-11-15 10:00:00'));
    $case = app(FrozenCaseFactory::class)->fromConversation($f['conversation'], $f['thread']);
    Http::fake(['test-shop.myshopify.com/*' => Http::response(['data' => ['orders' => ['edges' => [['node' => loopTOrderNode()]]]]])]);

    app(TrackingProviderFactory::class)->extend('fake', new class implements TrackingProvider
    {
        public function key(): string
        {
            return 'fake';
        }

        public function lookup(TrackingQuery $query): ?TrackingResult
        {
            return new TrackingResult(provider: 'fake', status: TrackingStatus::Delivered, trackingNumber: $query->trackingNumber,
                deliveredAt: CarbonImmutable::parse('2026-11-18 12:00:00'), lastEventAt: CarbonImmutable::parse('2026-11-18 12:00:00'),
                events: [
                    TrackingEvent::make('2026-11-18T12:00:00Z', 'Delivered, front door'),
                    TrackingEvent::make('2026-11-14T08:00:00Z', 'Arrived at sorting facility'),
                    TrackingEvent::make('2026-11-12T08:00:00Z', 'Accepted'),
                ]);
        }

        public function ping(): array
        {
            return [];
        }
    });
    CommerceSetting::forWorkspace($f['workspace']->id)->update(['tracking_provider' => 'fake']);

    app(EvidenceReconstructor::class)->prepare($case);

    $tracking = $case->fresh()->evidence['tracking'];
    // The only fulfilment (12th) is before the cut (15th): Shopify's own status applies.
    expect($case->fresh()->evidence['shopify']['fulfilment_status'])->toBe('FULFILLED')
        ->and($case->fresh()->evidence['shopify']['tracking_number'])->toBe('TRK1');
    expect($tracking['status'])->toBe('in_transit')
        ->and($tracking['delivered_at'])->toBeNull()
        ->and($tracking['last_event'])->toBe('Arrived at sorting facility')
        ->and($tracking['days_since_last_scan'])->toBe(1)
        ->and(collect($tracking['events'])->pluck('description')->all())->toBe(['Arrived at sorting facility', 'Accepted']);
});

test('an order placed after the cut cannot be used: quality stays none with the reason', function () {
    $f = loopTWorkspace();
    agentTEnableShopify($f['workspace']->id);
    $f['thread']->forceFill(['created_at' => '2026-10-01 09:00:00', 'sent_at' => '2026-10-01 09:00:00'])->save();
    $case = loopTFrozenCase($f);
    Http::fake(['test-shop.myshopify.com/*' => Http::response(['data' => ['orders' => ['edges' => [['node' => loopTOrderNode()]]]]])]);

    expect(app(EvidenceReconstructor::class)->prepare($case))->toBe('none')
        ->and($case->fresh()->evidence_error)->toContain('No Shopify order')
        ->and($case->fresh()->evidence_prepared_at)->not->toBeNull();
});

test('prepare is rate limited and re-dispatches itself with a delay', function () {
    $f = loopTWorkspace();
    agentTEnableShopify($f['workspace']->id);
    config(['agent.simulation.prepare_per_minute' => 1]);
    $a = loopTFrozenCase($f);
    $b = loopTCloneCase($a, 'b');
    Http::fake(['test-shop.myshopify.com/*' => Http::response(['data' => ['orders' => ['edges' => []]]])]);
    Queue::fake();

    (new PrepareFrozenCasesJob($f['workspace']->id))->handle(app(EvidenceReconstructor::class));

    expect($a->fresh()->evidence_prepared_at)->not->toBeNull()
        ->and($b->fresh()->evidence_prepared_at)->toBeNull();
    Queue::assertPushed(PrepareFrozenCasesJob::class, fn ($job) => $job->delay !== null);
    // One case looked up (by order number, then by email); the other waits for the limiter.
    Http::assertSentCount(2);
});
