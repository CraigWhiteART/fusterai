<?php

require_once __DIR__.'/AgentTestHelpers.php';

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\AI\Jobs\GenerateReplySuggestionJob;
use App\Domains\Conversation\Models\AiSuggestion;
use App\Events\AiSuggestionFailed;
use App\Events\AiSuggestionReady;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
 * "AI Suggest" (POST /ai/conversations/{id}/suggest-reply) with the legacy
 * drafter gone: a person asking always gets a one-off agent draft, also when
 * the agent is off or in shadow; automatic drafting still follows the mode.
 */

test('AI Suggest drafts once with the agent pipeline while the agent is off', function (string $mode) {
    Event::fake([AiSuggestionReady::class]);
    $case = agentTCase('Hi, has my order shipped yet?', mode: $mode);
    agentTFakeTriage('order_status');
    agentTFakeWriter(['reply' => 'Hi Alex, could you send me your order number so I can check?']);
    agentTFakeValidator('pass');
    $user = adminUser($case['workspace']);

    $this->actingAs($user)->postJson("/ai/conversations/{$case['conversation']->id}/suggest-reply")
        ->assertOk()->assertJson(['status' => 'queued']);

    $run = AgentRun::where('conversation_id', $case['conversation']->id)->sole();
    expect($run->purpose)->toBe(AgentRun::PURPOSE_LIVE)
        ->and($run->decision)->toBe('draft')
        ->and(AiSuggestion::where('agent_run_id', $run->id)->exists())->toBeTrue()
        ->and(AgentSetting::forWorkspace($case['workspace']->id)->mode)->toBe($mode); // the mode is not changed
    Event::assertDispatched(AiSuggestionReady::class);
})->with(['off', 'shadow']);

test('a manual draft is never auto-sent, even in a live workspace', function () {
    $case = agentTCase('Hi, has my order shipped yet?', mode: 'live');
    agentTFakeTriage('order_status');
    agentTFakeWriter(['reply' => 'Hi Alex, could you send me your order number so I can check?']);
    agentTFakeValidator('pass');

    GenerateReplySuggestionJob::dispatchSync($case['conversation'], manual: true);

    $run = AgentRun::where('conversation_id', $case['conversation']->id)->sole();
    expect($run->decision)->not->toBe('auto_send');
});

test('with the agent off nothing drafts on its own', function () {
    $case = agentTCase('Hi, has my order shipped yet?', mode: 'off');

    GenerateReplySuggestionJob::dispatchSync($case['conversation']);

    expect(AgentRun::count())->toBe(0)->and(AiSuggestion::count())->toBe(0);
});

test('AI Suggest is refused while the kill switch is on', function () {
    $case = agentTCase('Hi, has my order shipped yet?', mode: 'off');
    AgentSetting::forWorkspace($case['workspace']->id)->update(['kill_switch' => true]);
    Queue::fake();

    $this->actingAs(adminUser($case['workspace']))->postJson("/ai/conversations/{$case['conversation']->id}/suggest-reply")
        ->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'kill switch'));
    Queue::assertNotPushed(GenerateReplySuggestionJob::class);
});

test('a manual job that finds the kill switch on broadcasts why and runs nothing', function () {
    Event::fake([AiSuggestionFailed::class]);
    $case = agentTCase('Hi, has my order shipped yet?', mode: 'off');
    AgentSetting::forWorkspace($case['workspace']->id)->update(['kill_switch' => true]);

    GenerateReplySuggestionJob::dispatchSync($case['conversation'], manual: true);

    expect(AgentRun::count())->toBe(0);
    Event::assertDispatched(AiSuggestionFailed::class, fn ($e) => str_contains($e->reason ?? json_encode($e), 'kill switch'));
});
