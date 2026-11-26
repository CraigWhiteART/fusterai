<?php

require_once __DIR__.'/AgentTestHelpers.php';
require_once __DIR__.'/LoopTestHelpers.php';

use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\AgentTestCase;
use App\Domains\Agent\Models\KnowledgeItem;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Models\Proposal;
use App\Domains\Agent\Models\VoiceVersion;
use App\Domains\Agent\Pipeline\InstructionSet;
use App\Domains\Agent\Proposals\InvalidProposalException;
use App\Domains\Agent\Proposals\ProposalService;
use App\Domains\Agent\Proposals\ProposalStateException;
use App\Domains\Agent\Services\VersionService;

function loopTProposal(int $ws, array $changes, string $origin = 'mining'): Proposal
{
    return app(ProposalService::class)->createProposal($ws, $origin, 'Test change', 'because', $changes, ['conversation_ids' => [1]]);
}

test('voice ops apply with stable ids and a readable diff', function () {
    $f = loopTWorkspace();
    $voice = VoiceVersion::findOrFail(AgentSetting::forWorkspace($f['workspace']->id)->live_voice_version_id);
    $signOff = $voice->sign_offs[0];

    $p = loopTProposal($f['workspace']->id, [['target_type' => 'voice', 'ops' => [
        ['op' => 'add_do', 'text' => 'Thank them for their patience'],
        ['op' => 'add_dont', 'text' => 'guaranteed delivery'],
        ['op' => 'add_greeting', 'text' => 'Hey there'],
        ['op' => 'add_sign_off', 'text' => 'Cheers, Soul Dial'],
        ['op' => 'replace_item', 'field' => 'sign_offs', 'id' => $signOff['id'], 'text' => 'Warm regards'],
        ['op' => 'set_field', 'field' => 'max_words', 'value' => 150],
        ['op' => 'set_field', 'field' => 'personality', 'value' => 'Calm and kind.'],
        ['op' => 'set_field', 'field' => 'length_guidance', 'value' => 'Short.'],
    ]]]);

    $change = $p->changes->sole();
    expect($change->base_version_id)->toBe($voice->id)
        ->and($change->after['dont_phrases'])->toContain(['id' => $change->ops[1]['id'], 'text' => 'guaranteed delivery'])
        ->and($change->ops[1]['id'])->toStartWith('dont-')
        ->and($change->after['max_words'])->toBe(150)
        ->and($change->after['personality'])->toBe('Calm and kind.')
        ->and(collect($change->after['sign_offs'])->firstWhere('id', $signOff['id'])['text'])->toBe('Warm regards')
        ->and(collect($change->diff)->pluck('label')->all())->toContain('Add don\'t phrase', 'Change sign-off', 'Change maximum words')
        ->and($p->status)->toBe('draft')
        ->and($p->base_config_hash)->toHaveLength(64);

    // remove_item
    $p2 = loopTProposal($f['workspace']->id, [['target_type' => 'voice', 'ops' => [
        ['op' => 'remove_item', 'field' => 'sign_offs', 'id' => $signOff['id']],
    ]]]);
    expect(collect($p2->changes->sole()->after['sign_offs'])->pluck('id'))->not->toContain($signOff['id'])
        ->and($p2->changes->sole()->diff[0])->toMatchArray(['op' => 'remove_item', 'before' => $signOff['text'], 'after' => null]);
});

test('playbook ops apply: add, replace, remove rules and steps, set fields, create playbook', function () {
    $f = loopTWorkspace();
    $pb = $f['playbook'];
    $rule = $pb->liveVersion->rules[0];

    $p = loopTProposal($f['workspace']->id, [
        ['target_type' => 'playbook', 'target_id' => $pb->id, 'ops' => [
            ['op' => 'add_rule', 'if' => 'customer asks for a date', 'then' => 'Never promise a delivery date.'],
            ['op' => 'replace_rule', 'id' => $rule['id'], 'then' => 'Quote the status word for word.'],
            ['op' => 'add_step', 'text' => 'Check the carrier.'],
            ['op' => 'set_field', 'field' => 'escalation', 'value' => 'Hand over when stuck.'],
            ['op' => 'set_field', 'field' => 'required_evidence', 'value' => ['order']],
            ['op' => 'set_field', 'field' => 'when_text', 'value' => 'Customer asks where the order is.'],
        ]],
        ['target_type' => 'playbook', 'ops' => [
            ['op' => 'create_playbook', 'slug' => 'warranty_claim', 'name' => 'Warranty claim', 'when_text' => 'Fault, wants repair.',
                'rules' => [['then' => 'Ask for a photo.']], 'steps' => ['Check order age'], 'allowed_actions' => ['refund'], 'mode' => 'auto'],
            ['op' => 'add_rule', 'then' => 'Be kind.'],
        ]],
    ]);

    [$edit, $create] = $p->changes->all();
    $added = collect($edit->after['rules'])->last();
    expect($added['if'])->toBe('customer asks for a date')
        ->and($added['id'])->toStartWith('r-')
        ->and(collect($edit->after['rules'])->firstWhere('id', $rule['id'])['then'])->toBe('Quote the status word for word.')
        ->and($edit->after['escalation'])->toBe('Hand over when stuck.')
        ->and($edit->after['required_evidence'])->toBe(['order'])
        ->and($edit->base_version_id)->toBe($pb->live_version_id)
        ->and($create->target_id)->toBeNull()
        ->and($create->after['slug'])->toBe('warranty_claim')
        ->and($create->after['rules'])->toHaveCount(2)
        ->and($create->after['allowed_actions'])->toBe([])
        // the protected keys became a suggestion
        ->and($p->suggestions)->toHaveCount(1)
        ->and($p->suggestions[0]['op'])->toMatchArray(['allowed_actions' => ['refund'], 'mode' => 'auto']);

    $p2 = loopTProposal($f['workspace']->id, [['target_type' => 'playbook', 'target_id' => $pb->id, 'ops' => [
        ['op' => 'remove_rule', 'id' => $rule['id']],
        ['op' => 'remove_item', 'field' => 'steps', 'id' => 'step-missing'],
    ]]]);
})->throws(InvalidProposalException::class, 'no step with id');

test('knowledge, example and test case ops apply', function () {
    $f = loopTWorkspace();
    $item = loopTKnowledge($f['workspace']->id, 'Dispatch takes 2 business days.');
    $example = loopTExample($f['workspace']->id, 'It will arrive Friday guaranteed.');
    $case = loopTFrozenCase($f);

    $p = loopTProposal($f['workspace']->id, [
        ['target_type' => 'knowledge', 'ops' => [['op' => 'create_knowledge', 'kind' => 'policy', 'title' => 'Warranty', 'body' => '12 months.', 'scope' => ['global' => true]]]],
        ['target_type' => 'knowledge', 'target_id' => $item->id, 'ops' => [['op' => 'set_field', 'field' => 'body', 'value' => 'Dispatch takes 3 business days.']]],
        ['target_type' => 'example', 'target_id' => $example->id, 'ops' => [['op' => 'replace_example', 'reply' => 'The carrier estimates Friday.']]],
        ['target_type' => 'test_case', 'ops' => [['op' => 'create_test_case', 'frozen_case_id' => $case->id, 'name' => 'Where is it', 'playbook_slug' => 'order_status',
            'expectation' => ['must_not_include' => ['guaranteed'], 'expected_outcome' => 'draft']]]],
    ]);

    expect($p->changes)->toHaveCount(4)
        ->and($p->changes[1]->diff[0])->toMatchArray(['before' => 'Dispatch takes 2 business days.', 'after' => 'Dispatch takes 3 business days.'])
        ->and($p->changes[2]->after['reply'])->toBe('The carrier estimates Friday.')
        ->and($p->changes[3]->after['expectation']['must_not_include'])->toBe(['guaranteed']);

    $retire = loopTProposal($f['workspace']->id, [
        ['target_type' => 'knowledge', 'target_id' => $item->id, 'ops' => [['op' => 'retire_knowledge']]],
        ['target_type' => 'example', 'target_id' => $example->id, 'ops' => [['op' => 'retire_example', 'reason' => 'promises']]],
    ]);
    expect($retire->changes[0]->after['status'])->toBe('retired')
        ->and($retire->changes[1]->after['status'])->toBe('retired');
});

test('invalid ops are rejected with paths and nothing is stored', function () {
    $f = loopTWorkspace();

    try {
        loopTProposal($f['workspace']->id, [
            ['target_type' => 'playbook', 'target_id' => $f['playbook']->id, 'ops' => [['op' => 'replace_rule', 'id' => 'r-nope', 'then' => 'x']]],
            ['target_type' => 'voice', 'ops' => [['op' => 'add_rule', 'then' => 'x']]],
            ['target_type' => 'knowledge', 'ops' => [['op' => 'create_knowledge', 'body' => 'x', 'scope' => ['global' => false]]]],
            ['target_type' => 'playbook', 'target_id' => 999999, 'ops' => [['op' => 'add_rule', 'then' => 'x']]],
        ]);
        $this->fail('expected exception');
    } catch (InvalidProposalException $e) {
        expect(implode("\n", $e->errors))
            ->toContain('changes.0.ops.0: no rule with id "r-nope"')
            ->toContain('changes.1.ops.0: op "add_rule" is not valid for target "voice"')
            ->toContain('knowledge needs a scope')
            ->toContain('playbook 999999 not found');
    }

    expect(Proposal::count())->toBe(0);
});

test('forbidden ops become suggestions and are never applied', function () {
    $f = loopTWorkspace();

    $p = loopTProposal($f['workspace']->id, [['target_type' => 'playbook', 'target_id' => $f['playbook']->id, 'ops' => [
        ['op' => 'set_field', 'field' => 'allowed_actions', 'value' => ['refund']],
        ['op' => 'set_mode', 'value' => 'auto'],
        ['op' => 'set_field', 'field' => 'rollout_percent', 'value' => 100],
        ['op' => 'add_rule', 'then' => 'Be brief.'],
    ]]]);

    expect($p->suggestions)->toHaveCount(3)
        ->and(collect($p->suggestions)->pluck('reason')->implode(' '))->toContain('never by an AI proposal')
        ->and($p->changes->sole()->ops)->toHaveCount(1);

    // Only forbidden ops: nothing applicable.
    expect(fn () => loopTProposal($f['workspace']->id, [['target_type' => 'playbook', 'target_id' => $f['playbook']->id, 'ops' => [
        ['op' => 'allow_action', 'action' => 'refund'],
    ]]]))->toThrow(InvalidProposalException::class, 'No applicable changes');
});

test('overlay reflects the proposal and is never written', function () {
    $f = loopTWorkspace();
    $item = loopTKnowledge($f['workspace']->id, 'Old fact.');
    $example = loopTExample($f['workspace']->id, 'Old reply');

    $p = loopTProposal($f['workspace']->id, [
        ['target_type' => 'voice', 'ops' => [['op' => 'add_dont', 'text' => 'no worries']]],
        ['target_type' => 'playbook', 'target_id' => $f['playbook']->id, 'ops' => [['op' => 'add_rule', 'then' => 'Overlay rule.']]],
        ['target_type' => 'knowledge', 'target_id' => $item->id, 'ops' => [['op' => 'retire_knowledge']]],
        ['target_type' => 'example', 'target_id' => $example->id, 'ops' => [['op' => 'retire_example']]],
    ]);

    $set = InstructionSet::live($f['workspace']->id)->withOverlay(app(ProposalService::class)->overlay($p));
    expect($set->voice->dontTexts())->toContain('no worries')
        ->and(collect($set->playbook('order_status')->rules)->pluck('then'))->toContain('Overlay rule.')
        ->and($set->knowledgeRemove)->toBe([$item->id])
        ->and($set->examplesRemove)->toBe([$example->id])
        ->and(VoiceVersion::where('workspace_id', $f['workspace']->id)->count())->toBe(1);
});

test('approve writes versions atomically, flips pointers, creates test cases and links feedback; revert restores', function () {
    $f = loopTWorkspace();
    $ws = $f['workspace']->id;
    $item = loopTKnowledge($ws, 'Old fact.');
    $example = loopTExample($ws, 'Old reply');
    $case = loopTFrozenCase($f);
    $oldVoice = AgentSetting::forWorkspace($ws)->live_voice_version_id;
    $oldPb = $f['playbook']->live_version_id;
    $admin = adminUser($f['workspace']);

    $p = loopTProposal($ws, [
        ['target_type' => 'voice', 'ops' => [['op' => 'add_dont', 'text' => 'no worries']]],
        ['target_type' => 'playbook', 'target_id' => $f['playbook']->id, 'ops' => [['op' => 'add_rule', 'then' => 'Approved rule.']]],
        ['target_type' => 'playbook', 'ops' => [['op' => 'create_playbook', 'slug' => 'warranty', 'name' => 'Warranty', 'when_text' => 'Faults.']]],
        ['target_type' => 'knowledge', 'target_id' => $item->id, 'ops' => [['op' => 'set_field', 'field' => 'body', 'value' => 'New fact.']]],
        ['target_type' => 'knowledge', 'ops' => [['op' => 'create_knowledge', 'kind' => 'fact', 'body' => 'Brand new.', 'scope' => ['playbooks' => ['warranty']]]]],
        ['target_type' => 'example', 'target_id' => $example->id, 'ops' => [['op' => 'retire_example']]],
        ['target_type' => 'test_case', 'ops' => [['op' => 'create_test_case', 'frozen_case_id' => $case->id, 'name' => 'T', 'playbook_slug' => 'warranty']]],
    ]);
    $p->forceFill(['conflicts' => ['items' => []], 'conflicts_checked_at' => now()])->save();

    $applied = app(ProposalService::class)->approve($p, $admin->id);

    $newVoice = AgentSetting::forWorkspace($ws)->live_voice_version_id;
    $warranty = Playbook::where('workspace_id', $ws)->where('slug', 'warranty')->sole();
    expect($applied->status)->toBe('applied')
        ->and($newVoice)->not->toBe($oldVoice)
        ->and(VoiceVersion::find($newVoice)->dontPhraseTexts())->toContain('no worries')
        ->and($f['playbook']->fresh()->live_version_id)->not->toBe($oldPb)
        ->and(collect($f['playbook']->fresh()->liveVersion->rules)->pluck('then'))->toContain('Approved rule.')
        ->and($warranty->mode)->toBe('draft')
        ->and($warranty->liveVersion->allowed_actions)->toBe([])
        ->and($item->fresh()->status)->toBe('retired')
        ->and(KnowledgeItem::where('supersedes_id', $item->id)->sole()->body)->toBe('New fact.')
        ->and(KnowledgeItem::where('body', 'Brand new.')->sole()->status)->toBe('active')
        ->and($example->fresh()->status)->toBe('retired')
        ->and(AgentTestCase::where('proposal_id', $p->id)->sole()->playbook_id)->toBe($warranty->id)
        ->and($applied->applied['voice'])->toEqual(['from_version_id' => $oldVoice, 'to_version_id' => $newVoice]);

    $reverted = app(ProposalService::class)->revert($applied, $admin->id);

    expect($reverted->status)->toBe('reverted')
        ->and(VoiceVersion::find(AgentSetting::forWorkspace($ws)->live_voice_version_id)->restored_from_id)->toBe($oldVoice)
        ->and(collect($f['playbook']->fresh()->liveVersion->rules)->pluck('then'))->not->toContain('Approved rule.')
        ->and($f['playbook']->fresh()->liveVersion->restored_from_id)->toBe($oldPb)
        ->and($warranty->fresh()->archived_at)->not->toBeNull()
        ->and($item->fresh()->status)->toBe('active')
        ->and(KnowledgeItem::where('supersedes_id', $item->id)->sole()->status)->toBe('retired')
        ->and($example->fresh()->status)->toBe('active')
        ->and(AgentTestCase::where('proposal_id', $p->id)->sole()->status)->toBe('archived');
});

test('approve fails as a whole when one change cannot be written', function () {
    $f = loopTWorkspace();
    $ws = $f['workspace']->id;
    $oldVoice = AgentSetting::forWorkspace($ws)->live_voice_version_id;
    $example = loopTExample($ws, 'Old');

    $p = loopTProposal($ws, [
        ['target_type' => 'voice', 'ops' => [['op' => 'add_dont', 'text' => 'no worries']]],
        ['target_type' => 'example', 'target_id' => $example->id, 'ops' => [['op' => 'retire_example']]],
    ]);
    $p->forceFill(['conflicts' => ['items' => []], 'conflicts_checked_at' => now()])->save();
    // Sabotage the second change after validation: the example vanishes, but the fingerprint must still match.
    $p->changes[1]->forceFill(['target_id' => 99999999])->save();

    expect(fn () => app(ProposalService::class)->approve($p->fresh(), adminUser($f['workspace'])->id))->toThrow(Exception::class);
    expect(AgentSetting::forWorkspace($ws)->live_voice_version_id)->toBe($oldVoice)
        ->and($p->fresh()->status)->toBe('draft')
        ->and(VoiceVersion::where('workspace_id', $ws)->count())->toBe(1);
});

test('a proposal goes stale when the live config moves, and rebase re-validates', function () {
    $f = loopTWorkspace();
    $ws = $f['workspace']->id;
    $rule = $f['playbook']->liveVersion->rules[0];
    $p = loopTProposal($ws, [['target_type' => 'playbook', 'target_id' => $f['playbook']->id, 'ops' => [['op' => 'replace_rule', 'id' => $rule['id'], 'then' => 'New wording.']]]]);
    $p->forceFill(['conflicts' => ['items' => []], 'conflicts_checked_at' => now()])->save();
    $addedId = null;

    // Someone edits the playbook (keeps the rule).
    app(VersionService::class)->createPlaybookVersion($f['playbook'], [
        ...$f['playbook']->liveVersion->toContentArray(),
        'rules' => [...$f['playbook']->liveVersion->rules, ['then' => 'Another rule.']],
    ]);

    try {
        app(ProposalService::class)->approve($p, adminUser($f['workspace'])->id);
        $this->fail('expected stale');
    } catch (ProposalStateException $e) {
        expect($e->reason)->toBe('stale');
    }
    expect($p->fresh()->status)->toBe('stale');

    $rebased = app(ProposalService::class)->rebase($p->fresh());
    expect($rebased->status)->toBe('draft')
        ->and($rebased->changes->sole()->base_version_id)->toBe($f['playbook']->fresh()->live_version_id)
        ->and(collect($rebased->changes->sole()->after['rules'])->pluck('then'))->toContain('Another rule.', 'New wording.')
        ->and($rebased->conflicts_checked_at)->toBeNull();

    // Now the rule is removed: rebase cannot apply and the proposal stays stale.
    $live = $f['playbook']->fresh()->liveVersion;
    app(VersionService::class)->createPlaybookVersion($f['playbook']->fresh(), [
        ...$live->toContentArray(),
        'rules' => array_values(array_filter($live->rules, fn ($r) => $r['id'] !== $rule['id'])),
    ]);
    expect(fn () => app(ProposalService::class)->rebase($rebased->fresh()))->toThrow(InvalidProposalException::class);
    expect($rebased->fresh()->status)->toBe('stale');
});

test('revert refuses when the live version moved after apply', function () {
    $f = loopTWorkspace();
    $ws = $f['workspace']->id;
    $p = loopTProposal($ws, [['target_type' => 'voice', 'ops' => [['op' => 'add_dont', 'text' => 'no worries']]]]);
    $p->forceFill(['conflicts' => ['items' => []], 'conflicts_checked_at' => now()])->save();
    $admin = adminUser($f['workspace']);
    $applied = app(ProposalService::class)->approve($p, $admin->id);

    $live = VoiceVersion::find(AgentSetting::forWorkspace($ws)->live_voice_version_id);
    app(VersionService::class)->createVoiceVersion($ws, $live->toContentArray());

    try {
        app(ProposalService::class)->revert($applied, $admin->id);
        $this->fail('expected moved');
    } catch (ProposalStateException $e) {
        expect($e->reason)->toBe('moved');
    }
    expect($applied->fresh()->status)->toBe('applied');
});

test('approve refuses unchecked proposals and blocking conflicts', function () {
    $f = loopTWorkspace();
    $p = loopTProposal($f['workspace']->id, [['target_type' => 'voice', 'ops' => [['op' => 'add_dont', 'text' => 'x y z']]]]);
    $admin = adminUser($f['workspace']);

    expect(fn () => app(ProposalService::class)->approve($p, $admin->id))->toThrow(ProposalStateException::class, 'conflict check');

    $p->forceFill(['conflicts' => ['items' => [['id' => 'c1', 'severity' => 'blocking', 'resolution' => null]]], 'conflicts_checked_at' => now()])->save();
    expect(fn () => app(ProposalService::class)->approve($p, $admin->id))->toThrow(ProposalStateException::class, 'blocking');

    $rejected = app(ProposalService::class)->reject($p, $admin->id, 'nope');
    expect($rejected->status)->toBe('rejected')->and($rejected->review_note)->toBe('nope');
    expect(Proposal::find($p->id))->not->toBeNull();
});
