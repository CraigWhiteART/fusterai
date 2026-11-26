<?php

require_once __DIR__.'/AgentTestHelpers.php';
require_once __DIR__.'/LoopTestHelpers.php';

use App\Domains\Agent\Models\FeedbackEvent;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Models\Simulation;
use App\Domains\Agent\Models\SimulationResult;
use App\Domains\Agent\Proposals\ProposalService;
use App\Domains\Agent\Simulation\TestCaseService;
use App\Models\User;

/*
 * Endpoints the inbox / review screens asked for: the feedback state of a
 * conversation, last results on test cases, playbook filters.
 */

test('GET conversation feedback returns its events and linked proposals, latest first', function () {
    $f = loopTWorkspace();
    $run = loopTLiveRun($f);
    $ws = $f['workspace']->id;
    $older = FeedbackEvent::create(['workspace_id' => $ws, 'run_id' => $run->id, 'conversation_id' => $f['conversation']->id, 'kind' => 'sent_as_is', 'text' => null]);
    $proposal = app(ProposalService::class)->createProposal($ws, 'feedback', 'Be warm', null,
        [['target_type' => 'voice', 'ops' => [['op' => 'add_do', 'text' => 'Be warm']]]], [], null, ['conversation_id' => $f['conversation']->id, 'run_id' => $run->id]);
    $newer = FeedbackEvent::create(['workspace_id' => $ws, 'run_id' => $run->id, 'conversation_id' => $f['conversation']->id, 'kind' => 'comment', 'text' => 'Too cold',
        'meta' => ['routing' => ['status' => 'proposal', 'scope' => 'voice'], 'proposal_id' => $proposal->id]]);
    $proposal->forceFill(['feedback_event_id' => $newer->id])->save();
    $qa = app(ProposalService::class)->createProposal($ws, 'qa', 'QA fix', null,
        [['target_type' => 'voice', 'ops' => [['op' => 'add_dont', 'text' => 'Per my last email']]]], [], null, ['conversation_id' => $f['conversation']->id]);
    // Another conversation's feedback is not included.
    $other = loopTWorkspace();
    FeedbackEvent::create(['workspace_id' => $other['workspace']->id, 'conversation_id' => $other['conversation']->id, 'kind' => 'comment', 'text' => 'x']);

    $agent = User::factory()->create(['workspace_id' => $ws, 'role' => 'admin']);
    $res = $this->actingAs($agent)->getJson("/agent/conversations/{$f['conversation']->id}/feedback")->assertOk();

    expect($res->json('feedback.*.id'))->toBe([$newer->id, $older->id])
        ->and($res->json('feedback.0.status'))->toBe('proposal')
        ->and($res->json('feedback.0.proposal.id'))->toBe($proposal->id)
        ->and($res->json('proposals.*.id'))->toBe([$qa->id, $proposal->id]);

    $this->actingAs($agent)->getJson("/agent/conversations/{$f['conversation']->id}/feedback?kind=comment")->assertOk()->assertJsonCount(1, 'feedback');
    $this->actingAs(adminUser())->getJson("/agent/conversations/{$f['conversation']->id}/feedback")->assertForbidden();
});

test('test cases carry their last result; simulations filter by playbook; result rows name the playbook', function () {
    $f = loopTWorkspace();
    $ws = $f['workspace']->id;
    $admin = adminUser($f['workspace']);
    $case = loopTFrozenCase($f);
    $test = app(TestCaseService::class)->create($case, 'Late order', [], $f['playbook']->id);
    $untested = app(TestCaseService::class)->create(loopTCloneCase($case, 'b'), 'Never run', [], $f['playbook']->id);
    $other = Playbook::where('workspace_id', $ws)->where('slug', 'refund_request')->sole();

    $old = Simulation::forceCreate(['workspace_id' => $ws, 'kind' => 'playbook_tests', 'status' => 'completed', 'playbook_id' => $f['playbook']->id, 'candidate_config_hash' => str_repeat('a', 64), 'cases' => []]);
    $new = Simulation::forceCreate(['workspace_id' => $ws, 'kind' => 'playbook_tests', 'status' => 'completed', 'playbook_id' => $f['playbook']->id, 'candidate_config_hash' => str_repeat('a', 64), 'cases' => []]);
    Simulation::forceCreate(['workspace_id' => $ws, 'kind' => 'playbook_tests', 'status' => 'completed', 'playbook_id' => $other->id, 'candidate_config_hash' => str_repeat('a', 64), 'cases' => []]);
    SimulationResult::forceCreate(['simulation_id' => $old->id, 'frozen_case_id' => $case->id, 'test_case_id' => $test->id, 'verdict' => 'pass']);
    $latest = SimulationResult::forceCreate(['simulation_id' => $new->id, 'frozen_case_id' => $case->id, 'test_case_id' => $test->id, 'verdict' => 'fail', 'human_override' => 'pass']);

    $list = $this->actingAs($admin)->getJson('/agent/test-cases?playbook_id='.$f['playbook']->id)->assertOk();
    $byId = collect($list->json('data'))->keyBy('id');
    expect($byId[$test->id]['last_result'])->toMatchArray(['verdict' => 'pass', 'raw_verdict' => 'fail', 'simulation_id' => $new->id, 'result_id' => $latest->id])
        ->and($byId[$test->id]['last_result']['at'])->not->toBeNull()
        ->and($byId[$untested->id]['last_result'])->toBeNull();
    $this->actingAs($admin)->getJson("/agent/test-cases/{$test->id}")->assertOk()->assertJsonPath('test_case.last_result.simulation_id', $new->id);

    $sims = $this->actingAs($admin)->getJson('/agent/simulations?playbook_id='.$f['playbook']->id)->assertOk();
    expect($sims->json('data.*.id'))->toBe([$new->id, $old->id]);

    $this->actingAs($admin)->getJson("/agent/simulations/{$new->id}/results")->assertOk()
        ->assertJsonPath('data.0.playbook', ['id' => $f['playbook']->id, 'slug' => $f['playbook']->slug, 'name' => $f['playbook']->name])
        ->assertJsonPath('data.0.ai_playbook', null);
});

test('the playbook options list is small, workspace-scoped and hides archived ones by default', function () {
    $f = loopTWorkspace();
    $ws = $f['workspace']->id;
    $archived = Playbook::where('workspace_id', $ws)->where('slug', 'refund_request')->sole();
    $archived->forceFill(['archived_at' => now()])->save();
    $agent = User::factory()->create(['workspace_id' => $ws, 'role' => 'agent']);

    $res = $this->actingAs($agent)->getJson('/agent/playbooks/options')->assertOk();
    $row = collect($res->json('data'))->firstWhere('id', $f['playbook']->id);
    expect($row)->toBe(['id' => $f['playbook']->id, 'slug' => $f['playbook']->slug, 'name' => $f['playbook']->name, 'mode' => $f['playbook']->mode, 'archived' => false])
        ->and(collect($res->json('data'))->pluck('id'))->not->toContain($archived->id);

    $all = $this->actingAs($agent)->getJson('/agent/playbooks/options?include_archived=1')->assertOk();
    expect(collect($all->json('data'))->pluck('id'))->toContain($archived->id);

    $foreign = $this->actingAs(adminUser())->getJson('/agent/playbooks/options')->assertOk();
    expect(collect($foreign->json('data'))->pluck('id'))->not->toContain($f['playbook']->id);
});
