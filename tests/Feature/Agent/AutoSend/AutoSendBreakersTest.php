<?php

require_once __DIR__.'/AutoSendTestHelpers.php';

use App\Domains\Agent\AutoSend\AutoModeGuard;
use App\Domains\Agent\AutoSend\CircuitBreaker;
use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\AgentTestCase;
use App\Domains\Agent\Models\FeedbackEvent;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Models\Simulation;
use App\Domains\Agent\Proposals\ProposalService;
use App\Domains\Agent\Services\VersionService;
use App\Domains\Agent\Simulation\SimulationService;
use App\Domains\Agent\Simulation\TestCaseService;
use App\Domains\AI\Jobs\GenerateReplySuggestionJob;
use App\Domains\Conversation\Jobs\SendReplyJob;
use App\Domains\Conversation\Models\AiSuggestion;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Notifications\AgentAutoSendNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Http::preventStrayRequests();
    Bus::fake([SendReplyJob::class, GenerateReplySuggestionJob::class]);
    Notification::fake();
    config(['agent.auto_send.breakers.min_sample' => 4, 'agent.auto_send.min_test_cases' => 3]);
});

function autoTRunRow(array $f, array $attributes = []): AgentRun
{
    return AgentRun::create(array_replace([
        'workspace_id' => $f['workspace']->id, 'purpose' => 'live', 'conversation_id' => $f['conversation']->id,
        'idempotency_key' => 'r:'.uniqid('', true), 'status' => 'completed', 'config' => [], 'config_hash' => 'x',
        'playbook_id' => $f['playbook']->id, 'decision' => 'draft', 'validator' => ['passed' => true],
    ], $attributes));
}

function autoTSentThread(array $f, ?int $conversationId = null): Thread
{
    return Thread::factory()->create([
        'conversation_id' => $conversationId ?? $f['conversation']->id, 'type' => 'message', 'origin' => 'ai', 'user_id' => null, 'customer_id' => null,
        'sent_at' => now(), 'meta' => ['auto_send' => ['status' => 'sent', 'playbook_id' => $f['playbook']->id]],
    ]);
}

function autoTExpectDemoted(array $f, string $breaker): void
{
    $playbook = $f['playbook']->fresh();
    expect($playbook->mode)->toBe(Playbook::MODE_DRAFT)
        ->and($playbook->demoted_at)->not->toBeNull()
        ->and($playbook->demotion_reason)->not->toBeEmpty()
        ->and(Activity::where('event', 'playbook_demoted')->where('subject_id', $playbook->id)->where('properties->breaker', $breaker)->exists())->toBeTrue();
}

test('a high validator failure rate demotes the playbook and notifies admins; it never re-enables', function () {
    $f = autoTWorkspace();
    $admin = adminUser($f['workspace']);
    foreach (range(1, 3) as $i) {
        autoTRunRow($f, ['validator' => ['passed' => false]]);
    }
    autoTRunRow($f);

    expect(app(CircuitBreaker::class)->evaluatePlaybook($f['playbook']->fresh()))->toBe('validator_failures');
    autoTExpectDemoted($f, 'validator_failures');
    Notification::assertSentTo($admin, AgentAutoSendNotification::class, fn ($n) => $n->kind === AgentAutoSendNotification::KIND_PLAYBOOK_DEMOTED);

    // Things look fine again: still draft. Breakers only ever demote.
    foreach (range(1, 20) as $i) {
        autoTRunRow($f);
    }
    $this->artisan('agent:autosend-breakers')->assertSuccessful();
    expect($f['playbook']->fresh()->mode)->toBe(Playbook::MODE_DRAFT)
        ->and(app(CircuitBreaker::class)->evaluatePlaybook($f['playbook']->fresh()))->toBeNull();
});

test('below the minimum sample the validator breaker does not trip', function () {
    $f = autoTWorkspace();
    autoTRunRow($f, ['validator' => ['passed' => false]]);
    autoTRunRow($f, ['validator' => ['passed' => false]]);

    expect(app(CircuitBreaker::class)->evaluatePlaybook($f['playbook']->fresh()))->toBeNull()
        ->and($f['playbook']->fresh()->mode)->toBe('auto');
});

test('unhappy customer replies after auto-sends demote the playbook', function () {
    $f = autoTWorkspace();
    foreach (range(1, 4) as $i) {
        $sent = autoTSentThread($f);
        $reply = autoTCustomerMessage($f['conversation'], "Reply {$i}");
        autoTRunRow($f, ['customer_thread_id' => $reply->id, 'triage' => ['sentiment' => $i <= 2 ? 'angry' : 'calm', 'risk_flags' => []]]);
    }

    expect(app(CircuitBreaker::class)->evaluatePlaybook($f['playbook']->fresh()))->toBe('negative_signal');
    autoTExpectDemoted($f, 'negative_signal');
});

test('a reopened conversation after an auto-send counts as a negative signal', function () {
    $f = autoTWorkspace();
    config(['agent.auto_send.breakers.negative_signal_rate' => 0.2]);
    $conversations = [];
    foreach (range(1, 4) as $i) {
        $conversations[] = $i === 1 ? $f['conversation'] : Conversation::factory()->create([
            'workspace_id' => $f['workspace']->id, 'mailbox_id' => $f['mailbox']->id, 'customer_id' => $f['customer']->id, 'status' => 'open',
        ]);
    }
    foreach ($conversations as $conversation) {
        autoTSentThread($f, $conversation->id);
    }
    // One of them was closed and the customer wrote again (activity log records the reopen).
    $conversations[0]->update(['status' => 'closed']);
    $conversations[0]->update(['status' => 'open']);

    expect(app(CircuitBreaker::class)->evaluatePlaybook($f['playbook']->fresh()))->toBe('negative_signal');
});

test('"this was wrong" marks on auto-sent replies demote the playbook', function () {
    $f = autoTWorkspace();
    $thread = autoTSentThread($f);
    $other = autoTSentThread($f);

    $this->actingAs(agentUser($f['workspace']))->postJson("/agent/auto-sends/{$thread->id}/wrong", ['reason' => 'Promised a date we cannot keep'])
        ->assertCreated()->assertJsonPath('playbook_demoted', false);
    expect(FeedbackEvent::where('kind', 'auto_send_wrong')->sole()->meta['auto_thread_id'])->toBe($thread->id);

    $this->actingAs(agentUser($f['workspace']))->postJson("/agent/auto-sends/{$other->id}/wrong", ['reason' => 'Wrong tone'])
        ->assertCreated()->assertJsonPath('playbook_demoted', true);
    autoTExpectDemoted($f, 'wrong_marks');
});

test('only a sent auto-reply can be marked wrong, and a reason is required', function () {
    $f = autoTWorkspace();
    $human = loopTStaffReply($f['conversation'], 'Mine.');
    $sent = autoTSentThread($f);
    $agent = agentUser($f['workspace']);

    $this->actingAs($agent)->postJson("/agent/auto-sends/{$human->id}/wrong", ['reason' => 'nope'])->assertNotFound();
    $this->actingAs($agent)->postJson("/agent/auto-sends/{$sent->id}/wrong", [])->assertUnprocessable();
});

test('a low QA score on auto-sent replies demotes the playbook (when QA reviews exist)', function () {
    $f = autoTWorkspace();
    config(['agent.auto_send.breakers.qa_min_sample' => 2]);
    foreach (range(1, 2) as $i) {
        $thread = autoTSentThread($f);
        DB::table('agent_qa_reviews')->insert([
            'workspace_id' => $f['workspace']->id, 'conversation_id' => $f['conversation']->id, 'reviewed_thread_id' => $thread->id,
            'status' => 'reviewed', 'overall' => 2.0, 'reviewer_prompt_version' => 'test', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    expect(app(CircuitBreaker::class)->evaluatePlaybook($f['playbook']->fresh()))->toBe('qa_score');
    autoTExpectDemoted($f, 'qa_score');
});

test('demotion cancels the playbook\'s scheduled auto-replies back to drafts', function () {
    loopTFakePipeline();
    $f = autoTWorkspace();
    autoTRun($f['conversation']);
    $pending = autoTPending($f['conversation']);

    app(CircuitBreaker::class)->demote($f['playbook']->fresh(), 'wrong_marks', 'Test.');

    expect($pending->fresh()->meta['auto_send']['cancel_reason'])->toBe('breaker')
        ->and(AiSuggestion::count())->toBe(1);
});

// ── Preconditions for turning a playbook to auto ────────────────────────────

/** @return list<AgentTestCase> */
function autoTTestCases(array $f, int $count): array
{
    $case = loopTFrozenCase($f);
    $out = [];
    foreach (range(1, $count) as $i) {
        $clone = $i === 1 ? $case : loopTCloneCase($case, "variant {$i}");
        $out[] = app(TestCaseService::class)->create($clone, "case {$i}", [], $f['playbook']->id);
    }

    return $out;
}

test('auto cannot be enabled without enough test cases, a passing run on the live version and a confirmation', function () {
    $f = autoTWorkspace(playbook: ['mode' => 'draft']);
    $admin = adminUser($f['workspace']);
    $url = "/agent/playbooks/{$f['playbook']->id}";

    $this->actingAs($admin)->patch($url, ['mode' => 'auto', 'confirm_auto' => true])->assertSessionHasErrors('mode');

    autoTTestCases($f, 3);
    // Cases but no test run yet.
    $this->actingAs($admin)->patch($url, ['mode' => 'auto', 'confirm_auto' => true])->assertSessionHasErrors('mode');

    // A failing run.
    loopTFakePipeline(['Your order is guaranteed to arrive soon.']);
    loopTFakeJudge('guaranteed');
    app(SimulationService::class)->createPlaybookTests($f['playbook']->fresh(), null);
    $this->actingAs($admin)->patch($url, ['mode' => 'auto', 'confirm_auto' => true])->assertSessionHasErrors('mode');
    expect($f['playbook']->fresh()->mode)->toBe('draft');

    // A new version: the earlier run no longer counts (it was on an older version).
    $pb = $f['playbook']->fresh()->load('liveVersion');
    app(VersionService::class)->createPlaybookVersion($pb, [
        ...$pb->liveVersion->toContentArray(), 'rules' => [...$pb->liveVersion->rules, ['then' => 'Never promise a delivery day.']],
    ]);
    $items = collect(app(AutoModeGuard::class)->preconditions($f['playbook']->fresh())['items'])->keyBy('key');
    expect($items['tests_on_live']['ok'])->toBeFalse()->and($items['test_cases']['ok'])->toBeTrue();

    // A passing run on the live version.
    loopTFakePipeline(['Hi Alex, your order is being packed now.']);
    app(SimulationService::class)->createPlaybookTests($f['playbook']->fresh(), null);

    $this->actingAs($admin)->patch($url, ['mode' => 'auto'])->assertSessionHasErrors('confirm_auto');
    $this->actingAs($admin)->patch($url, ['mode' => 'auto', 'confirm_auto' => true, 'rollout_percent' => 10])->assertRedirect()->assertSessionHasNoErrors();

    $playbook = $f['playbook']->fresh();
    expect($playbook->mode)->toBe('auto')
        ->and($playbook->rollout_percent)->toBe(10)
        ->and($playbook->auto_enabled_by)->toBe($admin->id)
        ->and(Activity::where('event', 'playbook_auto_enabled')->where('causer_id', $admin->id)->exists())->toBeTrue();

    expect(app(AutoModeGuard::class)->preconditions($playbook)['ok'])->toBeTrue();
});

test('the playbook page shows the checklist, breakers, history and the rollout preview', function () {
    $f = autoTWorkspace();
    autoTRunRow($f, ['purpose' => 'shadow']);

    $this->actingAs(adminUser($f['workspace']))->get("/agent/playbooks/{$f['playbook']->id}")
        ->assertInertia(fn ($page) => $page->component('Agent/Playbooks/Edit')
            ->where('auto_mode_available', true)
            ->has('auto_send.preconditions.items', 6)
            ->has('auto_send.breakers.breakers.validator_failures')
            ->where('auto_send.rollout_preview.sample', 1)
            ->has('auto_send.history')
            ->etc());
});

test('approving a proposal that makes an auto playbook regress drops it back to draft', function () {
    $f = autoTWorkspace();
    autoTTestCases($f, 3);
    // After the change every reply promises a date the judge rejects.
    loopTFakePipeline(['Your order is guaranteed to arrive soon.']);
    loopTFakeJudge('guaranteed');
    $admin = adminUser($f['workspace']);

    $service = app(ProposalService::class);
    $proposal = $service->createProposal($f['workspace']->id, 'manager', 'Promise dates', null, [[
        'target_type' => 'playbook', 'target_id' => $f['playbook']->id,
        'ops' => [['op' => 'add_rule', 'then' => 'Tell the customer when it arrives.']],
    ]]);
    $proposal->forceFill(['conflicts' => ['items' => []], 'conflicts_checked_at' => now()])->save();
    $applied = $service->approve($proposal->fresh(), $admin->id);

    $playbook = $f['playbook']->fresh();
    expect($applied->status)->toBe('applied')
        ->and($playbook->mode)->toBe('draft')
        ->and($playbook->demotion_reason)->toContain('regressed')
        ->and(Simulation::where('kind', 'playbook_tests')->where('playbook_id', $playbook->id)->latest('id')->first()->selection['playbook_version_id'])->toBe($playbook->live_version_id)
        ->and(Activity::where('event', 'playbook_retest_started')->exists())->toBeTrue()
        ->and(Activity::where('event', 'playbook_demoted')->where('properties->breaker', 'retest')->exists())->toBeTrue();
    Notification::assertSentTo($admin, AgentAutoSendNotification::class);
});

test('approving a change that still passes keeps the playbook on auto', function () {
    $f = autoTWorkspace();
    autoTTestCases($f, 3);
    loopTFakePipeline(['Hi Alex, your order is being packed now.']);
    loopTFakeJudge('guaranteed');

    $service = app(ProposalService::class);
    $proposal = $service->createProposal($f['workspace']->id, 'manager', 'Be warm', null, [[
        'target_type' => 'playbook', 'target_id' => $f['playbook']->id,
        'ops' => [['op' => 'add_rule', 'then' => 'Thank them for waiting.']],
    ]]);
    $proposal->forceFill(['conflicts' => ['items' => []], 'conflicts_checked_at' => now()])->save();
    $service->approve($proposal->fresh(), adminUser($f['workspace'])->id);

    $playbook = $f['playbook']->fresh();
    expect($playbook->mode)->toBe('auto')->and($playbook->auto_hold)->toBeNull()
        ->and(Activity::where('event', 'playbook_retest_passed')->exists())->toBeTrue();
});
