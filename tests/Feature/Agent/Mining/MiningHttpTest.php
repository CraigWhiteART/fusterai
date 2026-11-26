<?php

require_once __DIR__.'/MiningTestHelpers.php';

use App\Domains\Agent\Mining\Jobs\RunMiningStepJob;
use App\Domains\Agent\Mining\Models\MiningRun;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->ctx = mineTWorkspace();
    $this->admin = adminUser($this->ctx['workspace']);
});

test('guests get 401 and non-admins get 403 on every mining endpoint', function () {
    $run = mineTStart($this->ctx);

    $this->getJson('/agent/mining')->assertUnauthorized();
    $this->postJson('/agent/mining/estimate')->assertUnauthorized();

    foreach ([agentUser($this->ctx['workspace']), managerUser($this->ctx['workspace'])] as $user) {
        $this->actingAs($user)->getJson('/agent/mining')->assertForbidden();
        $this->actingAs($user)->postJson('/agent/mining/estimate', [])->assertForbidden();
        $this->actingAs($user)->postJson('/agent/mining', [])->assertForbidden();
        $this->actingAs($user)->getJson("/agent/mining/{$run->id}")->assertForbidden();
        foreach (['pause', 'resume', 'cancel'] as $action) {
            $this->actingAs($user)->postJson("/agent/mining/{$run->id}/{$action}")->assertForbidden();
        }
    }

    expect($run->fresh()->status)->toBe(MiningRun::STATUS_QUEUED);
});

test('another workspace\'s run is 404', function () {
    $other = mineTWorkspace();
    $run = mineTStart($other);

    $this->actingAs($this->admin)->getJson("/agent/mining/{$run->id}")->assertNotFound();
    $this->actingAs($this->admin)->postJson("/agent/mining/{$run->id}/cancel")->assertNotFound();
    expect($run->fresh()->status)->toBe(MiningRun::STATUS_QUEUED);
});

test('an admin estimates, starts, follows, pauses, resumes and cancels a run', function () {
    mineTConversation($this->ctx, 'Where is my order?', 'It shipped yesterday.');

    $this->actingAs($this->admin)->getJson('/agent/mining')
        ->assertOk()
        ->assertJsonPath('runs', [])
        ->assertJsonPath('defaults.sample_strategy', 'stratified')
        ->assertJsonPath('strategies', ['stratified', 'newest']);

    $this->actingAs($this->admin)->postJson('/agent/mining/estimate', ['months' => 6, 'max_conversations' => 100, 'budget_usd' => 2])
        ->assertOk()
        ->assertJsonPath('estimate.selected', 1)
        ->assertJsonPath('estimate.stages.extract.calls', 1)
        ->assertJsonPath('estimate.options.max_conversations', 100)
        ->assertJsonStructure(['estimate' => ['total' => ['calls', 'tokens_in', 'tokens_out', 'cost_usd'], 'within_budget', 'assumptions']]);
    expect(MiningRun::count())->toBe(0);

    $this->actingAs($this->admin)->postJson('/agent/mining/estimate', ['sample_strategy' => 'everything', 'budget_usd' => 10000])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sample_strategy', 'budget_usd']);

    $id = $this->actingAs($this->admin)->postJson('/agent/mining', ['months' => 6, 'budget_usd' => 2])
        ->assertCreated()
        ->assertJsonPath('run.status', 'queued')
        ->assertJsonPath('run.budget_usd', 2)
        ->assertJsonPath('run.estimate.selected', 1)
        ->json('run.id');
    Queue::assertPushed(RunMiningStepJob::class, fn (RunMiningStepJob $job) => $job->runId === $id
        && $job->connection === 'redis-long' && $job->queue === 'ai-bulk');

    $this->actingAs($this->admin)->postJson('/agent/mining', [])->assertStatus(409);

    $this->actingAs($this->admin)->getJson("/agent/mining/{$id}")
        ->assertOk()
        ->assertJsonStructure(['run' => [
            'id', 'status', 'stage', 'stages', 'stage_index', 'pause_reason', 'message', 'options', 'cost_usd', 'budget_usd', 'remaining_budget_usd',
            'tokens' => ['in', 'out'], 'usage' => ['extract' => ['calls', 'cache_hits', 'tokens_in', 'tokens_out', 'cost_usd'], 'cluster', 'synthesise', 'voice'],
            'progress' => ['eligible', 'selected', 'pending', 'done', 'failed', 'extracted_new', 'reused', 'macro_copies', 'situations', 'situations_synthesised', 'proposals_emitted'],
            'dropped', 'coverage', 'inconsistencies', 'voice', 'proposals', 'estimate', 'last_error',
        ]]);

    $this->actingAs($this->admin)->postJson("/agent/mining/{$id}/pause")->assertOk()->assertJsonPath('run.status', 'paused')->assertJsonPath('run.pause_reason', 'user');
    $this->actingAs($this->admin)->postJson("/agent/mining/{$id}/pause")->assertStatus(409);
    $this->actingAs($this->admin)->postJson("/agent/mining/{$id}/resume", ['budget_usd' => 3])->assertOk()
        ->assertJsonPath('run.status', 'running')
        ->assertJsonPath('run.budget_usd', 3);
    $this->actingAs($this->admin)->postJson("/agent/mining/{$id}/cancel")->assertOk()->assertJsonPath('run.status', 'cancelled');
    $this->actingAs($this->admin)->postJson("/agent/mining/{$id}/explode")->assertNotFound();

    $this->actingAs($this->admin)->getJson('/agent/mining')->assertOk()->assertJsonPath('runs.0.id', $id)->assertJsonPath('active_run_id', null);
});

test('a finished run shows coverage per situation, dropped counts and proposal links', function () {
    mineTFakeAll();
    foreach (range(1, 3) as $i) {
        mineTConversation($this->ctx, 'I want a refund please', 'Refunds are available within 30 days of delivery.');
    }
    $run = mineTRun(mineTStart($this->ctx));

    $json = $this->actingAs($this->admin)->getJson("/agent/mining/{$run->id}")->assertOk()->json('run');

    expect($json['status'])->toBe('completed')
        ->and($json['coverage'][0])->toMatchArray(['key' => 'refund_request', 'conversation_count' => 3, 'share' => 1.0, 'is_new' => false, 'status' => 'synthesised'])
        ->and($json['coverage'][0]['proposal']['proposal_id'])->toBeInt()
        ->and($json['coverage'][0]['proposal']['status'])->toBe('draft')
        ->and($json['proposals'])->toHaveKeys(['situation:refund_request', 'voice', 'summary'])
        ->and($json['dropped'])->toHaveKey('rule_no_evidence')
        ->and($json['voice']['sample_size'])->toBe(3);
});
