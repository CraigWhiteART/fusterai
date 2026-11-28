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
 * Working through one kind of email at a time: the inbox filters by the
 * agent's category (with a count per category) and by "draft ready".
 */

beforeEach(function () {
    Queue::fake();
    Event::fake();

    $this->workspace = Workspace::factory()->create();
    $this->user = agentUser($this->workspace);
    $this->mailbox = Mailbox::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->customer = Customer::factory()->create(['workspace_id' => $this->workspace->id]);
});

function catTConversation(?string $category, array $attributes = []): Conversation
{
    $t = test();
    $conversation = Conversation::factory()->create(array_merge([
        'workspace_id' => $t->workspace->id,
        'mailbox_id' => $t->mailbox->id,
        'customer_id' => $t->customer->id,
        'status' => 'open',
        'last_reply_at' => now()->subMinutes(10),
    ], $attributes));
    $conversation->forceFill(['agent_category' => $category])->saveQuietly();

    return $conversation;
}

/** A customer message ten minutes ago and a finished live run for it (a draft, unless overridden). */
function catTRun(Conversation $conversation, array $attributes = []): AgentRun
{
    $thread = Thread::factory()->create([
        'conversation_id' => $conversation->id,
        'customer_id' => $conversation->customer_id,
        'user_id' => null,
        'type' => 'message',
        'created_at' => now()->subMinutes(10),
    ]);

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

/** @return array{rows: array<int, array<string, mixed>>, counts: array<string, int>, props: array<string, mixed>} */
function catTList(string $query = ''): array
{
    $t = test();
    $props = $t->actingAs($t->user)->get('/conversations'.($query !== '' ? '?'.$query : ''))->assertOk()->viewData('page')['props'];

    $rows = [];
    foreach ($props['conversations']['data'] as $row) {
        $rows[$row['id']] = $row;
    }

    return ['rows' => $rows, 'counts' => array_column($props['categories'], 'count', 'key'), 'props' => $props];
}

test('the inbox lists every category with its count for the current status tab', function () {
    catTConversation('orders');
    catTConversation('orders');
    catTConversation('technical');
    catTConversation(null);
    catTConversation('orders', ['status' => 'closed']);
    // Another workspace's conversations never count.
    Conversation::factory()->create(['status' => 'open'])->forceFill(['agent_category' => 'orders'])->saveQuietly();

    $open = catTList();

    expect($open['props']['categories'])->toBe([
        ['key' => 'orders', 'label' => 'Orders & shipping', 'count' => 2],
        ['key' => 'returns', 'label' => 'Returns & refunds', 'count' => 0],
        ['key' => 'technical', 'label' => 'Technical', 'count' => 1],
        ['key' => 'product', 'label' => 'Product questions', 'count' => 0],
        ['key' => 'business', 'label' => 'Wholesale & partners', 'count' => 0],
        ['key' => 'other', 'label' => 'Other', 'count' => 1],
    ])->and($open['rows'])->toHaveCount(4);

    expect(catTList('status=closed')['counts'])->toMatchArray(['orders' => 1, 'technical' => 0, 'other' => 0]);
});

test('choosing a category filters the list on the server and leaves the counts alone', function () {
    $first = catTConversation('orders');
    $second = catTConversation('orders');
    $technical = catTConversation('technical');
    $uncategorised = catTConversation(null);

    $orders = catTList('category=orders');
    expect(array_keys($orders['rows']))->toEqualCanonicalizing([$first->id, $second->id])
        ->and($orders['rows'][$first->id]['agent_category'])->toBe('orders')
        ->and($orders['counts'])->toMatchArray(['orders' => 2, 'technical' => 1, 'other' => 1])
        ->and($orders['props']['filters']['category'])->toBe('orders');

    expect(array_keys(catTList('category=technical')['rows']))->toBe([$technical->id])
        // Conversations the agent has not looked at are under "Other".
        ->and(array_keys(catTList('category=other')['rows']))->toBe([$uncategorised->id])
        ->and(catTList('category=returns')['rows'])->toBe([]);
});

test('the category combines with the other filters, and the counts follow them', function () {
    $otherMailbox = Mailbox::factory()->create(['workspace_id' => $this->workspace->id]);
    $mine = catTConversation('orders', ['assigned_user_id' => $this->user->id]);
    catTConversation('orders');
    catTConversation('technical', ['assigned_user_id' => $this->user->id]);
    $elsewhere = catTConversation('orders', ['mailbox_id' => $otherMailbox->id]);
    $pending = catTConversation('orders', ['status' => 'pending']);

    $assigned = catTList('category=orders&assigned=me');
    expect(array_keys($assigned['rows']))->toBe([$mine->id])
        ->and($assigned['counts'])->toMatchArray(['orders' => 1, 'technical' => 1]);

    $mailbox = catTList('category=orders&mailbox='.$otherMailbox->id);
    expect(array_keys($mailbox['rows']))->toBe([$elsewhere->id])
        ->and($mailbox['counts'])->toMatchArray(['orders' => 1, 'technical' => 0]);

    // The category survives a change of status tab.
    $tab = catTList('category=orders&status=pending');
    expect(array_keys($tab['rows']))->toBe([$pending->id])
        ->and($tab['props']['filters'])->toMatchArray(['category' => 'orders', 'status' => 'pending']);
});

test('the category and draft-ready filters survive pagination', function () {
    foreach (range(1, 31) as $i) {
        catTRun(catTConversation('orders'));
    }
    catTConversation('technical');

    $page = catTList('category=orders&draft_ready=1');

    expect($page['rows'])->toHaveCount(30)
        ->and($page['props']['conversations']['total'])->toBe(31)
        ->and($page['props']['conversations']['next_page_url'])->toContain('category=orders')->toContain('draft_ready=1')
        ->and(catTList('category=orders&draft_ready=1&page=2')['rows'])->toHaveCount(1);
});

test('"draft ready" keeps exactly the conversations whose badge says draft ready', function () {
    $ready = catTConversation('orders');
    catTRun($ready);
    $readyTechnical = catTConversation('technical');
    $waiting = AiSuggestion::create(['conversation_id' => $readyTechnical->id, 'type' => 'reply', 'content' => 'Hi']);
    catTRun($readyTechnical, ['ai_suggestion_id' => $waiting->id]);

    // Not draft ready, each for a different reason.
    $handedOver = catTConversation('orders');
    catTRun($handedOver, ['decision' => 'handover', 'draft' => null, 'handover_reason' => 'no_instruction']);
    $needsHuman = catTConversation('orders');
    catTRun($needsHuman, ['auto_send' => ['blockers' => ['playbook_mode', 'needs_human']]]);
    $answered = catTConversation('orders');
    catTRun($answered);
    Thread::factory()->create(['conversation_id' => $answered->id, 'user_id' => $this->user->id, 'customer_id' => null, 'type' => 'message', 'created_at' => now()->subMinutes(3)]);
    $dismissed = catTConversation('orders');
    $suggestion = AiSuggestion::create(['conversation_id' => $dismissed->id, 'type' => 'reply', 'content' => 'Hi', 'accepted' => false]);
    catTRun($dismissed, ['ai_suggestion_id' => $suggestion->id]);
    $autoSent = catTConversation('orders');
    catTRun($autoSent, ['decision' => 'auto_send']);
    $shadow = catTConversation('orders');
    catTRun($shadow, ['purpose' => AgentRun::PURPOSE_SHADOW]);
    $newMessage = catTConversation('orders');
    catTRun($newMessage);
    Thread::factory()->create(['conversation_id' => $newMessage->id, 'customer_id' => $newMessage->customer_id, 'user_id' => null, 'type' => 'message', 'created_at' => now()->subMinutes(2)]);
    // Handed over first, then drafted for the same message: the newer run decides.
    $redrafted = catTConversation('orders');
    $first = catTRun($redrafted, ['decision' => 'handover', 'draft' => null, 'handover_reason' => 'no_instruction']);
    AgentRun::create([...$first->only(['workspace_id', 'purpose', 'conversation_id', 'customer_thread_id', 'status', 'config_hash']), 'config' => [], 'idempotency_key' => (string) Str::uuid(), 'decision' => 'draft', 'draft' => 'Hi', 'completed_at' => now()->subMinutes(8)]);
    catTConversation('orders');

    $all = catTList();
    $badged = array_keys(array_filter($all['rows'], fn ($row) => ($row['agent_marker']['state'] ?? null) === 'draft_ready'));
    $filtered = catTList('draft_ready=1');

    expect($badged)->toEqualCanonicalizing([$ready->id, $readyTechnical->id, $redrafted->id])
        ->and(array_keys($filtered['rows']))->toEqualCanonicalizing($badged)
        ->and(array_column($filtered['rows'], 'agent_marker'))->each->toBe(['state' => 'draft_ready', 'label' => null])
        // The chip counts follow the toggle.
        ->and($filtered['counts'])->toMatchArray(['orders' => 2, 'technical' => 1, 'other' => 0])
        ->and($filtered['props']['filters']['draft_ready'])->toBe('1');

    expect(array_keys(catTList('draft_ready=1&category=technical')['rows']))->toBe([$readyTechnical->id]);
});

test('a closed conversation is never "draft ready"', function () {
    catTRun(catTConversation('orders', ['status' => 'closed']));

    expect(catTList('status=closed&draft_ready=1')['rows'])->toBe([]);
});

test('an unknown category is rejected', function () {
    $this->actingAs($this->user)->get('/conversations?category=nonsense')->assertSessionHasErrors('category');
});

test('the filtered list runs the same number of queries for three conversations as for twelve', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        catTList('category=orders&draft_ready=1');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    foreach (range(1, 3) as $i) {
        catTRun(catTConversation('orders'));
    }
    $count(); // warm any per-process caches first
    $few = $count();

    foreach (range(1, 9) as $i) {
        catTRun(catTConversation('orders'));
    }
    $many = $count();

    expect($many)->toBe($few);

    // The counts for all categories come from one grouped query.
    DB::flushQueryLog();
    DB::enableQueryLog();
    catTList();
    $grouped = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_contains($sql, 'group by') && str_contains($sql, 'agent_category'));
    DB::disableQueryLog();

    expect($grouped)->toHaveCount(1);
});
