<?php

require_once __DIR__.'/AgentTestHelpers.php';
require_once __DIR__.'/LoopTestHelpers.php';

use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\Proposal;
use App\Domains\Agent\Models\Simulation;
use App\Domains\Agent\Models\VoiceVersion;
use App\Domains\Agent\Proposals\ProposalService;
use App\Models\User;

/*
 * Partial approval: a reviewer leaves single changes or ops out of a
 * proposal; what is left is re-validated and re-checked, and the applied
 * summary records what was left out.
 */

/** @return array<string, mixed> */
function exclTFixture(): array
{
    $f = loopTWorkspace();
    loopTFakeConflictJudge();
    $f['admin'] = adminUser($f['workspace']);
    $f['proposal'] = app(ProposalService::class)->createProposal($f['workspace']->id, 'mining', 'Mined changes', null, [
        ['target_type' => 'voice', 'ops' => [['op' => 'add_do', 'text' => 'Thank them for their patience'], ['op' => 'add_dont', 'text' => 'Per my last email']]],
        ['target_type' => 'playbook', 'target_id' => $f['playbook']->id, 'ops' => [['op' => 'add_rule', 'then' => 'Share the tracking link when there is one.']]],
    ]);

    return $f;
}

function exclTUrl(Proposal $p, string $action): string
{
    return "/agent/proposals/{$p->id}/{$action}";
}

test('excluding a change and a single op keeps the rest, records both and resets the review', function () {
    $f = exclTFixture();
    $p = $f['proposal'];
    [$voice, $playbook] = $p->changes->all();
    $p->forceFill(['conflicts' => ['items' => []], 'conflicts_checked_at' => now(), 'status' => 'ready'])->save();

    $res = $this->actingAs($f['admin'])->postJson(exclTUrl($p, 'exclude'), [
        'change_ids' => [$playbook->id],
        'ops' => [['change_id' => $voice->id, 'op_index' => 1]],
        'note' => 'Not this part',
        'recheck' => false,
    ])->assertOk();

    $fresh = $p->fresh('changes');
    expect($fresh->changes)->toHaveCount(1)
        ->and($fresh->changes[0]->target_type)->toBe('voice')
        ->and(collect($fresh->changes[0]->ops)->pluck('op')->all())->toBe(['add_do'])
        ->and($fresh->status)->toBe('draft')
        ->and($fresh->conflicts_checked_at)->toBeNull()
        ->and($fresh->excluded)->toHaveCount(2);

    $excluded = collect($res->json('proposal.excluded'));
    expect($excluded->pluck('kind')->sort()->values()->all())->toBe(['change', 'op'])
        ->and($excluded->firstWhere('kind', 'change')['target_type'])->toBe('playbook')
        ->and($excluded->firstWhere('kind', 'change')['ops'][0]['then'])->toBe('Share the tracking link when there is one.')
        ->and($excluded->firstWhere('kind', 'op')['ops'][0]['text'])->toBe('Per my last email')
        ->and($excluded->firstWhere('kind', 'op')['note'])->toBe('Not this part')
        ->and($excluded->firstWhere('kind', 'op')['excluded_by'])->toBe($f['admin']->id)
        ->and($res->json('proposal.can_approve'))->toBeFalse();
});

test('approving after exclusions writes only what is left and the applied summary lists what was left out', function () {
    $f = exclTFixture();
    $p = $f['proposal'];
    [$voice, $playbook] = $p->changes->all();
    $liveVersion = $f['playbook']->fresh()->live_version_id;

    $this->actingAs($f['admin'])->postJson(exclTUrl($p, 'exclude'), ['change_ids' => [$playbook->id], 'ops' => [['change_id' => $voice->id, 'op_index' => 1]]])
        ->assertOk()
        ->assertJsonPath('proposal.conflicts.summary.blocking', 0);

    $res = $this->actingAs($f['admin'])->postJson(exclTUrl($p, 'approve'))->assertOk()->assertJsonPath('proposal.status', 'applied');

    $live = VoiceVersion::find(AgentSetting::forWorkspace($f['workspace']->id)->live_voice_version_id);
    expect(collect($live->do_phrases)->pluck('text')->all())->toContain('Thank them for their patience')
        ->and(collect($live->dont_phrases)->pluck('text')->all())->not->toContain('Per my last email')
        ->and($f['playbook']->fresh()->live_version_id)->toBe($liveVersion)
        ->and($res->json('proposal.applied.excluded'))->toHaveCount(2)
        ->and($res->json('proposal.applied.playbooks'))->toBe([]);
});

test('excluded items can be restored, one or all', function () {
    $f = exclTFixture();
    $p = $f['proposal'];
    [$voice, $playbook] = $p->changes->all();

    $this->actingAs($f['admin'])->postJson(exclTUrl($p, 'exclude'), ['change_ids' => [$playbook->id], 'ops' => [['change_id' => $voice->id, 'op_index' => 0]], 'recheck' => false])->assertOk();
    $opEntry = collect($p->fresh()->excluded)->firstWhere('kind', 'op');

    $this->actingAs($f['admin'])->postJson(exclTUrl($p, 'exclusions/restore'), ['ids' => [$opEntry['id']], 'recheck' => false])->assertOk();
    $fresh = $p->fresh('changes');
    expect($fresh->changes)->toHaveCount(1)
        ->and(collect($fresh->changes[0]->ops)->pluck('op')->sort()->values()->all())->toBe(['add_do', 'add_dont'])
        ->and($fresh->excluded)->toHaveCount(1);

    $this->actingAs($f['admin'])->postJson(exclTUrl($p, 'exclusions/restore'), ['recheck' => false])->assertOk()->assertJsonPath('proposal.excluded', []);
    expect($p->fresh('changes')->changes->pluck('target_type')->all())->toBe(['voice', 'playbook']);
});

test('excluding the op that caused a blocking conflict clears it on the re-check', function () {
    $f = loopTWorkspace();
    loopTFakeConflictJudge();
    $admin = adminUser($f['workspace']);
    $p = app(ProposalService::class)->createProposal($f['workspace']->id, 'mining', 'mixed', null, [
        ['target_type' => 'voice', 'ops' => [['op' => 'add_do', 'text' => 'Cheers mate'], ['op' => 'add_dont', 'text' => 'Cheers mate']]],
    ]);
    $this->actingAs($admin)->postJson(exclTUrl($p, 'check'))->assertOk();
    expect($p->fresh()->blockingConflicts())->not->toBe([]);

    $change = $p->fresh('changes')->changes[0];
    $this->actingAs($admin)->postJson(exclTUrl($p, 'exclude'), ['ops' => [['change_id' => $change->id, 'op_index' => 1]]])
        ->assertOk()
        ->assertJsonPath('proposal.blocking_conflicts', 0)
        ->assertJsonPath('proposal.can_approve', true);
});

test('excluding everything, or something that is not in the proposal, is refused', function () {
    $f = exclTFixture();
    $p = $f['proposal'];
    $ids = $p->changes->pluck('id')->all();

    $this->actingAs($f['admin'])->postJson(exclTUrl($p, 'exclude'), ['change_ids' => $ids])->assertStatus(422)->assertJsonPath('reason', 'invalid_remainder');
    $this->actingAs($f['admin'])->postJson(exclTUrl($p, 'exclude'), ['change_ids' => [999999]])->assertStatus(422);
    $this->actingAs($f['admin'])->postJson(exclTUrl($p, 'exclude'), ['ops' => [['change_id' => $ids[0], 'op_index' => 9]]])->assertStatus(422);
    $this->actingAs($f['admin'])->postJson(exclTUrl($p, 'exclude'), [])->assertStatus(422);
    expect($p->fresh('changes')->changes)->toHaveCount(2)->and($p->fresh()->excluded)->toBeNull();
});

test('exclusions wait for a running gate, need an open proposal and an admin', function () {
    $f = exclTFixture();
    $p = $f['proposal'];
    $change = $p->changes[1];

    $gate = Simulation::forceCreate(['workspace_id' => $f['workspace']->id, 'kind' => 'gate', 'status' => 'running', 'candidate_config_hash' => str_repeat('c', 64), 'cases' => [], 'proposal_id' => $p->id]);
    $p->forceFill(['gate_simulation_id' => $gate->id, 'status' => 'testing'])->save();
    $this->actingAs($f['admin'])->postJson(exclTUrl($p, 'exclude'), ['change_ids' => [$change->id]])->assertStatus(409)->assertJsonPath('reason', 'gate_running');

    $p->forceFill(['gate_simulation_id' => null, 'status' => 'rejected'])->save();
    $this->actingAs($f['admin'])->postJson(exclTUrl($p, 'exclude'), ['change_ids' => [$change->id]])->assertStatus(409)->assertJsonPath('reason', 'not_open');

    $agent = User::factory()->create(['workspace_id' => $f['workspace']->id, 'role' => 'agent']);
    $this->actingAs($agent)->postJson(exclTUrl($p, 'exclude'), ['change_ids' => [$change->id]])->assertForbidden();
    $this->actingAs(adminUser())->postJson(exclTUrl($p, 'exclude'), ['change_ids' => [$change->id]])->assertNotFound();
});
