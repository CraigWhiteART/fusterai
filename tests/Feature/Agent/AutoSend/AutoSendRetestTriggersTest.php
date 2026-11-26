<?php

require_once __DIR__.'/AutoSendTestHelpers.php';

use App\Domains\Agent\AutoSend\AutoModeGuard;
use App\Domains\Agent\Models\KnowledgeItem;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Models\Simulation;
use App\Domains\Agent\Proposals\ProposalService;
use App\Domains\Agent\Simulation\SimulationService;
use App\Domains\AI\Jobs\GenerateReplySuggestionJob;
use App\Domains\Conversation\Jobs\SendReplyJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;

/*
 * Voice, knowledge and example changes hold `auto` playbooks for a re-test,
 * exactly like a new playbook version does (AutoModeGuard). With no test
 * cases the re-test finishes at once and demotes, so the trigger is read
 * from the `playbook_retest_started` audit entries.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    Bus::fake([SendReplyJob::class, GenerateReplySuggestionJob::class]);
});

/** @return array<string, mixed> autoTWorkspace() + a second auto playbook and a draft one */
function retestTFixture(): array
{
    $f = autoTWorkspace();
    $ws = $f['workspace']->id;
    $f['other'] = Playbook::where('workspace_id', $ws)->where('slug', 'refund_request')->sole();
    $f['other']->forceFill(['mode' => Playbook::MODE_AUTO, 'rollout_percent' => 100])->save();
    $f['draft'] = Playbook::where('workspace_id', $ws)->whereNotIn('id', [$f['playbook']->id, $f['other']->id])->whereNotNull('live_version_id')->firstOrFail();
    $f['draft']->forceFill(['mode' => Playbook::MODE_DRAFT])->save();
    $f['admin'] = adminUser($f['workspace']);

    return $f;
}

/** @return list<int> playbook ids a re-test was started for */
function retestTHeld(): array
{
    return Activity::where('event', 'playbook_retest_started')->get()
        ->map(fn (Activity $a) => (int) $a->properties['playbook_id'])->unique()->sort()->values()->all();
}

function retestTApprove(int $ws, array $changes, int $userId): void
{
    $service = app(ProposalService::class);
    $proposal = $service->createProposal($ws, 'manager', 'change', null, $changes);
    $proposal->forceFill(['conflicts' => ['items' => []], 'conflicts_checked_at' => now()])->save();
    expect($service->approve($proposal->fresh(), $userId)->status)->toBe('applied');
}

test('saving or restoring the voice re-tests every auto playbook, not draft ones', function () {
    $f = retestTFixture();

    $this->actingAs($f['admin'])->post('/agent/voice', ['personality' => 'Brisk and kind'])->assertRedirect();

    $expected = collect([$f['playbook']->id, $f['other']->id])->sort()->values()->all();
    expect(retestTHeld())->toBe($expected)
        ->and(Activity::where('event', 'playbook_retest_started')->where('properties->playbook_id', $f['draft']->id)->exists())->toBeFalse()
        ->and(Activity::where('event', 'playbook_retest_started')->first()->properties['cause'])->toContain('voice version');
});

test('a voice proposal approved re-tests every auto playbook', function () {
    $f = retestTFixture();

    retestTApprove($f['workspace']->id, [['target_type' => 'voice', 'ops' => [['op' => 'add_do', 'text' => 'Be warm']]]], $f['admin']->id);

    expect(retestTHeld())->toBe(collect([$f['playbook']->id, $f['other']->id])->sort()->values()->all());
});

test('knowledge scoped to one playbook re-tests only that auto playbook; global knowledge re-tests all', function () {
    $f = retestTFixture();

    $this->actingAs($f['admin'])->post('/agent/knowledge', ['kind' => 'fact', 'body' => 'Orders ship from Melbourne.', 'scope' => ['global' => false, 'playbooks' => ['order_status']]])->assertRedirect();
    expect(retestTHeld())->toBe([$f['playbook']->id]);

    Activity::query()->delete();
    $f['playbook']->fresh()->forceFill(['mode' => Playbook::MODE_AUTO, 'auto_hold' => null, 'auto_hold_simulation_id' => null])->save();
    $this->actingAs($f['admin'])->post('/agent/knowledge', ['kind' => 'policy', 'body' => 'Returns within 30 days.', 'scope' => ['global' => true]])->assertRedirect();
    expect(retestTHeld())->toBe(collect([$f['playbook']->id, $f['other']->id])->sort()->values()->all());
});

test('editing knowledge re-tests playbooks in its old and its new scope; a draft item changes nothing', function () {
    $f = retestTFixture();
    $item = loopTKnowledge($f['workspace']->id, 'Refunds take 5 days.', ['global' => false, 'playbooks' => ['refund_request']]);

    $this->actingAs($f['admin'])->patch("/agent/knowledge/{$item->id}", ['kind' => 'fact', 'body' => 'Refunds take 5 days.', 'scope' => ['global' => false, 'playbooks' => ['order_status']]])->assertRedirect();
    expect(retestTHeld())->toBe(collect([$f['playbook']->id, $f['other']->id])->sort()->values()->all());

    Activity::query()->delete();
    $draft = KnowledgeItem::create(['workspace_id' => $f['workspace']->id, 'kind' => 'fact', 'body' => 'Draft only', 'scope' => ['global' => true], 'status' => KnowledgeItem::STATUS_DRAFT, 'source_type' => 'manual', 'content_hash' => 'x']);
    $this->actingAs($f['admin'])->patch("/agent/knowledge/{$draft->id}", ['kind' => 'fact', 'body' => 'Draft only, edited', 'scope' => ['global' => true], 'status' => 'draft'])->assertRedirect();
    expect(retestTHeld())->toBe([]);
});

test('a knowledge proposal re-tests the auto playbooks in its scope', function () {
    $f = retestTFixture();

    retestTApprove($f['workspace']->id, [['target_type' => 'knowledge', 'ops' => [['op' => 'create_knowledge', 'kind' => 'policy', 'title' => 'Refunds', 'body' => 'Refunds take 5 days.', 'scope' => ['global' => false, 'playbooks' => ['refund_request']]]]]], $f['admin']->id);

    expect(retestTHeld())->toBe([$f['other']->id]);
});

test('retiring an example by proposal re-tests the auto playbook it belongs to, and so does the revert', function () {
    $f = retestTFixture();
    $example = loopTExample($f['workspace']->id, 'Your refund is on its way.', 'refund_request');
    $service = app(ProposalService::class);
    $proposal = $service->createProposal($f['workspace']->id, 'manager', 'retire', null, [['target_type' => 'example', 'target_id' => $example->id, 'ops' => [['op' => 'retire_example', 'reason' => 'old']]]]);
    $proposal->forceFill(['conflicts' => ['items' => []], 'conflicts_checked_at' => now()])->save();
    $applied = $service->approve($proposal->fresh(), $f['admin']->id);
    expect(retestTHeld())->toBe([$f['other']->id]);

    Activity::query()->delete();
    Playbook::whereKey($f['other']->id)->update(['mode' => Playbook::MODE_AUTO, 'auto_hold' => null, 'auto_hold_simulation_id' => null]);
    $service->revert($applied, $f['admin']->id);
    expect(retestTHeld())->toBe([$f['other']->id]);
});

test('only the re-test the hold points at decides; an older run finishing does not lift the hold', function () {
    $f = retestTFixture();
    $playbook = $f['playbook']->fresh();
    $old = app(SimulationService::class)->createPlaybookTests($playbook, null);
    $current = Simulation::forceCreate(['workspace_id' => $playbook->workspace_id, 'kind' => Simulation::KIND_PLAYBOOK_TESTS, 'status' => Simulation::STATUS_RUNNING,
        'playbook_id' => $playbook->id, 'candidate_config_hash' => str_repeat('b', 64), 'cases' => [], 'selection' => ['playbook_id' => $playbook->id, 'playbook_version_id' => $playbook->live_version_id]]);
    $playbook->forceFill(['mode' => Playbook::MODE_AUTO, 'auto_hold' => Playbook::HOLD_RETESTING, 'auto_hold_simulation_id' => $current->id])->save();

    app(AutoModeGuard::class)->afterSimulationFinished($old->fresh());

    expect($playbook->fresh()->auto_hold)->toBe(Playbook::HOLD_RETESTING)
        ->and($playbook->fresh()->mode)->toBe(Playbook::MODE_AUTO);
});
