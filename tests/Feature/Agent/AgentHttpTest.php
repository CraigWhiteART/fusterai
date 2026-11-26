<?php

require_once __DIR__.'/AgentTestHelpers.php';

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\KnowledgeItem;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Models\VoiceVersion;
use App\Domains\Agent\Services\AgentBootstrapper;
use App\Domains\AI\Jobs\GenerateReplySuggestionJob;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

dataset('admin routes', [
    'settings' => ['get', '/agent/settings'],
    'settings update' => ['patch', '/agent/settings'],
    'voice' => ['get', '/agent/voice'],
    'voice store' => ['post', '/agent/voice'],
    'voice restore' => ['post', '/agent/voice/versions/{voice}/restore'],
    'playbooks' => ['get', '/agent/playbooks'],
    'playbook create' => ['get', '/agent/playbooks/create'],
    'playbook store' => ['post', '/agent/playbooks'],
    'playbook show' => ['get', '/agent/playbooks/{playbook}'],
    'playbook update' => ['patch', '/agent/playbooks/{playbook}'],
    'playbook version' => ['post', '/agent/playbooks/{playbook}/versions'],
    'playbook restore' => ['post', '/agent/playbooks/{playbook}/versions/{version}/restore'],
    'knowledge' => ['get', '/agent/knowledge'],
    'knowledge create' => ['get', '/agent/knowledge/create'],
    'knowledge store' => ['post', '/agent/knowledge'],
    'knowledge edit' => ['get', '/agent/knowledge/{item}/edit'],
    'knowledge update' => ['patch', '/agent/knowledge/{item}'],
]);

/** @return array{workspace: Workspace, admin: User, agent: User, playbook: Playbook, voice: VoiceVersion, item: KnowledgeItem} */
function agentTHttpFixture(): array
{
    $workspace = Workspace::factory()->create();
    app(AgentBootstrapper::class)->ensureForWorkspace($workspace->id);

    return [
        'workspace' => $workspace,
        'admin' => User::factory()->create(['workspace_id' => $workspace->id, 'role' => 'admin']),
        'agent' => User::factory()->create(['workspace_id' => $workspace->id, 'role' => 'agent']),
        'playbook' => Playbook::with('liveVersion')->where('workspace_id', $workspace->id)->where('slug', 'order_status')->sole(),
        'voice' => VoiceVersion::where('workspace_id', $workspace->id)->firstOrFail(),
        'item' => KnowledgeItem::create([
            'workspace_id' => $workspace->id, 'kind' => 'fact', 'body' => 'Closed Friday', 'status' => 'needs_scope',
            'scope' => ['playbooks' => [], 'products' => [], 'countries' => [], 'global' => false],
        ]),
    ];
}

function agentTUrl(string $url, array $f): string
{
    return strtr($url, [
        '{voice}' => (string) $f['voice']->id,
        '{playbook}' => (string) $f['playbook']->id,
        '{version}' => (string) $f['playbook']->live_version_id,
        '{item}' => (string) $f['item']->id,
    ]);
}

test('agent instruction routes need login', function (string $method, string $url) {
    $f = agentTHttpFixture();

    $this->{$method}(agentTUrl($url, $f))->assertRedirect('/login');
})->with('admin routes');

test('agent instruction routes are admin only', function (string $method, string $url) {
    $f = agentTHttpFixture();

    $this->actingAs($f['agent'])->{$method.'Json'}(agentTUrl($url, $f), [])->assertForbidden();
})->with('admin routes');

test('another workspace\'s playbooks, versions and knowledge are not found', function () {
    $f = agentTHttpFixture();
    $other = adminUser();

    $this->actingAs($other)->get("/agent/playbooks/{$f['playbook']->id}")->assertNotFound();
    $this->actingAs($other)->get("/agent/knowledge/{$f['item']->id}/edit")->assertNotFound();
    $this->actingAs($other)->post("/agent/voice/versions/{$f['voice']->id}/restore")->assertNotFound();
});

test('admins can view the agent pages', function () {
    $f = agentTHttpFixture();

    $this->actingAs($f['admin'])->get('/agent/settings')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Agent/Settings')->where('settings.mode', 'off'));
    $this->actingAs($f['admin'])->get('/agent/voice')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Agent/Voice')->has('versions', 1)->where('live.is_starter', true));
    $this->actingAs($f['admin'])->get('/agent/playbooks')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Agent/Playbooks/Index')->has('playbooks'));
    $this->actingAs($f['admin'])->get("/agent/playbooks/{$f['playbook']->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Agent/Playbooks/Edit')->where('playbook.slug', 'order_status')->where('auto_mode_available', true));
    $this->actingAs($f['admin'])->get('/agent/knowledge?status=needs_scope')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Agent/Knowledge/Index')->has('items.data', 1)->where('counts.needs_scope', 1));
    $this->actingAs($f['admin'])->get('/agent/knowledge/create')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Agent/Knowledge/Edit')->where('item', null));
});

test('admins edit voice, playbooks, settings and knowledge through the routes', function () {
    $f = agentTHttpFixture();
    $admin = $f['admin'];

    $this->actingAs($admin)->patch('/agent/settings', ['mode' => 'draft', 'kill_switch' => true, 'link_allowlist' => ['soul-dial.com']])->assertRedirect();
    expect(AgentSetting::forWorkspace($f['workspace']->id)->only(['mode', 'kill_switch', 'link_allowlist']))
        ->toBe(['mode' => 'draft', 'kill_switch' => true, 'link_allowlist' => ['soul-dial.com']]);

    $this->actingAs($admin)->post('/agent/voice', ['personality' => 'Brisk', 'dont_phrases' => [['text' => 'Kind regards']]])->assertRedirect();
    $live = VoiceVersion::find(AgentSetting::forWorkspace($f['workspace']->id)->live_voice_version_id);
    expect($live->personality)->toBe('Brisk')->and($live->version)->toBe(2);
    $this->actingAs($admin)->post("/agent/voice/versions/{$f['voice']->id}/restore")->assertRedirect();
    expect(VoiceVersion::find(AgentSetting::forWorkspace($f['workspace']->id)->live_voice_version_id)->restored_from_id)->toBe($f['voice']->id);

    $this->actingAs($admin)->post("/agent/playbooks/{$f['playbook']->id}/versions", [
        'when_text' => 'Order status questions',
        'rules' => [['id' => $f['playbook']->liveVersion->rules[0]['id'], 'if' => 'always', 'then' => 'Be quick.']],
    ])->assertRedirect();
    expect($f['playbook']->fresh()->liveVersion->version)->toBe(2);

    // Auto mode needs an explicit confirmation and the preconditions (tests/Feature/Agent/AutoSend).
    $this->actingAs($admin)->patch("/agent/playbooks/{$f['playbook']->id}", ['mode' => 'auto'])->assertSessionHasErrors('confirm_auto');
    $this->actingAs($admin)->patch("/agent/playbooks/{$f['playbook']->id}", ['mode' => 'auto', 'confirm_auto' => true])->assertSessionHasErrors('mode');
    $this->actingAs($admin)->patch("/agent/playbooks/{$f['playbook']->id}", ['mode' => 'draft', 'rollout_percent' => 30])->assertRedirect();
    expect($f['playbook']->fresh()->rollout_percent)->toBe(30);

    $this->actingAs($admin)->post('/agent/playbooks', ['name' => 'Warranty claims', 'mode' => 'draft', 'when_text' => 'Warranty questions', 'rules' => [['then' => 'Warranty is 12 months.']]])
        ->assertRedirect();
    expect(Playbook::where('workspace_id', $f['workspace']->id)->where('slug', 'warranty_claims')->exists())->toBeTrue();

    // Knowledge: scope is required; scoping a needs_scope item activates it.
    $this->actingAs($admin)->patch("/agent/knowledge/{$f['item']->id}", ['kind' => 'fact', 'body' => 'Closed Friday', 'scope' => ['global' => false]])
        ->assertSessionHasErrors('scope');
    $this->actingAs($admin)->patch("/agent/knowledge/{$f['item']->id}", ['kind' => 'fact', 'body' => 'Closed Friday', 'scope' => ['global' => false, 'playbooks' => ['order_status']]])
        ->assertRedirect();
    expect($f['item']->fresh()->status)->toBe('active');

    $this->actingAs($admin)->post('/agent/knowledge', ['kind' => 'policy', 'title' => 'Returns', 'body' => '30 days', 'scope' => ['global' => true]])->assertRedirect();
    expect(KnowledgeItem::where('title', 'Returns')->sole()->scope['global'])->toBeTrue();
});

test('any agent who can view the conversation can read its latest run', function () {
    $case = agentTCase('Where is it?');
    $agent = User::factory()->create(['workspace_id' => $case['workspace']->id, 'role' => 'agent']);
    $this->getJson("/agent/conversations/{$case['conversation']->id}/run")->assertUnauthorized();
    AgentRun::create([
        'workspace_id' => $case['workspace']->id, 'purpose' => 'live', 'conversation_id' => $case['conversation']->id,
        'idempotency_key' => 'live:x', 'status' => 'completed', 'config' => ['models' => ['writer' => 'claude-opus-5-5']], 'config_hash' => 'abc',
        'decision' => 'draft', 'draft' => 'Hello', 'claims' => [['text' => 'Order paid', 'sources' => ['E1', 'K9']]],
        'evidence' => ['facts' => [['id' => 'E1', 'source' => 'shopify', 'key' => 'shopify.payment_status', 'label' => 'Payment status', 'value' => 'PAID']], 'knowledge' => [['id' => 'K9', 'kind' => 'policy', 'title' => 'Pay', 'body' => 'Paid orders ship.', 'scope' => []]], 'meta' => ['shopify_payload' => ['secret' => 1]]],
        'tokens_in' => 10, 'tokens_out' => 5, 'cost_micro_usd' => 1500,
    ]);

    $this->actingAs($agent)->getJson("/agent/conversations/{$case['conversation']->id}/run")
        ->assertOk()
        ->assertJsonPath('run.decision', 'draft')
        ->assertJsonPath('run.claims.0.sources.0.type', 'evidence')
        ->assertJsonPath('run.claims.0.sources.0.value', 'PAID')
        ->assertJsonPath('run.claims.0.sources.1.type', 'knowledge')
        ->assertJsonPath('run.claims.0.sources.1.knowledge_item_id', 9)
        ->assertJsonPath('run.cost_usd', 0.0015)
        ->assertJsonPath('run.tokens.in', 10)
        ->assertJsonMissingPath('run.evidence.meta.shopify_payload');

    $outsider = agentUser();
    $this->actingAs($outsider)->getJson("/agent/conversations/{$case['conversation']->id}/run")->assertForbidden();
});

test('regenerate queues a forced run for agents who can update the conversation', function () {
    Queue::fake();
    $case = agentTCase('Where is it?');
    $agent = User::factory()->create(['workspace_id' => $case['workspace']->id, 'role' => 'agent']);
    $this->postJson("/agent/conversations/{$case['conversation']->id}/regenerate")->assertUnauthorized();

    $this->actingAs($agent)->postJson("/agent/conversations/{$case['conversation']->id}/regenerate")->assertStatus(202);
    Queue::assertPushed(GenerateReplySuggestionJob::class, fn ($job) => $job->regenerateToken !== null);

    AgentSetting::forWorkspace($case['workspace']->id)->update(['mode' => 'off']);
    $this->actingAs($agent)->postJson("/agent/conversations/{$case['conversation']->id}/regenerate")->assertStatus(409);

    $this->actingAs(agentUser())->postJson("/agent/conversations/{$case['conversation']->id}/regenerate")->assertForbidden();
});
