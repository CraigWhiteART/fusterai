<?php

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\FeedbackEvent;
use App\Domains\Agent\Models\KnowledgeItem;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Pipeline\HandoverReasons;
use App\Domains\Agent\Services\AgentBootstrapper;
use App\Domains\Agent\Tools\HandoverTool;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

function agentTOverviewRun(int $workspaceId, array $attributes): AgentRun
{
    $createdAt = $attributes['created_at'] ?? now();
    unset($attributes['created_at']);

    $run = AgentRun::create(array_merge([
        'workspace_id' => $workspaceId,
        'purpose' => AgentRun::PURPOSE_LIVE,
        'idempotency_key' => (string) Str::uuid(),
        'status' => AgentRun::STATUS_COMPLETED,
        'config' => [],
        'config_hash' => str_repeat('a', 64),
        'cost_micro_usd' => 0,
    ], $attributes));
    $run->forceFill(['created_at' => $createdAt])->save();

    return $run;
}

test('the overview is admin only', function () {
    $workspace = Workspace::factory()->create();
    $agent = User::factory()->create(['workspace_id' => $workspace->id, 'role' => 'agent']);

    $this->get('/agent')->assertRedirect('/login');
    $this->actingAs($agent)->get('/agent')->assertForbidden();
});

test('the overview summarises mode, instructions, runs, feedback and cost for the workspace', function () {
    $workspace = Workspace::factory()->create();
    $admin = User::factory()->create(['workspace_id' => $workspace->id, 'role' => 'admin']);
    app(AgentBootstrapper::class)->ensureForWorkspace($workspace->id);
    AgentSetting::forWorkspace($workspace->id)->update(['mode' => 'draft', 'kill_switch' => true]);

    $playbooks = Playbook::where('workspace_id', $workspace->id)->whereNull('archived_at')->count();
    Playbook::where('workspace_id', $workspace->id)->orderBy('id')->first()->update(['mode' => Playbook::MODE_OFF]);

    $scope = ['playbooks' => [], 'products' => [], 'countries' => [], 'global' => true];
    $knowledgeBefore = KnowledgeItem::where('workspace_id', $workspace->id)->count();
    $activeBefore = KnowledgeItem::where('workspace_id', $workspace->id)->where('status', 'active')->count();
    KnowledgeItem::create(['workspace_id' => $workspace->id, 'kind' => 'fact', 'body' => 'Open weekdays', 'status' => 'active', 'scope' => $scope]);
    KnowledgeItem::create(['workspace_id' => $workspace->id, 'kind' => 'fact', 'body' => 'Closed Friday', 'status' => 'needs_scope',
        'scope' => ['playbooks' => [], 'products' => [], 'countries' => [], 'global' => false]]);

    // In the window: 2 drafts, 3 handovers (2 no_instruction, 1 risk), 1 failed shadow run, 1 simulation (cost only).
    agentTOverviewRun($workspace->id, ['decision' => 'draft', 'cost_micro_usd' => 20_000]);
    agentTOverviewRun($workspace->id, ['decision' => 'draft', 'cost_micro_usd' => 10_000, 'created_at' => now()->subDays(2)]);
    agentTOverviewRun($workspace->id, ['decision' => 'handover', 'handover_reason' => 'no_instruction']);
    agentTOverviewRun($workspace->id, ['decision' => 'handover', 'handover_reason' => 'no_instruction', 'created_at' => now()->subDay()]);
    agentTOverviewRun($workspace->id, ['decision' => 'handover', 'handover_reason' => 'risk:legal']);
    agentTOverviewRun($workspace->id, ['purpose' => AgentRun::PURPOSE_SHADOW, 'status' => AgentRun::STATUS_FAILED, 'decision' => null]);
    agentTOverviewRun($workspace->id, ['purpose' => AgentRun::PURPOSE_SIMULATION, 'decision' => 'draft', 'cost_micro_usd' => 5_000]);
    // Outside the window, and another workspace: ignored.
    agentTOverviewRun($workspace->id, ['decision' => 'draft', 'cost_micro_usd' => 999_000, 'created_at' => now()->subDays(10)]);
    $other = Workspace::factory()->create();
    agentTOverviewRun($other->id, ['decision' => 'handover', 'handover_reason' => 'no_instruction', 'cost_micro_usd' => 1_000_000]);

    foreach (['sent_as_is', 'sent_as_is', 'sent_as_is', 'sent_edited', 'gmail_reply'] as $kind) {
        FeedbackEvent::create(['workspace_id' => $workspace->id, 'kind' => $kind, 'percent_changed' => $kind === 'sent_edited' ? 30 : null]);
    }
    FeedbackEvent::create(['workspace_id' => $other->id, 'kind' => 'sent_edited', 'percent_changed' => 90]);

    $this->actingAs($admin)
        ->get('/agent')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Agent/Overview')
            ->where('settings.mode', 'draft')
            ->where('settings.kill_switch', true)
            ->where('counts.playbooks_total', $playbooks)
            ->where('counts.playbooks_active', Playbook::where('workspace_id', $workspace->id)->whereNull('archived_at')->where('mode', '!=', 'off')->count())
            ->where('counts.knowledge_total', $knowledgeBefore + 2)
            ->where('counts.knowledge_active', $activeBefore + 1)
            ->where('counts.knowledge_needs_scope', KnowledgeItem::where('workspace_id', $workspace->id)->where('status', 'needs_scope')->count())
            ->where('runs.total', 6)
            ->where('runs.live', 5)
            ->where('runs.shadow', 1)
            ->where('runs.draft', 2)
            ->where('runs.handover', 3)
            ->where('runs.failed', 1)
            ->where('runs.top_handover_reasons.0.reason', 'no_instruction')
            ->where('runs.top_handover_reasons.0.count', 2)
            ->where('runs.top_handover_reasons.0.label', 'no playbook covers this situation')
            ->has('runs.top_handover_reasons', 2)
            ->has('runs.daily', 7)
            ->where('runs.daily.6.date', now()->toDateString())
            ->where('runs.daily.6.draft', 1)
            ->where('runs.daily.6.handover', 2)
            ->where('feedback.sent_as_is', 3)
            ->where('feedback.sent_edited', 1)
            ->where('feedback.gmail_reply', 1)
            ->where('feedback.edit_rate', fn ($rate) => abs((float) $rate - 0.25) < 0.0001)
            ->where('feedback.avg_percent_changed', 30)
            ->where('cost.total_usd', fn ($usd) => abs((float) $usd - 0.035) < 0.00001)
            ->has('cost.by_purpose', 3)
            ->etc());
});

test('the overview handles a workspace with no runs', function () {
    $workspace = Workspace::factory()->create();
    $admin = User::factory()->create(['workspace_id' => $workspace->id, 'role' => 'admin']);

    $this->actingAs($admin)
        ->get('/agent')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Agent/Overview')
            ->where('runs.total', 0)
            ->where('feedback.edit_rate', null)
            ->where('cost.total_usd', fn ($usd) => (float) $usd === 0.0)
            ->where('cost.per_run_usd', null)
            ->where('connections.shopify', false)
            ->etc());
});

test('handover reasons read as plain words, never as codes in brackets', function () {
    $workspace = Workspace::factory()->create();
    $admin = User::factory()->create(['workspace_id' => $workspace->id, 'role' => 'admin']);
    agentTOverviewRun($workspace->id, ['decision' => 'handover', 'handover_reason' => 'writer:writer_error']);
    agentTOverviewRun($workspace->id, ['decision' => 'handover', 'handover_reason' => 'writer:writer_error']);
    agentTOverviewRun($workspace->id, ['decision' => 'handover', 'handover_reason' => 'writer:needs_human_decision']);

    $this->actingAs($admin)->get('/agent')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('runs.top_handover_reasons.0.label', 'the agent could not write a reply because of a technical problem')
        ->where('runs.top_handover_reasons.1.label', 'a person needs to make a decision')
        ->etc());

    foreach (HandoverTool::REASONS as $code) {
        expect(HandoverReasons::label("writer:{$code}"))->not->toContain('(')->not->toContain('_');
    }
    // Codes nobody listed still come out readable.
    expect(HandoverReasons::label('writer:something_new'))->toBe('the agent asked for a person (something new)')
        ->and(HandoverReasons::label('brand_new_reason'))->toBe('brand new reason')
        ->and(HandoverReasons::label('risk:prompt_injection'))->toBe('a risk flag was raised (prompt injection)')
        ->and(HandoverReasons::label('no_instruction'))->toBe('no playbook covers this situation');
});
