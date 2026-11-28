<?php

require_once __DIR__.'/AgentTestHelpers.php';

use App\Domains\Agent\Agents\TriageAgent;
use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Pipeline\AgentPipeline;
use App\Domains\Agent\Pipeline\EvidenceLedger;
use App\Domains\Agent\Pipeline\PipelineInput;
use App\Domains\Agent\Services\AgentBootstrapper;
use App\Domains\Agent\Support\Categories;
use App\Domains\Conversation\Models\Conversation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;

/*
 * The inbox category (conversations.agent_category): decided by the playbook
 * triage chose, or by the triage model when no playbook fits; written by live
 * runs only; backfilled by `agent:categorise`.
 */

beforeEach(function () {
    Http::preventStrayRequests();
});

function categoryTFakeTriage(string $playbook, ?string $category): void
{
    TriageAgent::fake([agentTStructured(array_filter([
        'language' => 'en',
        'sentiment' => 'calm',
        'risk_flags' => [],
        'playbook' => $playbook,
        'category' => $category,
        'confidence' => 0.9,
        'reason' => 'Test.',
    ], fn ($value) => $value !== null))]);
}

function categoryTRun(Conversation $conversation, ?string $slug, array $attributes = []): AgentRun
{
    $playbook = $slug === null ? null : Playbook::where('workspace_id', $conversation->workspace_id)->where('slug', $slug)->firstOrFail();

    return AgentRun::create(array_merge([
        'workspace_id' => $conversation->workspace_id,
        'purpose' => AgentRun::PURPOSE_LIVE,
        'conversation_id' => $conversation->id,
        'idempotency_key' => (string) Str::uuid(),
        'status' => AgentRun::STATUS_COMPLETED,
        'config' => [],
        'config_hash' => str_repeat('a', 64),
        'playbook_id' => $playbook?->id,
        'decision' => 'draft',
        'completed_at' => now(),
    ], $attributes));
}

test('playbook slugs map to a category by exact slug, then by the part before the first dot, else other', function (string $slug, string $category) {
    expect(Categories::forPlaybook($slug))->toBe($category);
})->with([
    ['order_status', 'orders'],
    ['order_status.unfulfilled', 'orders'],
    ['order_status.fulfilled', 'orders'],
    ['preorder_status', 'orders'],
    ['tracking_problem', 'orders'],
    ['tracking.no_updates', 'orders'],
    ['tracking.delivered_not_received', 'orders'],
    ['address_change', 'orders'],
    ['missing_items_in_shipment', 'orders'],
    ['duties_tariffs', 'orders'],
    ['price_discrepancy', 'orders'],
    ['refund_request', 'returns'],
    ['refund.change_of_mind', 'returns'],
    ['damage.screen', 'returns'],
    ['damage.shipping', 'returns'],
    ['damaged_product', 'returns'],
    ['technical_support', 'technical'],
    ['technical_support.firmware', 'technical'],
    ['product_question', 'product'],
    ['wholesale', 'business'],
    ['influencer', 'business'],
    ['spam', 'other'],
    ['other', 'other'],
    ['something_new', 'other'],
    ['something.new', 'other'],
]);

test('every starter playbook is in the category map', function () {
    $case = agentTCase();
    app(AgentBootstrapper::class)->ensureForWorkspace($case['workspace']->id);

    $slugs = Playbook::where('workspace_id', $case['workspace']->id)->pluck('slug');

    expect($slugs)->not->toBeEmpty()
        ->and($slugs->reject(fn ($slug) => Categories::knowsPlaybook($slug))->values()->all())->toBe([]);
});

test('the triage prompt explains every category', function () {
    $case = agentTCase();
    categoryTFakeTriage('none', 'product');

    app(AgentPipeline::class)->run(PipelineInput::fromConversation($case['conversation']->fresh()));

    TriageAgent::assertPrompted(function (AgentPrompt $prompt) {
        $system = $prompt->agent->instructions();
        foreach (Categories::keys() as $key) {
            expect($system)->toContain("- {$key}: ");
        }

        return true;
    });
});

test('a live run writes the category of the playbook it chose, whatever the model said', function () {
    $case = agentTCase('Hi, has my order shipped yet?');
    categoryTFakeTriage('order_status', 'technical');
    agentTFakeWriter(['reply' => "Hi Alex,\n\nCould you send me your order number?\n\nCheers,", 'claims' => []]);
    agentTFakeValidator('pass');

    $run = app(AgentPipeline::class)->run(PipelineInput::fromConversation($case['conversation']->fresh()))->run;

    expect($run->triage['category'])->toBe('orders')
        ->and($case['conversation']->fresh()->agent_category)->toBe('orders');
});

test('without a playbook the category the triage model gave is used', function () {
    $case = agentTCase('My dial will not pair with the app any more.');
    categoryTFakeTriage('none', 'technical');

    $run = app(AgentPipeline::class)->run(PipelineInput::fromConversation($case['conversation']->fresh()))->run;

    expect($run->playbook_id)->toBeNull()
        ->and($run->decision)->toBe('handover')
        ->and($run->triage['category'])->toBe('technical')
        ->and($case['conversation']->fresh()->agent_category)->toBe('technical');
});

test('without a playbook, a missing or unknown category from the model is "other"', function (?string $category) {
    $case = agentTCase('Can you recommend a good pizza place?');
    categoryTFakeTriage('none', $category);

    app(AgentPipeline::class)->run(PipelineInput::fromConversation($case['conversation']->fresh()));

    expect($case['conversation']->fresh()->agent_category)->toBe('other');
})->with([null, 'nonsense']);

test('a failed triage leaves the category as it was', function () {
    $case = agentTCase();
    $case['conversation']->forceFill(['agent_category' => 'returns'])->save();
    TriageAgent::fake(fn () => throw new RuntimeException('provider down'));

    $run = app(AgentPipeline::class)->run(PipelineInput::fromConversation($case['conversation']->fresh()))->run;

    expect($run->triage['category'])->toBeNull()
        ->and($case['conversation']->fresh()->agent_category)->toBe('returns');
});

test('a shadow run does not write the category', function () {
    $case = agentTCase('Where is order SD8421?', mode: 'shadow');
    categoryTFakeTriage('order_status', 'orders');
    agentTFakeWriter(['reply' => 'Hi Alex, could you send me your order number so I can check?']);
    agentTFakeValidator('pass');

    $run = app(AgentPipeline::class)->run(PipelineInput::fromConversation($case['conversation']->fresh(), AgentRun::PURPOSE_SHADOW))->run;

    expect($run->purpose)->toBe('shadow')
        ->and($run->triage['category'])->toBe('orders')
        ->and($case['conversation']->fresh()->agent_category)->toBeNull();
});

test('a preview run on a real conversation and a simulation do not write the category', function () {
    $case = agentTCase('Where is order SD8421?');

    categoryTFakeTriage('none', 'technical');
    $preview = app(AgentPipeline::class)->run(PipelineInput::fromConversation($case['conversation']->fresh(), AgentRun::PURPOSE_PREVIEW))->run;

    categoryTFakeTriage('none', 'technical');
    $simulation = app(AgentPipeline::class)->run(PipelineInput::fromTranscript(
        $case['workspace']->id,
        AgentRun::PURPOSE_SIMULATION,
        [['role' => 'customer', 'text' => 'My dial will not pair.', 'at' => '2026-03-01T10:00:00Z']],
        EvidenceLedger::fromArray(['facts' => []]),
        CarbonImmutable::parse('2026-03-02T09:00:00Z'),
        subject: 'Pairing',
        customerName: 'Sam',
        customerEmail: 'sam@example.com',
    ))->run;

    expect($preview->purpose)->toBe('preview')
        ->and($simulation->purpose)->toBe('simulation')
        ->and($case['conversation']->fresh()->agent_category)->toBeNull()
        ->and(Conversation::whereNotNull('agent_category')->count())->toBe(0);
});

test('agent:categorise backfills open and pending conversations from their latest completed live run', function () {
    $case = agentTCase();
    $workspace = $case['workspace'];
    app(AgentBootstrapper::class)->ensureForWorkspace($workspace->id);
    $make = fn (array $attributes = []) => Conversation::factory()->create(array_merge([
        'workspace_id' => $workspace->id,
        'mailbox_id' => $case['mailbox']->id,
        'customer_id' => $case['customer']->id,
        'status' => 'open',
    ], $attributes));

    // The newest completed live run decides.
    $tracking = $make();
    categoryTRun($tracking, 'refund_request');
    categoryTRun($tracking, 'tracking.no_updates');
    // Runs that are not completed live runs are ignored.
    categoryTRun($tracking, 'wholesale', ['purpose' => AgentRun::PURPOSE_SHADOW]);
    categoryTRun($tracking, 'wholesale', ['status' => AgentRun::STATUS_FAILED]);

    $pending = $make(['status' => 'pending']);
    categoryTRun($pending, 'damage.screen');

    // No playbook: the category the run recorded, else "other".
    $recorded = $make();
    categoryTRun($recorded, null, ['decision' => 'handover', 'triage' => ['playbook' => null, 'category' => 'technical']]);
    $noPlaybook = $make();
    categoryTRun($noPlaybook, null, ['decision' => 'handover', 'triage' => ['playbook' => null]]);

    $closed = $make(['status' => 'closed']);
    categoryTRun($closed, 'order_status');
    $neverRun = $make();
    $alreadyRight = $make(['updated_at' => now()->subDay()]);
    $alreadyRight->forceFill(['agent_category' => 'product'])->saveQuietly();
    categoryTRun($alreadyRight, 'product_question');
    $stamp = $alreadyRight->fresh()->updated_at;

    $category = fn (Conversation $conversation) => $conversation->fresh()->agent_category;

    $this->artisan('agent:categorise', ['--dry-run' => true])
        ->expectsOutputToContain('5 open or pending conversations with an agent run checked, 4 would change')
        ->assertSuccessful();
    expect($category($tracking))->toBeNull();

    $this->artisan('agent:categorise')
        ->expectsOutputToContain('5 open or pending conversations with an agent run checked, 4 updated.')
        ->assertSuccessful();

    expect($category($tracking))->toBe('orders')
        ->and($category($pending))->toBe('returns')
        ->and($category($recorded))->toBe('technical')
        ->and($category($noPlaybook))->toBe('other')
        ->and($category($closed))->toBeNull()
        ->and($category($neverRun))->toBeNull()
        ->and($category($alreadyRight))->toBe('product')
        ->and($alreadyRight->fresh()->updated_at->equalTo($stamp))->toBeTrue();

    // Running it again changes nothing.
    $this->artisan('agent:categorise')
        ->expectsOutputToContain('5 open or pending conversations with an agent run checked, 0 updated.')
        ->assertSuccessful();
});

test('agent:categorise can be limited to one workspace', function () {
    $one = agentTCase();
    $two = agentTCase();
    foreach ([$one, $two] as $case) {
        app(AgentBootstrapper::class)->ensureForWorkspace($case['workspace']->id);
        categoryTRun($case['conversation'], 'order_status');
    }

    $this->artisan('agent:categorise', ['--workspace' => $one['workspace']->id])->assertSuccessful();

    expect($one['conversation']->fresh()->agent_category)->toBe('orders')
        ->and($two['conversation']->fresh()->agent_category)->toBeNull();
});
