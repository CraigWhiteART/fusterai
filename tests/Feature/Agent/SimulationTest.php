<?php

require_once __DIR__.'/AgentTestHelpers.php';
require_once __DIR__.'/LoopTestHelpers.php';

use App\Domains\Agent\Agents\SimulationJudgeAgent;
use App\Domains\Agent\Jobs\RunSimulationJob;
use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Models\Proposal;
use App\Domains\Agent\Models\SimulationResult;
use App\Domains\Agent\Proposals\ProposalService;
use App\Domains\Agent\Simulation\CaseSelector;
use App\Domains\Agent\Simulation\SimulationRunner;
use App\Domains\Agent\Simulation\SimulationService;
use App\Domains\Agent\Simulation\TestCaseService;
use App\Domains\Conversation\Jobs\SendReplyJob;
use App\Domains\Conversation\Models\AiSuggestion;
use App\Domains\Conversation\Models\Thread;
use App\Events\AiSuggestionReady;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['agent.simulation.batch_connection' => 'sync', 'agent.feedback.auto_gate' => false]);
});

/** Workspace with one recorded frozen case + an active order_status test case. */
function loopTSimFixture(array $expectation = []): array
{
    $f = loopTWorkspace();
    loopTSnapshot($f);
    $f['case'] = loopTFrozenCase($f);
    $f['test'] = app(TestCaseService::class)->create($f['case'], 'Where is it', $expectation, $f['playbook']->id);

    return $f;
}

function loopTPlaybookProposal(array $f, string $then = 'Quote the carrier only.'): Proposal
{
    return app(ProposalService::class)->createProposal($f['workspace']->id, 'manager', 'p', null, [
        ['target_type' => 'playbook', 'target_id' => $f['playbook']->id, 'ops' => [['op' => 'add_rule', 'then' => $then]]],
    ]);
}

function loopTSimRuns(): int
{
    return AgentRun::where('purpose', 'simulation')->count();
}

test('a batch simulation never calls Shopify, a carrier or mail, and never touches the conversation', function () {
    $f = loopTSimFixture(['must_not_include' => ['guaranteed'], 'expected_outcome' => 'draft']);
    agentTEnableShopify($f['workspace']->id);
    loopTFakePipeline();
    loopTFakeJudge();
    Http::fake();
    Http::preventStrayRequests();
    Mail::fake();
    Bus::fake([SendReplyJob::class]);
    Event::fake([AiSuggestionReady::class]);
    $before = $f['conversation']->fresh()->only(['status', 'handover_reason', 'updated_at']);
    $threads = Thread::count();

    $sim = app(SimulationService::class)->createBatch($f['workspace']->id, ['limit' => 10], 5.0, null);

    $sim->refresh();
    $result = SimulationResult::where('simulation_id', $sim->id)->sole();
    $run = AgentRun::findOrFail($result->agent_run_id);
    expect($sim->status)->toBe('completed')
        ->and($sim->totals['done'])->toBe(1)
        ->and($result->verdict)->toBe('pass')
        ->and($result->scores['deterministic']['passed'])->toBeTrue()
        ->and($result->scores['judge']['status'])->toBe('ok')
        ->and($run->purpose)->toBe('simulation')
        ->and($run->conversation_id)->toBeNull()
        ->and($run->frozen_case_id)->toBe($f['case']->id)
        ->and($run->evidence['meta']['source'])->toBe('supplied')
        ->and(collect($run->evidence['facts'])->pluck('value'))->toContain('SD8421')
        ->and($sim->cost_micro_usd)->toBeGreaterThan(0)
        ->and(AiSuggestion::count())->toBe(0)
        ->and(Thread::count())->toBe($threads)
        ->and($f['conversation']->fresh()->only(['status', 'handover_reason', 'updated_at']))->toEqual($before);
    Http::assertNothingSent();
    Mail::assertNothingSent();
    Bus::assertNotDispatched(SendReplyJob::class);
    Event::assertNotDispatched(AiSuggestionReady::class);
});

test('deterministic expectations fail a case before the judge is trusted', function () {
    $f = loopTSimFixture(['must_not_include' => ['guaranteed'], 'must_include' => ['carrier']]);
    loopTFakePipeline(['Your parcel is guaranteed to arrive soon.']);
    loopTFakeJudge('nothing-bad');

    $sim = app(SimulationService::class)->createPlaybookTests($f['playbook'], null);

    $result = SimulationResult::where('simulation_id', $sim->id)->sole();
    expect($sim->kind)->toBe('playbook_tests')
        ->and($result->verdict)->toBe('fail')
        ->and(collect($result->scores['deterministic']['failures'])->pluck('check')->all())->toBe(['must_include', 'must_not_include'])
        ->and($result->judge_notes)->toContain('contains "guaranteed"');
});

test('expectations mined from history are judged as points, not matched as text', function () {
    $f = loopTSimFixture(['must_include' => ['no sales planned'], 'expected_actions' => ['discount_code']]);
    $f['test']->forceFill(['source' => 'mining'])->save();
    loopTFakePipeline(['We do not have a sale coming up.']);
    loopTFakeJudge();

    $sim = app(SimulationService::class)->createPlaybookTests($f['playbook'], null);

    $result = SimulationResult::where('simulation_id', $sim->id)->sole();
    expect($result->scores['deterministic']['failures'])->toBe([])
        ->and($result->verdict)->toBe('pass');
});

test('the judge does not score facts when evidence is not recorded', function () {
    $f = loopTWorkspace();
    $case = loopTFrozenCase($f);
    expect($case->evidence_quality)->toBe('none');
    app(TestCaseService::class)->create($case, 't', [], $f['playbook']->id);
    loopTFakePipeline();
    loopTFakeJudge();

    app(SimulationService::class)->createPlaybookTests($f['playbook'], null);

    $result = SimulationResult::sole();
    expect($result->scores['facts_scored'])->toBeFalse()
        ->and($result->scores['judge']['factual_grounding'])->toBeNull();
    SimulationJudgeAgent::assertPrompted(fn ($p) => str_contains($p->prompt, 'EVIDENCE QUALITY: none'));
});

test('gate results are cached by config hash and case: only changed configs re-run', function () {
    $f = loopTSimFixture();
    loopTFakePipeline();
    loopTFakeJudge();

    $gate1 = app(SimulationService::class)->createGate(loopTPlaybookProposal($f, 'Rule A.'), null);
    expect(loopTSimRuns())->toBe(2) // candidate + baseline
        ->and(SimulationResult::where('simulation_id', $gate1->id)->sole()->verdict)->toBe('pass');

    // Another candidate: the live baseline is cached, only the candidate runs.
    $p2 = loopTPlaybookProposal($f, 'Rule B.');
    app(SimulationService::class)->createGate($p2, null);
    expect(loopTSimRuns())->toBe(3);

    // Same candidate again: nothing runs at all.
    $gate3 = app(SimulationService::class)->createGate($p2->fresh(), null);
    $result = SimulationResult::where('simulation_id', $gate3->id)->sole();
    expect(loopTSimRuns())->toBe(3)
        ->and($result->cached)->toBeTrue()
        ->and($gate3->fresh()->totals['cached'])->toBe(1)
        ->and($p2->fresh()->status)->toBe('ready');
});

test('a flip that does not reproduce on re-run is `changed`, one that does is `regressed` or `improved`', function (array $replies, string $verdict) {
    $f = loopTSimFixture();
    loopTFakePipeline($replies); // order: candidate, baseline, candidate re-run
    loopTFakeJudge('guaranteed');

    $gate = app(SimulationService::class)->createGate(loopTPlaybookProposal($f), null);

    $result = SimulationResult::where('simulation_id', $gate->id)->sole();
    expect($result->verdict)->toBe($verdict)
        ->and($result->rerun_run_id)->not->toBeNull()
        ->and($gate->fresh()->totals[$verdict])->toBe(1);
})->with([
    'noise' => [['It is guaranteed to arrive soon.', 'It is on its way.', 'It is on its way.'], 'changed'],
    'regression' => [['It is guaranteed to arrive soon.', 'It is on its way.', 'Guaranteed to arrive soon!'], 'regressed'],
    'improvement' => [['It is on its way.', 'It is guaranteed to arrive soon.', 'It is on its way.'], 'improved'],
]);

test('gate case selection follows the change target and is capped', function () {
    $f = loopTSimFixture();
    $ws = $f['workspace']->id;
    $refund = Playbook::where('workspace_id', $ws)->where('slug', 'refund_request')->sole();
    $tests = app(TestCaseService::class);
    $refundTest = $tests->create(loopTCloneCase($f['case'], 'refund'), 'refund', [], $refund->id);
    $pinned = $tests->create(loopTCloneCase($f['case'], 'pinned'), 'core', [], $refund->id, pinned: true);
    $tests->create(loopTCloneCase($f['case'], 'draft'), 'draft', [], $f['playbook']->id, status: 'draft');
    $selector = app(CaseSelector::class);
    $ids = fn (Proposal $p) => array_map(fn ($c) => $c[1], $selector->gate($p));

    // Playbook change: that playbook's active test cases only.
    expect($ids(loopTPlaybookProposal($f)))->toBe([$f['test']->id]);

    // Voice change: pinned core set first, then a stratified sample.
    $voice = app(ProposalService::class)->createProposal($ws, 'manager', 'v', null, [['target_type' => 'voice', 'ops' => [['op' => 'add_do', 'text' => 'Be warm']]]]);
    $voiceIds = $ids($voice);
    expect($voiceIds[0])->toBe($pinned->id)->and($voiceIds)->toContain($f['test']->id, $refundTest->id)->toHaveCount(3);

    // Knowledge change: cases whose runs cited the item, plus its scope's playbooks.
    $item = loopTKnowledge($ws, 'Refunds take 5 days.', ['playbooks' => ['refund_request']]);
    $cited = loopTCloneCase($f['case'], 'cited');
    AgentRun::create(['workspace_id' => $ws, 'purpose' => 'simulation', 'frozen_case_id' => $cited->id, 'idempotency_key' => 'sim:'.uniqid(), 'status' => 'completed',
        'config' => [], 'config_hash' => str_repeat('c', 64), 'evidence' => ['knowledge' => [['id' => 'K'.$item->id]]]]);
    $knowledge = app(ProposalService::class)->createProposal($ws, 'manager', 'k', null, [['target_type' => 'knowledge', 'target_id' => $item->id, 'ops' => [['op' => 'retire_knowledge']]]]);
    $selected = $selector->gate($knowledge);
    expect(array_map(fn ($c) => $c[0], $selected))->toContain($cited->id)
        ->and(array_map(fn ($c) => $c[1], $selected))->toContain($refundTest->id, $pinned->id)->not->toContain($f['test']->id);

    // Example change: the example's playbook.
    $example = loopTExample($ws, 'Refund done.', 'refund_request');
    $ex = app(ProposalService::class)->createProposal($ws, 'manager', 'e', null, [['target_type' => 'example', 'target_id' => $example->id, 'ops' => [['op' => 'retire_example']]]]);
    expect($ids($ex))->toEqualCanonicalizing([$refundTest->id, $pinned->id]);

    // Cap.
    config(['agent.simulation.gate_cap' => 40]);
    foreach (range(1, 45) as $i) {
        $tests->create(loopTCloneCase($f['case'], "bulk{$i}"), "bulk {$i}", [], $f['playbook']->id);
    }
    expect($selector->gate(loopTPlaybookProposal($f, 'Another.')))->toHaveCount(40);
});

test('a budget cap stops a batch', function () {
    $f = loopTSimFixture();
    loopTCloneCase($f['case'], 'two');
    loopTCloneCase($f['case'], 'three');
    loopTFakePipeline();
    loopTFakeJudge();

    // Each case is estimated at 68,000 micro-USD (no recent runs); a budget of $0.08 fits one or two.
    $sim = app(SimulationService::class)->createBatch($f['workspace']->id, ['limit' => 10], 0.08, null);

    $sim->refresh();
    expect($sim->status)->toBe('budget_exhausted')
        ->and($sim->totals['done'])->toBeGreaterThanOrEqual(1)->toBeLessThan(3)
        ->and($sim->error)->toContain('budget');

    // Cached cases cost nothing, but one case is still uncached: a near-zero budget stops before any case.
    $zero = app(SimulationService::class)->createBatch($f['workspace']->id, ['limit' => 10], 0.000001, null);
    expect($zero->fresh()->status)->toBe('budget_exhausted')->and($zero->fresh()->totals['done'])->toBe(0);
});

test('pause, resume and cancel', function () {
    $f = loopTSimFixture();
    loopTCloneCase($f['case'], 'two');
    loopTFakePipeline();
    loopTFakeJudge();
    Queue::fake();
    $service = app(SimulationService::class);

    $sim = $service->createBatch($f['workspace']->id, ['limit' => 10], null, null);
    Queue::assertPushed(RunSimulationJob::class, 1);
    expect($sim->status)->toBe('pending');

    $service->pause($sim);
    expect(app(SimulationRunner::class)->step($sim->fresh()))->toBeFalse()
        ->and(SimulationResult::count())->toBe(0)
        ->and($sim->fresh()->status)->toBe('paused');

    $service->resume($sim->fresh());
    Queue::assertPushed(RunSimulationJob::class, 2);
    // A step with no time left does one case and asks to continue.
    expect(app(SimulationRunner::class)->step($sim->fresh(), 0))->toBeTrue()
        ->and(SimulationResult::count())->toBe(1);

    $service->cancel($sim->fresh());
    expect(app(SimulationRunner::class)->step($sim->fresh()))->toBeFalse()
        ->and($sim->fresh()->status)->toBe('cancelled')
        ->and(SimulationResult::count())->toBe(1);

    expect(fn () => $service->resume($sim->fresh()))->toThrow(InvalidArgumentException::class);
});

test('a batch on the long queue is dispatched to redis-long / ai-bulk; gates to ai', function () {
    config(['agent.simulation.batch_connection' => 'redis-long']);
    $f = loopTSimFixture();
    Queue::fake();

    app(SimulationService::class)->createBatch($f['workspace']->id, ['limit' => 10], null, null);
    app(SimulationService::class)->createGate(loopTPlaybookProposal($f), null);

    Queue::assertPushed(RunSimulationJob::class, fn ($job) => $job->kind === 'batch' && $job->connection === 'redis-long' && $job->queue === 'ai-bulk');
    Queue::assertPushed(RunSimulationJob::class, fn ($job) => $job->kind === 'gate' && $job->queue === 'ai');
});

test('estimate, results and human override over HTTP', function () {
    $f = loopTSimFixture();
    loopTFakePipeline(['Guaranteed delivery!']);
    loopTFakeJudge();
    $admin = adminUser($f['workspace']);

    $this->actingAs($admin)->postJson('/agent/simulations/estimate', ['limit' => 5])
        ->assertOk()->assertJsonPath('estimate.cases', 1)->assertJsonPath('estimate.estimated_micro_usd', 68000)
        ->assertJsonPath('frozen_case_ids', [$f['case']->id]);

    $id = $this->actingAs($admin)->postJson('/agent/simulations', ['limit' => 5, 'budget_usd' => 2])->assertStatus(202)->json('simulation.id');
    $this->actingAs($admin)->getJson("/agent/simulations/{$id}")->assertOk()->assertJsonPath('simulation.status', 'completed');
    $result = $this->actingAs($admin)->getJson("/agent/simulations/{$id}/results?verdict=fail")->assertOk()->json('data.0');
    expect($result['verdict'])->toBe('fail')
        ->and($result['human_reply'])->toContain('ships tomorrow')
        ->and($result['ai_reply'])->toBe('Guaranteed delivery!');

    $this->actingAs($admin)->patchJson("/agent/simulations/{$id}/results/{$result['id']}", ['verdict' => 'pass', 'note' => 'Fine for this customer'])
        ->assertOk()->assertJsonPath('result.effective_verdict', 'pass')->assertJsonPath('result.human_override', 'pass');
    $this->actingAs($admin)->getJson("/agent/simulations/{$id}/results?verdict=pass")->assertJsonCount(1, 'data');

    $this->actingAs($admin)->postJson("/agent/playbooks/{$f['playbook']->id}/tests/run")->assertStatus(202)->assertJsonPath('simulation.kind', 'playbook_tests');
});
