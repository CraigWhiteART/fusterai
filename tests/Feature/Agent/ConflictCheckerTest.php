<?php

require_once __DIR__.'/AgentTestHelpers.php';
require_once __DIR__.'/LoopTestHelpers.php';

use App\Domains\Agent\Agents\ConflictJudgeAgent;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Models\Proposal;
use App\Domains\Agent\Models\VoiceVersion;
use App\Domains\Agent\Proposals\ConflictChecker;
use App\Domains\Agent\Proposals\ConflictResolver;
use App\Domains\Agent\Proposals\ProposalService;
use App\Domains\Agent\Proposals\ProposalStateException;
use App\Domains\Agent\Services\VersionService;
use App\Domains\Commerce\Actions\Models\ActionPolicy;

function loopTCheck(Proposal $p): array
{
    return app(ConflictChecker::class)->check($p);
}

function loopTItems(array $report, string $type): array
{
    return array_values(array_filter($report['items'], fn ($i) => $i['type'] === $type));
}

test('the same phrase in do and don\'t blocks approval', function () {
    $f = loopTWorkspace();
    $ws = $f['workspace']->id;
    $voice = VoiceVersion::findOrFail(AgentSetting::forWorkspace($ws)->live_voice_version_id);
    app(VersionService::class)->createVoiceVersion($ws, [...$voice->toContentArray(), 'do_phrases' => [['text' => 'No worries at all']]]);
    loopTFakeConflictJudge();

    $p = app(ProposalService::class)->createProposal($ws, 'feedback', 'Ban no worries', null, [['target_type' => 'voice', 'ops' => [['op' => 'add_dont', 'text' => 'no worries']]]]);
    $report = loopTCheck($p);

    $conflict = loopTItems($report, 'do_dont')[0];
    expect($conflict['severity'])->toBe('blocking')
        ->and($conflict['existing']['text'])->toBe('No worries at all')
        ->and($conflict['options'])->toContain('keep_old', 'replace_old')
        ->and($p->fresh()->blockingConflicts())->toHaveCount(1);

    expect(fn () => app(ProposalService::class)->approve($p->fresh(), adminUser($f['workspace'])->id))
        ->toThrow(ProposalStateException::class, 'blocking');

    // Replace the old "do": the proposal now also removes it, and can be approved.
    $resolved = app(ConflictResolver::class)->resolve($p->fresh(), $conflict['id'], 'replace_old', null, adminUser($f['workspace'])->id);
    expect($resolved->blockingConflicts())->toBe([])
        ->and(collect($resolved->changes->sole()->ops)->pluck('op')->all())->toBe(['add_dont', 'remove_item']);

    $applied = app(ProposalService::class)->approve($resolved, adminUser($f['workspace'])->id);
    $live = VoiceVersion::find(AgentSetting::forWorkspace($ws)->live_voice_version_id);
    expect($applied->status)->toBe('applied')
        ->and($live->dontPhraseTexts())->toContain('no worries')
        ->and(collect($live->do_phrases)->pluck('text'))->not->toContain('No worries at all');
});

test('exact duplicates across playbooks are warnings, not blockers', function () {
    $f = loopTWorkspace();
    $ws = $f['workspace']->id;
    $other = Playbook::with('liveVersion')->where('workspace_id', $ws)->where('slug', 'refund_request')->sole();
    $existing = $other->liveVersion->rules[0];
    loopTFakeConflictJudge();

    $p = app(ProposalService::class)->createProposal($ws, 'feedback', 'dup', null, [['target_type' => 'playbook', 'target_id' => $f['playbook']->id, 'ops' => [['op' => 'add_rule', 'then' => $existing['then']]]]]);
    $report = loopTCheck($p);

    $dup = loopTItems($report, 'duplicate')[0];
    expect($dup['severity'])->toBe('warning')
        ->and($dup['existing']['target_id'])->toBe($other->id)
        ->and($p->fresh()->blockingConflicts())->toBe([]);
});

test('a rule offering a switched-off action warns (not blocks); one the playbook cannot propose warns', function () {
    $f = loopTWorkspace();
    // Everything defaults to `never`; the workspace has switched refunds to approval.
    ActionPolicy::create(['workspace_id' => $f['workspace']->id, 'action_key' => 'refund', 'playbook_id' => null, 'mode' => ActionPolicy::APPROVAL, 'limits' => []]);
    loopTFakeConflictJudge();

    $p = app(ProposalService::class)->createProposal($f['workspace']->id, 'feedback', 'offers', null, [['target_type' => 'playbook', 'target_id' => $f['playbook']->id, 'ops' => [
        ['op' => 'add_rule', 'then' => 'Offer a 10% discount code to say sorry.'],
        ['op' => 'add_rule', 'then' => 'Offer a refund if it is late.'],
        ['op' => 'add_rule', 'then' => 'Never offer a replacement.'],
    ]]]);
    $report = loopTCheck($p);

    $never = loopTItems($report, 'action_never');
    $notAllowed = loopTItems($report, 'action_not_allowed');
    expect($never)->toHaveCount(1)
        ->and($never[0]['existing']['item_id'])->toBe('discount_code')
        ->and($never[0]['severity'])->toBe('warning')
        ->and($never[0]['explanation'])->toContain('currently switched off, so the agent will hand over')
        ->and($never[0]['options'])->toBe(['keep_old', 'acknowledge'])
        ->and($notAllowed)->toHaveCount(1)
        ->and($notAllowed[0]['existing']['item_id'])->toBe('refund')
        ->and($notAllowed[0]['severity'])->toBe('warning')
        ->and($p->fresh()->blockingConflicts())->toBe([]);

    // keep_old drops the offending rule only.
    $resolved = app(ConflictResolver::class)->resolve($p->fresh(), $never[0]['id'], 'keep_old', null, adminUser($f['workspace'])->id);
    $thens = collect($resolved->changes->sole()->ops)->pluck('then')->all();
    expect($thens)->not->toContain('Offer a 10% discount code to say sorry.')
        ->and($thens)->toContain('Offer a refund if it is late.');
});

test('a playbook-level `never` policy switches the action off for that playbook only', function () {
    $f = loopTWorkspace();
    $ws = $f['workspace']->id;
    ActionPolicy::create(['workspace_id' => $ws, 'action_key' => 'refund', 'playbook_id' => null, 'mode' => ActionPolicy::APPROVAL, 'limits' => []]);
    ActionPolicy::create(['workspace_id' => $ws, 'action_key' => 'refund', 'playbook_id' => $f['playbook']->id, 'mode' => ActionPolicy::NEVER, 'limits' => []]);
    loopTFakeConflictJudge();

    $p = app(ProposalService::class)->createProposal($ws, 'feedback', 'offers', null, [['target_type' => 'playbook', 'target_id' => $f['playbook']->id, 'ops' => [
        ['op' => 'add_rule', 'then' => 'Offer a refund if it is late.'],
    ]]]);
    $report = loopTCheck($p);

    expect(loopTItems($report, 'action_never'))->toHaveCount(1)
        ->and(loopTItems($report, 'action_not_allowed'))->toBe([]);
});

test('LLM contradictions block and can be scoped; subsumes is a warning', function () {
    $f = loopTWorkspace();
    $ws = $f['workspace']->id;
    $item = loopTKnowledge($ws, 'Orders dispatch within 2 business days of payment.');
    loopTFakeConflictJudge(fn (string $new, string $existing) => match (true) {
        str_contains($existing, 'dispatch within 2 business days') => 'contradiction',
        str_contains($existing, 'promise a dispatch or delivery date') => 'subsumes',
        default => 'none',
    });

    $p = app(ProposalService::class)->createProposal($ws, 'feedback', 'dispatch', null, [['target_type' => 'playbook', 'target_id' => $f['playbook']->id, 'ops' => [
        ['op' => 'add_rule', 'then' => 'Tell customers we dispatch orders within 5 business days; never promise a delivery date.'],
    ]]]);
    $report = loopTCheck($p);

    $contradiction = loopTItems($report, 'contradiction')[0];
    expect($report['llm']['status'])->toBe('ok')
        ->and($contradiction['severity'])->toBe('blocking')
        ->and($contradiction['existing']['target_type'])->toBe('knowledge')
        ->and($contradiction['existing']['target_id'])->toBe($item->id)
        ->and($contradiction['existing_precedence'])->toBeLessThan($contradiction['new_precedence'])
        ->and($contradiction['options'])->toBe(['keep_old', 'replace_old', 'scope_new'])
        ->and(loopTItems($report, 'subsumes')[0]['severity'])->toBe('warning');

    $resolved = app(ConflictResolver::class)->resolve($p->fresh(), $contradiction['id'], 'scope_new', 'the order contains preorder items', adminUser($f['workspace'])->id);
    expect($resolved->changes->sole()->ops[0]['if'])->toBe('the order contains preorder items')
        ->and($resolved->blockingConflicts())->toBe([]);

    // Re-checking keeps the human resolution of the same pair.
    loopTCheck($resolved->fresh());
    expect($resolved->fresh()->blockingConflicts())->toBe([])
        ->and(collect($resolved->fresh()->conflicts['items'])->firstWhere('type', 'contradiction')['resolution'])->toBe('scope_new');
});

test('a failed AI check blocks until acknowledged', function () {
    $f = loopTWorkspace();
    ConflictJudgeAgent::fake(fn () => throw new RuntimeException('overloaded'));

    $p = app(ProposalService::class)->createProposal($f['workspace']->id, 'feedback', 'x', null, [['target_type' => 'playbook', 'target_id' => $f['playbook']->id, 'ops' => [
        ['op' => 'add_rule', 'then' => 'Look up the order in Shopify data before saying anything about the status.'],
    ]]]);
    $report = loopTCheck($p);

    expect($report['llm']['status'])->toBe('failed')
        ->and(loopTItems($report, 'check_failed')[0]['severity'])->toBe('blocking');

    $resolved = app(ConflictResolver::class)->resolve($p->fresh(), 'check_failed', 'acknowledge', null, adminUser($f['workspace'])->id);
    expect($resolved->blockingConflicts())->toBe([]);
});

test('examples that violate the new instruction are flagged for retirement', function () {
    $f = loopTWorkspace();
    $ws = $f['workspace']->id;
    $bad = loopTExample($ws, 'Good news, delivery is guaranteed by Friday!');
    $llmBad = loopTExample($ws, 'It will be with you on Friday, promise.');
    $fine = loopTExample($ws, 'The carrier shows it in transit; I will keep an eye on it.');
    loopTFakeConflictJudge(fn (string $new, string $existing, string $label) => str_contains($existing, 'promise') ? 'violates' : 'none');

    $p = app(ProposalService::class)->createProposal($ws, 'feedback', 'no promises', null, [
        ['target_type' => 'voice', 'ops' => [['op' => 'add_dont', 'text' => 'guaranteed']]],
        ['target_type' => 'playbook', 'target_id' => $f['playbook']->id, 'ops' => [['op' => 'add_rule', 'then' => 'Never promise a delivery day such as Friday.']]],
    ]);
    $report = loopTCheck($p);

    $flagged = collect($report['example_flags'])->pluck('example_id')->all();
    expect($flagged)->toContain($bad->id, $llmBad->id)->not->toContain($fine->id);

    $flag = collect($report['example_flags'])->firstWhere('example_id', $bad->id);
    $resolved = app(ConflictResolver::class)->resolve($p->fresh(), $flag['id'], 'retire', null, adminUser($f['workspace'])->id);
    expect(collect($resolved->changes)->firstWhere('target_type', 'example')->target_id)->toBe($bad->id);

    app(ProposalService::class)->approve($resolved->fresh(), adminUser($f['workspace'])->id);
    expect($bad->fresh()->status)->toBe('retired')->and($fine->fresh()->status)->toBe('active');
});
