<?php

require_once __DIR__.'/AgentTestHelpers.php';
require_once __DIR__.'/LoopTestHelpers.php';

use App\Domains\Agent\Agents\FeedbackManagerAgent;
use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\AgentTestCase;
use App\Domains\Agent\Models\FeedbackEvent;
use App\Domains\Agent\Models\PlaybookVersion;
use App\Domains\Agent\Models\Proposal;
use App\Domains\Agent\Models\Simulation;
use App\Domains\Agent\Models\VoiceVersion;
use App\Domains\Agent\Simulation\TestCaseService;
use App\Domains\Conversation\Models\AiSuggestion;
use App\Domains\Conversation\Models\Thread;
use App\Events\AiSuggestionFailed;
use App\Events\AiSuggestionReady;
use App\Events\NewThreadReceived;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

function loopTManager(array $data): void
{
    FeedbackManagerAgent::fake([agentTStructured(array_merge(loopTManagerDefaults(), $data), 2000, 300, 'claude-opus-5-5')]);
}

function loopTManagerDefaults(): array
{
    return [
        'scope' => 'voice', 'reason' => 'Brand-wide wording.', 'question' => '', 'playbook_slug' => '', 'knowledge_id' => 0, 'example_id' => 0,
        'title' => 'Never promise delivery', 'one_off_instruction' => '', 'ops' => [], 'must_not_include' => [], 'must_include' => [], 'suggestions' => [],
    ];
}

function loopTOp(array $op): array
{
    return array_merge(['op' => 'add_rule', 'field' => '', 'item_id' => '', 'text' => '', 'condition' => '', 'value' => '', 'kind' => '', 'title' => '',
        'body' => '', 'scope_global' => false, 'scope_playbooks' => []], $op);
}

/** @return array{0: array, 1: AgentRun, 2: User} */
function loopTFeedbackFixture(): array
{
    $f = loopTWorkspace();
    agentTEnableShopify($f['workspace']->id);
    $run = loopTLiveRun($f);
    AiSuggestion::create(['conversation_id' => $f['conversation']->id, 'type' => 'reply', 'content' => (string) $run->draft, 'agent_run_id' => $run->id]);
    loopTFakePipeline(['Hi Alex, your order is being prepared. I will let you know when it ships.']);
    loopTFakeConflictJudge();
    Http::fake();
    Http::preventStrayRequests();
    Mail::fake();

    return [$f, $run, User::factory()->create(['workspace_id' => $f['workspace']->id, 'role' => 'agent'])];
}

function loopTSubmit($test, array $f, AgentRun $run, User $user, array $body = [])
{
    return $test->actingAs($user)->postJson("/agent/conversations/{$f['conversation']->id}/feedback", array_merge([
        'run_id' => $run->id, 'text' => 'Don\'t say that, we never promise delivery dates.', 'selected_text' => 'guaranteed to arrive Friday',
    ], $body));
}

test('voice feedback becomes a proposal with a diff, a conflict report and a side-effect-free preview', function () {
    [$f, $run, $user] = loopTFeedbackFixture();
    Event::fake([AiSuggestionReady::class, AiSuggestionFailed::class, NewThreadReceived::class]);
    loopTManager(['scope' => 'voice', 'ops' => [loopTOp(['op' => 'add_dont', 'text' => 'guaranteed'])], 'must_not_include' => ['guaranteed']]);
    $suggestions = AiSuggestion::count();
    $threads = Thread::where('conversation_id', $f['conversation']->id)->count();
    $before = $f['conversation']->fresh()->only(['status', 'handover_reason', 'updated_at']);

    $response = loopTSubmit($this, $f, $run, $user)->assertStatus(202);

    $feedback = $this->actingAs($user)->getJson('/agent/feedback/'.$response->json('feedback.id'))->assertOk()->json('feedback');
    expect($feedback['status'])->toBe('proposal')
        ->and($feedback['scope'])->toBe('voice')
        ->and($feedback['proposal']['status'])->toBe('draft')
        ->and($feedback['proposal']['origin'])->toBe('feedback')
        ->and($feedback['proposal']['diff'][0])->toMatchArray(['op' => 'add_dont', 'label' => 'Add don\'t phrase', 'before' => null, 'after' => 'guaranteed', 'target_label' => 'Brand voice'])
        ->and($feedback['proposal']['conflicts']['checked_at'])->not->toBeNull()
        ->and($feedback['preview']['status'])->toBe('ready')
        ->and($feedback['preview']['old_draft'])->toBe($run->draft)
        ->and($feedback['preview']['new_draft'])->toContain('being prepared')
        ->and($feedback['preview']['instruction_diff'])->toHaveCount(1);

    $event = FeedbackEvent::findOrFail($feedback['id']);
    expect($event->kind)->toBe('comment')->and($event->run_id)->toBe($run->id)->and($event->meta['selected_text'])->toBe('guaranteed to arrive Friday');

    // The preview ran with purpose preview, wrote nothing visible, called nothing.
    $preview = AgentRun::findOrFail($feedback['preview']['run_id']);
    expect($preview->purpose)->toBe('preview')
        ->and($preview->config['overlay']['voice']['dont_phrases'] ?? null)->not->toBeNull()
        ->and(AiSuggestion::count())->toBe($suggestions)
        ->and(Thread::where('conversation_id', $f['conversation']->id)->count())->toBe($threads)
        ->and($f['conversation']->fresh()->only(['status', 'handover_reason', 'updated_at']))->toEqual($before);
    Http::assertNothingSent();
    Mail::assertNothingSent();
    Event::assertNotDispatched(AiSuggestionReady::class);
    Event::assertNotDispatched(NewThreadReceived::class);

    // Approval saves the conversation as a test case with expectations from the feedback.
    $admin = adminUser($f['workspace']);
    $this->actingAs($admin)->postJson("/agent/proposals/{$feedback['proposal']['id']}/approve")->assertOk()->assertJsonPath('proposal.status', 'applied');
    $test = AgentTestCase::where('proposal_id', $feedback['proposal']['id'])->sole();
    expect($test->source)->toBe('feedback')
        ->and($test->expectation['must_not_include'])->toContain('guaranteed')
        ->and($test->expectation['expected_outcome'])->toBe('draft')
        ->and($test->feedback_event_id)->toBe($event->id)
        ->and($test->frozenCase->cut_thread_id)->toBe($f['thread']->id)
        ->and($event->fresh()->meta['proposal_id'])->toBe($feedback['proposal']['id']);
});

test('playbook feedback targets the named playbook with field-level ops', function () {
    [$f, $run, $user] = loopTFeedbackFixture();
    $rule = $f['playbook']->liveVersion->rules[3];
    loopTManager(['scope' => 'playbook', 'playbook_slug' => 'order_status', 'ops' => [
        loopTOp(['op' => 'replace_rule', 'item_id' => $rule['id'], 'text' => 'Never promise a dispatch or delivery date; quote the carrier estimate only.']),
    ]]);

    $id = loopTSubmit($this, $f, $run, $user)->assertStatus(202)->json('feedback.id');

    $p = Proposal::where('feedback_event_id', $id)->sole();
    expect($p->scope)->toBe('playbook')
        ->and($p->changes->sole()->target_id)->toBe($f['playbook']->id)
        ->and($p->changes->sole()->diff[0]['op'])->toBe('replace_rule')
        ->and($p->changes->sole()->diff[0]['before'])->toContain('Do not promise');
});

test('knowledge feedback creates or edits a knowledge item', function () {
    [$f, $run, $user] = loopTFeedbackFixture();
    $item = loopTKnowledge($f['workspace']->id, 'Warranty is 6 months.');
    loopTManager(['scope' => 'knowledge', 'knowledge_id' => $item->id, 'ops' => [loopTOp(['op' => 'set_field', 'field' => 'body', 'value' => 'Warranty is 12 months from delivery.'])]]);

    $id = loopTSubmit($this, $f, $run, $user, ['text' => 'Our warranty is 12 months now.'])->json('feedback.id');
    $change = Proposal::where('feedback_event_id', $id)->sole()->changes->sole();
    expect($change->target_type)->toBe('knowledge')->and($change->target_id)->toBe($item->id)->and($change->after['body'])->toBe('Warranty is 12 months from delivery.');

    loopTManager(['scope' => 'knowledge', 'knowledge_id' => 0, 'ops' => [loopTOp(['op' => 'create_knowledge', 'kind' => 'policy', 'title' => 'Duties', 'body' => 'Customers outside AU pay import duties.', 'scope_playbooks' => ['duties_tariffs']])]]);
    $id2 = loopTSubmit($this, $f, $run, $user, ['text' => 'Duties are paid by the customer.'])->json('feedback.id');
    $created = Proposal::where('feedback_event_id', $id2)->sole()->changes->sole();
    expect($created->target_id)->toBeNull()->and($created->after['scope']['playbooks'])->toBe(['duties_tariffs']);
});

test('example feedback retires the example the draft was shown', function () {
    $f = loopTWorkspace();
    $example = loopTExample($f['workspace']->id, 'Guaranteed Friday!');
    $run = loopTLiveRun($f, exampleIds: [$example->id]);
    loopTFakePipeline();
    loopTFakeConflictJudge();
    loopTManager(['scope' => 'example', 'example_id' => $example->id, 'ops' => [loopTOp(['op' => 'retire_example', 'text' => 'Promises a date'])]]);

    $id = loopTSubmit($this, $f, $run, agentUser($f['workspace']))->json('feedback.id');
    $change = Proposal::where('feedback_event_id', $id)->sole()->changes->sole();
    expect($change->target_type)->toBe('example')->and($change->target_id)->toBe($example->id)->and($change->after['status'])->toBe('retired');

    // The preview hides that example from retrieval.
    $preview = AgentRun::where('purpose', 'preview')->latest('id')->firstOrFail();
    expect($preview->config['overlay']['examples_remove'])->toBe([$example->id]);
});

test('unclear feedback asks one question; the answer re-routes', function () {
    [$f, $run, $user] = loopTFeedbackFixture();
    loopTManager(['scope' => 'unclear', 'question' => 'Is this about this customer only, or every order-status reply?']);

    $id = loopTSubmit($this, $f, $run, $user, ['text' => 'hmm not like this'])->json('feedback.id');
    $feedback = $this->actingAs($user)->getJson("/agent/feedback/{$id}")->json('feedback');
    expect($feedback['status'])->toBe('question')
        ->and($feedback['question'])->toBe('Is this about this customer only, or every order-status reply?')
        ->and($feedback['proposal'])->toBeNull()
        ->and(Proposal::count())->toBe(0);

    loopTManager(['scope' => 'voice', 'ops' => [loopTOp(['op' => 'add_do', 'text' => 'Keep it short'])]]);
    $this->actingAs($user)->postJson("/agent/feedback/{$id}/answer", ['answer' => 'Every reply.'])->assertStatus(202);
    FeedbackManagerAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Every reply.'));
    expect($this->actingAs($user)->getJson("/agent/feedback/{$id}")->json('feedback.status'))->toBe('proposal');

    $this->actingAs($user)->postJson("/agent/feedback/{$id}/answer", ['answer' => 'again'])->assertStatus(409);
});

test('one-off feedback previews with an ephemeral instruction and saves nothing', function () {
    [$f, $run, $user] = loopTFeedbackFixture();
    loopTManager(['scope' => 'one_off', 'one_off_instruction' => 'Mention that this customer already got a refund.']);
    $voiceVersions = VoiceVersion::count();
    $playbookVersions = PlaybookVersion::count();

    $id = loopTSubmit($this, $f, $run, $user, ['text' => 'She already got her refund, say so.'])->json('feedback.id');
    $feedback = $this->actingAs($user)->getJson("/agent/feedback/{$id}")->json('feedback');

    expect($feedback['status'])->toBe('one_off')
        ->and($feedback['one_off_instruction'])->toBe('Mention that this customer already got a refund.')
        ->and($feedback['proposal'])->toBeNull()
        ->and($feedback['preview']['status'])->toBe('ready')
        ->and($feedback['preview']['old_draft'])->toBe($run->draft)
        ->and(Proposal::count())->toBe(0)
        ->and(VoiceVersion::count())->toBe($voiceVersions)
        ->and(PlaybookVersion::count())->toBe($playbookVersions);

    $preview = AgentRun::findOrFail($feedback['preview']['run_id']);
    expect(collect($preview->config['overlay']['playbooks']['order_status']['rules'])->last()['then'])->toContain('already got a refund');
});

test('a scope override re-routes without asking, and supersedes the open proposal', function () {
    [$f, $run, $user] = loopTFeedbackFixture();
    loopTManager(['scope' => 'voice', 'ops' => [loopTOp(['op' => 'add_dont', 'text' => 'guaranteed'])]]);
    $id = loopTSubmit($this, $f, $run, $user)->json('feedback.id');
    $first = Proposal::where('feedback_event_id', $id)->sole();

    loopTManager(['scope' => 'playbook', 'playbook_slug' => 'order_status', 'ops' => [loopTOp(['op' => 'add_rule', 'text' => 'Never promise delivery dates.'])]]);
    $this->actingAs($user)->postJson("/agent/feedback/{$id}/scope", ['scope' => 'playbook', 'playbook_id' => $f['playbook']->id])->assertStatus(202);

    FeedbackManagerAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Scope chosen by the reviewer: playbook'));
    $feedback = $this->actingAs($user)->getJson("/agent/feedback/{$id}")->json('feedback');
    expect($first->fresh()->status)->toBe('rejected')
        ->and($feedback['scope'])->toBe('playbook')
        ->and($feedback['scope_overridden'])->toBeTrue()
        ->and($feedback['proposal']['id'])->not->toBe($first->id)
        ->and($feedback['proposal']['changes'][0]['target_id'])->toBe($f['playbook']->id);

    // Override to one_off: no manager call at all.
    FeedbackManagerAgent::fake(fn () => throw new RuntimeException('the manager must not be asked'));
    $this->actingAs($user)->postJson("/agent/feedback/{$id}/scope", ['scope' => 'one_off'])->assertStatus(202);
    expect($this->actingAs($user)->getJson("/agent/feedback/{$id}")->json('feedback.status'))->toBe('one_off');
});

test('permission, limit and mode changes from the manager are refused and returned as suggestions', function () {
    [$f, $run, $user] = loopTFeedbackFixture();
    loopTManager(['scope' => 'playbook', 'playbook_slug' => 'order_status', 'ops' => [
        loopTOp(['op' => 'set_field', 'field' => 'allowed_actions', 'value' => 'refund']),
        loopTOp(['op' => 'set_field', 'field' => 'rollout_percent', 'value' => '100']),
        loopTOp(['op' => 'add_rule', 'text' => 'Apologise once.']),
    ], 'suggestions' => ['Allow refunds for this playbook in settings.']]);

    $id = loopTSubmit($this, $f, $run, $user, ['text' => 'Just refund them automatically.'])->json('feedback.id');
    $p = Proposal::where('feedback_event_id', $id)->sole();
    $fields = collect($p->suggestions)->pluck('op.field')->filter()->all();

    expect($fields)->toContain('allowed_actions', 'rollout_percent')
        ->and(collect($p->suggestions)->pluck('reason')->implode(' '))->toContain('Allow refunds for this playbook in settings.')
        ->and($p->changes->sole()->ops)->toHaveCount(1)
        ->and($p->changes->sole()->ops[0]['op'])->toBe('add_rule');

    // Only forbidden ops: no proposal, suggestions shown.
    loopTManager(['scope' => 'playbook', 'ops' => [loopTOp(['op' => 'set_field', 'field' => 'mode', 'value' => 'auto'])], 'suggestions' => []]);
    $id2 = loopTSubmit($this, $f, $run, $user, ['text' => 'Turn on auto-send.'])->json('feedback.id');
    $feedback = $this->actingAs($user)->getJson("/agent/feedback/{$id2}")->json('feedback');
    expect($feedback['status'])->toBe('suggestions_only')
        ->and(collect($feedback['suggestions'])->pluck('op.field')->all())->toContain('mode')
        ->and(Proposal::where('feedback_event_id', $id2)->count())->toBe(0);
});

test('invalid manager ops are retried once with the validation errors', function () {
    [$f, $run, $user] = loopTFeedbackFixture();
    FeedbackManagerAgent::fake([
        agentTStructured(array_merge(loopTManagerDefaults(), ['scope' => 'playbook', 'ops' => [loopTOp(['op' => 'remove_rule', 'item_id' => 'r-does-not-exist'])]])),
        agentTStructured(array_merge(loopTManagerDefaults(), ['scope' => 'playbook', 'ops' => [loopTOp(['op' => 'add_rule', 'text' => 'Quote the carrier only.'])]])),
    ]);

    $id = loopTSubmit($this, $f, $run, $user)->json('feedback.id');

    FeedbackManagerAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'no rule with id "r-does-not-exist"'));
    expect(Proposal::where('feedback_event_id', $id)->sole()->changes->sole()->ops[0]['then'])->toBe('Quote the carrier only.');
});

test('the manager sees the run\'s recorded config: versions, cited rules, knowledge and examples', function () {
    $f = loopTWorkspace();
    $item = loopTKnowledge($f['workspace']->id, 'Dispatch takes 2 days.');
    $example = loopTExample($f['workspace']->id, 'Old example reply');
    $run = loopTLiveRun($f, knowledgeIds: [$item->id], exampleIds: [$example->id]);
    loopTFakePipeline();
    loopTFakeConflictJudge();
    loopTManager(['scope' => 'voice', 'ops' => [loopTOp(['op' => 'add_dont', 'text' => 'guaranteed'])]]);

    loopTSubmit($this, $f, $run, agentUser($f['workspace']));

    $ruleId = $f['playbook']->liveVersion->rules[0]['id'];
    FeedbackManagerAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, "[{$ruleId}]")
        && str_contains($prompt->prompt, '(cited by the draft)')
        && str_contains($prompt->prompt, "[K{$item->id}] Dispatch takes 2 days.")
        && str_contains($prompt->prompt, "[X{$example->id}]")
        && str_contains($prompt->prompt, 'guaranteed to arrive Friday'));
});

test('a feedback proposal with affected test cases starts the regression gate', function () {
    [$f, $run, $user] = loopTFeedbackFixture();
    loopTFakeJudge();
    loopTSnapshot($f);
    $case = loopTFrozenCase($f);
    app(TestCaseService::class)->create($case, 'Where is it', [], $f['playbook']->id);
    loopTManager(['scope' => 'playbook', 'playbook_slug' => 'order_status', 'ops' => [loopTOp(['op' => 'add_rule', 'text' => 'Quote the carrier only.'])]]);

    $id = loopTSubmit($this, $f, $run, $user)->json('feedback.id');

    $p = Proposal::where('feedback_event_id', $id)->sole();
    $gate = Simulation::findOrFail($p->gate_simulation_id);
    expect($gate->kind)->toBe('gate')->and($gate->status)->toBe('completed')->and($p->status)->toBe('ready')
        ->and($gate->totals['done'])->toBe(1);
});
