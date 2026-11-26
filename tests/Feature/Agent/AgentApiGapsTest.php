<?php

require_once __DIR__.'/AgentTestHelpers.php';
require_once __DIR__.'/LoopTestHelpers.php';

use App\Domains\Agent\Manager\ManagerChatService;
use App\Domains\Agent\Manager\ScopeGuard;
use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Qa\Models\QaReview;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use Illuminate\Support\Facades\DB;

/*
 * Small API gaps reported by the frontend: QA per conversation, the full
 * verdict payload, gap thresholds, run citations with their conversation,
 * human-readable scope refusals.
 */

function gapTReview(array $f, Conversation $conversation, array $extra = []): QaReview
{
    $thread = Thread::factory()->create(['conversation_id' => $conversation->id, 'user_id' => null, 'customer_id' => null, 'type' => 'message', 'body' => 'Reply', 'body_plain' => 'Reply']);

    return QaReview::query()->forceCreate(array_merge([
        'workspace_id' => $f['workspace']->id, 'conversation_id' => $conversation->id, 'reviewed_thread_id' => $thread->id,
        'author_type' => 'human', 'status' => 'reviewed', 'triage_score' => 4, 'overall' => '4.0', 'reviewer_prompt_version' => 'qa-v1',
        'replied_at' => now(), 'failure_class' => 'none',
    ], $extra));
}

test('QA reviews can be listed for one conversation', function () {
    $f = loopTWorkspace();
    $other = Conversation::factory()->create(['workspace_id' => $f['workspace']->id, 'mailbox_id' => $f['mailbox']->id, 'customer_id' => $f['customer']->id]);
    $mine = gapTReview($f, $f['conversation']);
    gapTReview($f, $other);
    $dismissed = gapTReview($f, $f['conversation'], ['human_verdict' => 'dismissed']);
    $admin = adminUser($f['workspace']);

    $ids = $this->actingAs($admin)->getJson("/agent/qa/reviews?conversation_id={$f['conversation']->id}")->assertOk()->json('data.*.id');
    expect($ids)->toBe([$mine->id]);

    $ids = $this->actingAs($admin)->getJson("/agent/qa/reviews?conversation_id={$f['conversation']->id}&include_dismissed=1")->assertOk()->json('data.*.id');
    expect($ids)->toEqualCanonicalizing([$mine->id, $dismissed->id]);

    $this->actingAs(agentUser($f['workspace']))->getJson("/agent/qa/reviews?conversation_id={$f['conversation']->id}")->assertForbidden();
});

test('setting a QA verdict answers the full review payload', function () {
    $f = loopTWorkspace();
    $review = gapTReview($f, $f['conversation']);
    $admin = adminUser($f['workspace']);

    $shown = $this->actingAs($admin)->getJson("/agent/qa/reviews/{$review->id}")->assertOk()->json('review');
    $after = $this->actingAs($admin)->postJson("/agent/qa/reviews/{$review->id}/verdict", ['verdict' => 'dismissed', 'note' => 'fine'])->assertOk()->json('review');

    expect(array_keys($after))->toEqualCanonicalizing(array_keys($shown))
        ->and($after['human_verdict'])->toBe('dismissed')
        ->and($after)->toHaveKeys(['reviewed_reply', 'customer_message', 'gap_group', 'subject']);

    $undo = $this->actingAs($admin)->postJson("/agent/qa/reviews/{$review->id}/verdict", ['verdict' => null])->assertOk()->json('review');
    expect($undo['human_verdict'])->toBeNull()->and($undo)->toHaveKey('reviewed_reply');
});

test('gap payloads carry the proposal threshold', function () {
    config(['agent_qa.gaps.proposal_threshold' => 4]);
    $f = loopTWorkspace();
    DB::table('agent_qa_gaps')->insert([
        'workspace_id' => $f['workspace']->id, 'failure_class' => 'missing_knowledge', 'status' => 'open', 'label' => 'Warranty', 'topic' => 'warranty',
        'terms' => json_encode(['warranty']), 'review_count' => 2, 'conversation_ids' => json_encode([$f['conversation']->id]), 'created_at' => now(), 'updated_at' => now(), 'last_seen_at' => now(),
    ]);

    $this->actingAs(adminUser($f['workspace']))->getJson('/agent/qa/gaps')->assertOk()
        ->assertJsonPath('threshold', 4)
        ->assertJsonPath('data.0.threshold', 4);
});

test('run citations carry the conversation the run answered', function () {
    $f = loopTWorkspace();
    $run = loopTLiveRun($f);

    $out = ManagerChatService::withRunConversations($f['workspace']->id, [
        ['type' => 'run', 'id' => $run->id, 'label' => 'Run', 'source' => 'read'],
        ['type' => 'run', 'id' => 999999, 'label' => 'Gone', 'source' => 'answer'],
        ['type' => 'playbook', 'id' => $f['playbook']->id, 'label' => 'P', 'source' => 'read'],
    ]);

    expect($out[0]['conversation_id'])->toBe($f['conversation']->id)
        ->and($out[1]['conversation_id'])->toBeNull()
        ->and($out[2])->not->toHaveKey('conversation_id');
    expect(AgentRun::find($run->id)->conversation_id)->toBe($f['conversation']->id);
});

test('scope refusals are written for people, with playbook names', function () {
    $f = loopTWorkspace();
    $refund = Playbook::where('workspace_id', $f['workspace']->id)->where('slug', 'refund_request')->sole();
    $guard = app(ScopeGuard::class);

    $problems = $guard->violations($f['workspace']->id, [
        ['target_type' => 'voice', 'target_id' => null, 'ops' => [['op' => 'add_do', 'text' => 'x']]],
        ['target_type' => 'playbook', 'target_id' => $refund->id, 'ops' => [['op' => 'add_rule', 'then' => 'x']]],
    ], ['exclude_playbook_ids' => [$refund->id], 'exclude_targets' => ['voice']]);

    expect($problems)->toHaveCount(2)
        ->and($problems[0])->toBe('Left out the change to the voice: you asked not to change the voice.')
        ->and($problems[1])->toBe("Left out the change to the playbook “{$refund->name}”: you asked not to touch the playbook “{$refund->name}”.");
    foreach ($problems as $p) {
        expect($p)->not->toContain('changes.')->not->toContain('refund_request');
    }

    $only = $guard->violations($f['workspace']->id, [
        ['target_type' => 'playbook', 'target_id' => null, 'ops' => [['op' => 'add_rule', 'then' => 'x']]],
    ], ['only_playbook_ids' => [$refund->id]]);
    expect($only[0])->toStartWith('Left out the new playbook: you asked to change only the playbooks “');
});
