<?php

require_once __DIR__.'/AgentTestHelpers.php';
require_once __DIR__.'/LoopTestHelpers.php';

use App\Domains\Agent\Models\KnowledgeItem;
use App\Domains\Agent\Models\Proposal;
use App\Domains\Agent\Models\Simulation;
use App\Domains\Agent\Proposals\ProposalService;
use App\Models\User;

/*
 * A reviewer rewords one item of an open proposal before approving it:
 * the title / body of new knowledge, or the if / then of a playbook rule.
 * Only those text fields change; the proposal is re-validated and re-checked.
 */

/** @return array<string, mixed> */
function editTFixture(): array
{
    $f = loopTWorkspace();
    loopTFakeConflictJudge();
    $f['admin'] = adminUser($f['workspace']);
    $f['proposal'] = app(ProposalService::class)->createProposal($f['workspace']->id, 'mining', 'Mining: inconsistencies to decide (1)', null, [
        ['target_type' => 'knowledge', 'ops' => [['op' => 'create_knowledge', 'kind' => 'policy', 'title' => 'Refund window', 'body' => 'Refunds within 30 days.', 'scope' => ['global' => true]]]],
        ['target_type' => 'playbook', 'target_id' => $f['playbook']->id, 'ops' => [
            ['op' => 'add_rule', 'if' => 'the parcel is late', 'then' => 'Share the tracking link.'],
            ['op' => 'add_step', 'text' => 'Check the tracking page first.'],
        ]],
    ], ['inconsistencies' => [
        ['topic' => 'refund_window', 'summary' => 'Two answers were given.', 'knowledge' => ['title' => 'Refund window', 'body' => 'Refunds within 30 days.']],
        ['topic' => 'dispatch_estimates', 'summary' => 'Unrelated.', 'knowledge' => ['title' => 'Dispatch', 'body' => 'Ships in two days.']],
    ]]);

    return $f;
}

function editTUrl(Proposal $p, int $changeId): string
{
    return "/agent/proposals/{$p->id}/changes/{$changeId}";
}

test('editing new knowledge rewrites only its title and body, restarts the review and keeps its evidence link', function () {
    $f = editTFixture();
    $p = $f['proposal'];
    [$knowledge, $playbook] = $p->changes->all();
    $p->forceFill(['conflicts' => ['items' => []], 'conflicts_checked_at' => now()->subHour(), 'status' => 'ready'])->save();

    $res = $this->actingAs($f['admin'])->postJson(editTUrl($p, $knowledge->id), [
        'title' => 'Refunds',
        'body' => '  Refunds within 14 days of delivery.  ',
        // Not text a reviewer may change here: ignored.
        'kind' => 'fact',
        'scope' => ['global' => false],
        'op' => 'retire_knowledge',
    ])->assertOk();

    $fresh = $p->fresh('changes');
    $op = $fresh->changes[0]->ops[0];
    expect($fresh->changes)->toHaveCount(2)
        ->and($op['op'])->toBe('create_knowledge')
        ->and($op['title'])->toBe('Refunds')
        ->and($op['body'])->toBe('Refunds within 14 days of delivery.')
        ->and($op['kind'])->toBe('policy')
        ->and($op['scope']['global'])->toBeTrue()
        ->and($fresh->changes[0]->after['body'])->toBe('Refunds within 14 days of delivery.')
        ->and($fresh->changes[0]->diff[0]['after'])->toBe('Refunds within 14 days of delivery.')
        // The other change is untouched.
        ->and($fresh->changes[1]->ops)->toEqual($playbook->ops)
        // Back to draft and checked again for what it says now.
        ->and($fresh->status)->toBe('draft')
        ->and($fresh->conflicts_checked_at->gt(now()->subMinute()))->toBeTrue()
        ->and($fresh->excluded)->toBeNull()
        ->and($fresh->evidence['inconsistencies'][0]['knowledge'])->toEqual(['title' => 'Refunds', 'body' => 'Refunds within 14 days of delivery.'])
        ->and($fresh->evidence['inconsistencies'][1]['knowledge'])->toEqual(['title' => 'Dispatch', 'body' => 'Ships in two days.']);

    expect($res->json('proposal.changes.0.target_label'))->toBe('New knowledge: Refunds')
        ->and($res->json('proposal.can_approve'))->toBeTrue();

    // Approving writes the edited wording.
    $this->actingAs($f['admin'])->postJson("/agent/proposals/{$p->id}/approve")->assertOk()->assertJsonPath('proposal.status', 'applied');
    expect(KnowledgeItem::where('workspace_id', $f['workspace']->id)->where('body', 'Refunds within 14 days of delivery.')->where('title', 'Refunds')->exists())->toBeTrue()
        ->and(KnowledgeItem::where('workspace_id', $f['workspace']->id)->where('body', 'Refunds within 30 days.')->exists())->toBeFalse();
});

test('editing a playbook rule rewrites its if and then and leaves the other ops alone', function () {
    $f = editTFixture();
    $p = $f['proposal'];
    $playbook = $p->changes[1];

    $this->actingAs($f['admin'])->postJson(editTUrl($p, $playbook->id), [
        'op_index' => 0,
        'if' => 'the parcel is more than three days late',
        'then' => 'Share the tracking link and apologise.',
        'recheck' => false,
    ])->assertOk();

    $ops = $p->fresh('changes')->changes[1]->ops;
    expect($ops[0]['op'])->toBe('add_rule')
        ->and($ops[0]['if'])->toBe('the parcel is more than three days late')
        ->and($ops[0]['then'])->toBe('Share the tracking link and apologise.')
        ->and($ops[0]['id'])->toBe($playbook->ops[0]['id'])
        ->and($ops[1])->toEqual($playbook->ops[1])
        ->and($p->fresh()->conflicts_checked_at)->toBeNull();

    // An empty condition means "always".
    $change = $p->fresh('changes')->changes[1];
    $this->actingAs($f['admin'])->postJson(editTUrl($p, $change->id), ['if' => null, 'then' => 'Share the tracking link.', 'recheck' => false])->assertOk();
    expect($p->fresh('changes')->changes[1]->ops[0]['if'])->toBe('always');
});

test('an edit that changes nothing leaves the proposal as it was', function () {
    $f = editTFixture();
    $p = $f['proposal'];
    $p->forceFill(['conflicts' => ['items' => []], 'conflicts_checked_at' => now(), 'status' => 'ready'])->save();
    $knowledge = $p->changes[0];

    $this->actingAs($f['admin'])->postJson(editTUrl($p, $knowledge->id), ['title' => 'Refund window', 'body' => 'Refunds within 30 days.'])->assertOk();

    expect($p->fresh()->status)->toBe('ready')
        ->and($p->fresh('changes')->changes[0]->id)->toBe($knowledge->id);
});

test('edits that do not make sense are refused and change nothing', function () {
    $f = editTFixture();
    $p = $f['proposal'];
    [$knowledge, $playbook] = $p->changes->all();
    $admin = $f['admin'];

    // Text is required.
    $this->actingAs($admin)->postJson(editTUrl($p, $knowledge->id), ['title' => 'Refunds', 'body' => ''])->assertStatus(422)->assertJsonValidationErrors('body');
    $this->actingAs($admin)->postJson(editTUrl($p, $playbook->id), ['then' => ''])->assertStatus(422)->assertJsonValidationErrors('then');
    $this->actingAs($admin)->postJson(editTUrl($p, $knowledge->id), ['body' => str_repeat('a', 20001)])->assertStatus(422);
    // A step is not editable here; neither is an op or a change that is not there.
    $this->actingAs($admin)->postJson(editTUrl($p, $playbook->id), ['op_index' => 1, 'then' => 'Something else'])->assertStatus(422)->assertJsonPath('reason', 'invalid_edit');
    $this->actingAs($admin)->postJson(editTUrl($p, $playbook->id), ['op_index' => 7, 'then' => 'Something else'])->assertStatus(422)->assertJsonPath('reason', 'invalid_edit');
    $this->actingAs($admin)->postJson(editTUrl($p, 999999), ['body' => 'Something else'])->assertStatus(422)->assertJsonPath('reason', 'invalid_edit');
    // A rule that already exists in the live playbook does not validate.
    $existing = $f['playbook']->liveVersion->rules[0];
    $this->actingAs($admin)->postJson(editTUrl($p, $playbook->id), ['if' => $existing['if'], 'then' => $existing['then']])
        ->assertStatus(422)->assertJsonPath('reason', 'invalid_edit')->assertJsonStructure(['errors' => ['ops'], 'proposal']);

    $fresh = $p->fresh('changes');
    expect($fresh->changes->pluck('id')->all())->toBe([$knowledge->id, $playbook->id])
        ->and($fresh->changes[0]->ops[0]['body'])->toBe('Refunds within 30 days.')
        ->and($fresh->changes[1]->ops[0]['then'])->toBe('Share the tracking link.');
});

test('editing waits for a running gate, needs an open proposal and an admin of the workspace', function () {
    $f = editTFixture();
    $p = $f['proposal'];
    $knowledge = $p->changes[0];
    $body = ['body' => 'Refunds within 14 days.'];

    $this->postJson(editTUrl($p, $knowledge->id), $body)->assertUnauthorized();
    $agent = User::factory()->create(['workspace_id' => $f['workspace']->id, 'role' => 'agent']);
    $this->actingAs($agent)->postJson(editTUrl($p, $knowledge->id), $body)->assertForbidden();
    $this->actingAs(adminUser())->postJson(editTUrl($p, $knowledge->id), $body)->assertNotFound();

    $gate = Simulation::forceCreate(['workspace_id' => $f['workspace']->id, 'kind' => 'gate', 'status' => 'running', 'candidate_config_hash' => str_repeat('c', 64), 'cases' => [], 'proposal_id' => $p->id]);
    $p->forceFill(['gate_simulation_id' => $gate->id, 'status' => 'testing'])->save();
    $this->actingAs($f['admin'])->postJson(editTUrl($p, $knowledge->id), $body)->assertStatus(409)->assertJsonPath('reason', 'gate_running');

    $p->forceFill(['gate_simulation_id' => null, 'status' => 'applied'])->save();
    $this->actingAs($f['admin'])->postJson(editTUrl($p, $knowledge->id), $body)->assertStatus(409)->assertJsonPath('reason', 'not_open');

    expect($p->fresh('changes')->changes[0]->ops[0]['body'])->toBe('Refunds within 30 days.');
});
