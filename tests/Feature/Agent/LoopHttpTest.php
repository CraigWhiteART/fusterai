<?php

require_once __DIR__.'/AgentTestHelpers.php';
require_once __DIR__.'/LoopTestHelpers.php';

use App\Domains\Agent\Models\FeedbackEvent;
use App\Domains\Agent\Models\Simulation;
use App\Domains\Agent\Models\SimulationResult;
use App\Domains\Agent\Models\VoiceVersion;
use App\Domains\Agent\Proposals\ProposalService;
use App\Domains\Agent\Services\VersionService;
use App\Domains\Agent\Simulation\TestCaseService;
use App\Domains\Conversation\Models\AiSuggestion;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/** @return array<string, mixed> */
function loopTHttpFixture(): array
{
    $f = loopTWorkspace();
    $f['run'] = loopTLiveRun($f);
    $f['case'] = loopTFrozenCase($f);
    $f['test'] = app(TestCaseService::class)->create($f['case'], 't', [], $f['playbook']->id);
    $f['proposal'] = app(ProposalService::class)->createProposal($f['workspace']->id, 'feedback', 'p', null,
        [['target_type' => 'voice', 'ops' => [['op' => 'add_do', 'text' => 'Be warm']]]], [], null, ['conversation_id' => $f['conversation']->id, 'run_id' => $f['run']->id]);
    $f['simulation'] = Simulation::create(['workspace_id' => $f['workspace']->id, 'kind' => 'batch', 'status' => 'completed', 'candidate_config_hash' => str_repeat('a', 64), 'cases' => [[$f['case']->id, null]]]);
    $f['result'] = SimulationResult::create(['simulation_id' => $f['simulation']->id, 'frozen_case_id' => $f['case']->id, 'verdict' => 'fail']);
    $f['feedback'] = FeedbackEvent::create(['workspace_id' => $f['workspace']->id, 'run_id' => $f['run']->id, 'conversation_id' => $f['conversation']->id,
        'kind' => 'comment', 'text' => 'x', 'meta' => ['routing' => ['status' => 'question', 'question' => 'Which?']]]);
    $f['suggestion'] = AiSuggestion::create(['conversation_id' => $f['conversation']->id, 'type' => 'reply', 'content' => 'Draft', 'agent_run_id' => $f['run']->id]);
    $f['admin'] = adminUser($f['workspace']);
    $f['agent'] = User::factory()->create(['workspace_id' => $f['workspace']->id, 'role' => 'agent']);

    return $f;
}

function loopTRoute(string $url, array $f): string
{
    return strtr($url, [
        '{conversation}' => (string) $f['conversation']->id, '{proposal}' => (string) $f['proposal']->id, '{test}' => (string) $f['test']->id,
        '{simulation}' => (string) $f['simulation']->id, '{result}' => (string) $f['result']->id, '{case}' => (string) $f['case']->id,
        '{playbook}' => (string) $f['playbook']->id, '{feedback}' => (string) $f['feedback']->id, '{suggestion}' => (string) $f['suggestion']->id,
    ]);
}

dataset('manage routes', [
    'proposals' => ['get', '/agent/proposals'],
    'check' => ['post', '/agent/proposals/{proposal}/check'],
    'resolve' => ['post', '/agent/proposals/{proposal}/resolve-conflict'],
    'gate' => ['post', '/agent/proposals/{proposal}/gate'],
    'approve' => ['post', '/agent/proposals/{proposal}/approve'],
    'reject' => ['post', '/agent/proposals/{proposal}/reject'],
    'rebase' => ['post', '/agent/proposals/{proposal}/rebase'],
    'revert' => ['post', '/agent/proposals/{proposal}/revert'],
    'test cases' => ['get', '/agent/test-cases'],
    'test case store' => ['post', '/agent/test-cases'],
    'test case show' => ['get', '/agent/test-cases/{test}'],
    'test case update' => ['patch', '/agent/test-cases/{test}'],
    'test case delete' => ['delete', '/agent/test-cases/{test}'],
    'playbook tests' => ['post', '/agent/playbooks/{playbook}/tests/run'],
    'estimate' => ['post', '/agent/simulations/estimate'],
    'simulations' => ['get', '/agent/simulations'],
    'simulation store' => ['post', '/agent/simulations'],
    'simulation show' => ['get', '/agent/simulations/{simulation}'],
    'results' => ['get', '/agent/simulations/{simulation}/results'],
    'pause' => ['post', '/agent/simulations/{simulation}/pause'],
    'resume' => ['post', '/agent/simulations/{simulation}/resume'],
    'cancel' => ['post', '/agent/simulations/{simulation}/cancel'],
    'override' => ['patch', '/agent/simulations/{simulation}/results/{result}'],
    'frozen cases' => ['get', '/agent/frozen-cases'],
    'frozen case' => ['get', '/agent/frozen-cases/{case}'],
    'build' => ['post', '/agent/frozen-cases/build'],
    'prepare' => ['post', '/agent/frozen-cases/prepare'],
]);

dataset('conversation routes', [
    'feedback store' => ['post', '/agent/conversations/{conversation}/feedback'],
    'feedback show' => ['get', '/agent/feedback/{feedback}'],
    'feedback scope' => ['post', '/agent/feedback/{feedback}/scope'],
    'feedback answer' => ['post', '/agent/feedback/{feedback}/answer'],
    'proposal show' => ['get', '/agent/proposals/{proposal}'],
    'proposal preview' => ['post', '/agent/proposals/{proposal}/preview'],
    'dismiss' => ['post', '/agent/suggestions/{suggestion}/dismiss'],
]);

test('management routes need login', function (string $method, string $url) {
    $f = loopTHttpFixture();
    $this->{$method.'Json'}(loopTRoute($url, $f))->assertUnauthorized();
})->with('manage routes');

test('conversation routes need login', function (string $method, string $url) {
    $f = loopTHttpFixture();
    $this->{$method.'Json'}(loopTRoute($url, $f))->assertUnauthorized();
})->with('conversation routes');

test('management routes are admin only', function (string $method, string $url) {
    $f = loopTHttpFixture();
    Queue::fake();
    $this->actingAs($f['agent'])->{$method.'Json'}(loopTRoute($url, $f), [])->assertForbidden();
})->with('manage routes');

test('another workspace\'s rows are not found for admins', function (string $method, string $url) {
    $f = loopTHttpFixture();
    Queue::fake();
    if (! preg_match('/\{(proposal|test|simulation|case|playbook)\}/', $url)) {
        expect(true)->toBeTrue();

        return;
    }
    $this->actingAs(adminUser())->{$method.'Json'}(loopTRoute($url, $f), [])->assertNotFound();
})->with('manage routes');

test('conversation routes are open to agents who can view the conversation, and closed to other workspaces', function (string $method, string $url) {
    $f = loopTHttpFixture();
    Queue::fake();
    $body = ['run_id' => $f['run']->id, 'text' => 'Shorter please', 'scope' => 'voice', 'answer' => 'All replies'];

    $status = $this->actingAs($f['agent'])->{$method.'Json'}(loopTRoute($url, $f), $body)->status();
    expect($status)->toBeIn([200, 202]);

    $this->actingAs(agentUser())->{$method.'Json'}(loopTRoute($url, $f), $body)->assertForbidden();
})->with('conversation routes');

test('dismissing a suggestion records a rejected feedback event on its run', function () {
    $f = loopTHttpFixture();

    $this->actingAs($f['agent'])->postJson("/agent/suggestions/{$f['suggestion']->id}/dismiss", ['reason' => 'Wrong order'])
        ->assertOk()->assertJsonPath('ok', true);

    $event = FeedbackEvent::where('kind', 'rejected')->sole();
    expect($event->run_id)->toBe($f['run']->id)
        ->and($event->user_id)->toBe($f['agent']->id)
        ->and($event->text)->toBe('Wrong order')
        ->and($event->meta['reason'])->toBe('dismissed')
        ->and($f['suggestion']->fresh()->accepted)->toBeFalse();
});

test('proposal list, show, reject and stale approve over HTTP', function () {
    $f = loopTHttpFixture();
    loopTFakeConflictJudge();

    $this->actingAs($f['admin'])->getJson('/agent/proposals?open=1')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.changes.0.target_label', 'Brand voice')
        ->assertJsonPath('counts.draft', 1);
    $this->actingAs($f['admin'])->getJson("/agent/proposals/{$f['proposal']->id}")->assertOk()->assertJsonPath('proposal.diff.0.after', 'Be warm');

    // Stale: the live voice moved.
    $voice = VoiceVersion::where('workspace_id', $f['workspace']->id)->latest('id')->first();
    app(VersionService::class)->createVoiceVersion($f['workspace']->id, $voice->toContentArray());
    $this->actingAs($f['admin'])->postJson("/agent/proposals/{$f['proposal']->id}/approve")->assertStatus(409)->assertJsonPath('reason', 'stale')
        ->assertJsonPath('proposal.status', 'stale');
    $this->actingAs($f['admin'])->postJson("/agent/proposals/{$f['proposal']->id}/rebase")->assertOk()->assertJsonPath('proposal.status', 'draft');
    $this->actingAs($f['admin'])->postJson("/agent/proposals/{$f['proposal']->id}/approve")->assertOk()->assertJsonPath('proposal.status', 'applied');
    $this->actingAs($f['admin'])->postJson("/agent/proposals/{$f['proposal']->id}/revert")->assertOk()->assertJsonPath('proposal.status', 'reverted');
    $this->actingAs($f['admin'])->postJson("/agent/proposals/{$f['proposal']->id}/reject")->assertStatus(409)->assertJsonPath('reason', 'not_open');
});
