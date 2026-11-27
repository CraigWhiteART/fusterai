<?php

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Conversation\Models\AiSuggestion;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Customer\Models\Customer;
use App\Domains\Mailbox\Models\Mailbox;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * The inbox list shows where the AI agent got to on each open conversation:
 * "draft_ready" (a draft is waiting) or "needs_you" (handed over, or the
 * draft says a person still has to decide or do something).
 */

beforeEach(function () {
    Queue::fake();
    Event::fake();

    $this->workspace = Workspace::factory()->create();
    $this->user = agentUser($this->workspace);
    $this->mailbox = Mailbox::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->customer = Customer::factory()->create(['workspace_id' => $this->workspace->id]);
});

/** An open conversation with one customer message, written ten minutes ago. */
function markerTConversation(array $attributes = []): array
{
    $t = test();
    $conversation = Conversation::factory()->create(array_merge([
        'workspace_id' => $t->workspace->id,
        'mailbox_id' => $t->mailbox->id,
        'customer_id' => $t->customer->id,
        'status' => 'open',
        'last_reply_at' => now()->subMinutes(10),
    ], $attributes));

    return [$conversation, markerTCustomerMessage($conversation, now()->subMinutes(10))];
}

function markerTCustomerMessage(Conversation $conversation, $at): Thread
{
    return Thread::factory()->create([
        'conversation_id' => $conversation->id,
        'customer_id' => $conversation->customer_id,
        'user_id' => null,
        'type' => 'message',
        'created_at' => $at,
    ]);
}

function markerTRun(Conversation $conversation, Thread $thread, array $attributes = []): AgentRun
{
    return AgentRun::create(array_merge([
        'workspace_id' => $conversation->workspace_id,
        'purpose' => AgentRun::PURPOSE_LIVE,
        'conversation_id' => $conversation->id,
        'customer_thread_id' => $thread->id,
        'idempotency_key' => (string) Str::uuid(),
        'status' => AgentRun::STATUS_COMPLETED,
        'config' => [],
        'config_hash' => str_repeat('a', 64),
        'decision' => 'draft',
        'draft' => 'Hi, your order ships tomorrow.',
        'auto_send' => ['blockers' => ['playbook_mode']],
        'completed_at' => now()->subMinutes(9),
    ], $attributes));
}

/** @return array<int, array{state: string, label: string|null}|null> conversation id => marker */
function markerTList(): array
{
    $t = test();
    $markers = [];
    $t->actingAs($t->user)->get('/conversations')->assertOk()->assertInertia(function ($page) use (&$markers) {
        $page->where('conversations.data', function ($rows) use (&$markers) {
            foreach ($rows as $row) {
                $markers[$row['id']] = $row['agent_marker'];
            }

            return true;
        })->etc();
    });

    return $markers;
}

test('a draft waiting for the latest customer message is marked "draft ready"', function () {
    [$conversation, $thread] = markerTConversation();
    markerTRun($conversation, $thread);
    [$untouched] = markerTConversation();

    $markers = markerTList();

    expect($markers[$conversation->id])->toBe(['state' => 'draft_ready', 'label' => null])
        ->and($markers[$untouched->id])->toBeNull();
});

test('a handover, or a draft that needs a person, is marked "needs you" with the plain reason', function () {
    [$handedOver, $t1] = markerTConversation();
    markerTRun($handedOver, $t1, ['decision' => 'handover', 'draft' => null, 'handover_reason' => 'writer:needs_human_decision', 'auto_send' => ['blockers' => ['handover']]]);

    [$needsHuman, $t2] = markerTConversation();
    markerTRun($needsHuman, $t2, ['auto_send' => ['blockers' => ['playbook_mode', 'needs_human']]]);

    $markers = markerTList();

    expect($markers[$handedOver->id])->toBe(['state' => 'needs_you', 'label' => 'a person needs to make a decision'])
        ->and($markers[$needsHuman->id]['state'])->toBe('needs_you')
        ->and($markers[$needsHuman->id]['label'])->not->toContain('needs_human');
});

test('the newest completed live run for the latest customer message decides', function () {
    // Handed over first, then drafted anyway: the draft is what is waiting.
    [$redrafted, $t1] = markerTConversation();
    markerTRun($redrafted, $t1, ['decision' => 'handover', 'draft' => null, 'handover_reason' => 'no_instruction']);
    markerTRun($redrafted, $t1);

    // The customer wrote again and nothing has run for that message yet.
    [$newMessage, $t2] = markerTConversation();
    markerTRun($newMessage, $t2);
    markerTCustomerMessage($newMessage, now()->subMinutes(2));

    // Runs that are not finished live runs say nothing about the inbox.
    [$other, $t3] = markerTConversation();
    markerTRun($other, $t3, ['purpose' => AgentRun::PURPOSE_SHADOW]);
    [$unfinished, $t4] = markerTConversation();
    markerTRun($unfinished, $t4, ['status' => AgentRun::STATUS_RUNNING, 'completed_at' => null]);
    // Sent automatically: nothing is waiting.
    [$sent, $t5] = markerTConversation();
    markerTRun($sent, $t5, ['decision' => 'auto_send']);

    $markers = markerTList();

    expect($markers[$redrafted->id]['state'])->toBe('draft_ready')
        ->and($markers[$newMessage->id])->toBeNull()
        ->and($markers[$other->id])->toBeNull()
        ->and($markers[$unfinished->id])->toBeNull()
        ->and($markers[$sent->id])->toBeNull();
});

test('no marker once a person has replied, or the draft was used or dismissed', function () {
    [$answered, $t1] = markerTConversation();
    markerTRun($answered, $t1, ['decision' => 'handover', 'draft' => null, 'handover_reason' => 'risk:legal']);
    Thread::factory()->create(['conversation_id' => $answered->id, 'user_id' => $this->user->id, 'customer_id' => null, 'type' => 'message', 'created_at' => now()->subMinutes(3)]);

    // An automatic acknowledgement sent before the run finished is not an answer.
    [$acknowledged, $t2] = markerTConversation();
    Thread::factory()->create(['conversation_id' => $acknowledged->id, 'user_id' => null, 'customer_id' => null, 'type' => 'message', 'created_at' => now()->subMinutes(10)]);
    markerTRun($acknowledged, $t2);

    // A note is not a reply either.
    [$noted, $t3] = markerTConversation();
    markerTRun($noted, $t3);
    Thread::factory()->note()->create(['conversation_id' => $noted->id, 'user_id' => $this->user->id, 'created_at' => now()->subMinutes(3)]);

    [$dismissed, $t4] = markerTConversation();
    $suggestion = AiSuggestion::create(['conversation_id' => $dismissed->id, 'type' => 'reply', 'content' => 'Hi', 'accepted' => false]);
    markerTRun($dismissed, $t4, ['ai_suggestion_id' => $suggestion->id]);

    [$pending, $t5] = markerTConversation();
    $waiting = AiSuggestion::create(['conversation_id' => $pending->id, 'type' => 'reply', 'content' => 'Hi']);
    markerTRun($pending, $t5, ['ai_suggestion_id' => $waiting->id]);

    $markers = markerTList();

    expect($markers[$answered->id])->toBeNull()
        ->and($markers[$acknowledged->id]['state'])->toBe('draft_ready')
        ->and($markers[$noted->id]['state'])->toBe('draft_ready')
        ->and($markers[$dismissed->id])->toBeNull()
        ->and($markers[$pending->id]['state'])->toBe('draft_ready');
});

test('the markers for a page of conversations come from one query', function () {
    foreach (range(1, 6) as $i) {
        [$conversation, $thread] = markerTConversation();
        markerTRun($conversation, $thread);
    }

    DB::enableQueryLog();
    $markers = markerTList();
    $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_contains($sql, 'agent_runs'));
    DB::disableQueryLog();

    expect(array_filter($markers))->toHaveCount(6)
        ->and($queries)->toHaveCount(1);
});

test('closed conversations carry no marker', function () {
    [$conversation, $thread] = markerTConversation(['status' => 'closed']);
    markerTRun($conversation, $thread);

    $this->actingAs($this->user)->get('/conversations?status=closed')->assertOk()->assertInertia(fn ($page) => $page
        ->where('conversations.data.0.id', $conversation->id)
        ->where('conversations.data.0.agent_marker', null)
        ->etc());
});
