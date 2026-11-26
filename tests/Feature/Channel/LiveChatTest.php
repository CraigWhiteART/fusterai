<?php

use App\Domains\Channel\Jobs\HandleLiveChatMessageJob;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Customer\Models\Customer;
use App\Models\Workspace;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Event::fake();
    $this->workspace = Workspace::factory()->create();
});

test('live chat message endpoint requires workspace_id, visitor_id and message', function () {
    $this->postJson('/api/livechat/message', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['workspace_id', 'visitor_id', 'message']);
});

test('valid live chat message dispatches HandleLiveChatMessageJob', function () {
    $this->postJson('/api/livechat/message', [
        'workspace_id' => $this->workspace->id,
        'visitor_id' => 'visitor-abc-123',
        'visitor_name' => 'Jane',
        'message' => 'Hello, I need help',
    ])->assertOk()->assertJson(['status' => 'sent']);

    Queue::assertPushed(HandleLiveChatMessageJob::class);
});

test('visitor email is stored on the customer record', function () {
    Queue::fake([]);

    $this->postJson('/api/livechat/message', [
        'workspace_id' => $this->workspace->id,
        'visitor_id' => 'visitor-email-test',
        'visitor_name' => 'Jane',
        'visitor_email' => 'jane@example.com',
        'message' => 'Hi there',
    ])->assertOk();

    expect(Customer::where('email', 'jane@example.com')->exists())->toBeTrue();
});

test('visitor without email falls back to virtual email', function () {
    Queue::fake([]);

    $this->postJson('/api/livechat/message', [
        'workspace_id' => $this->workspace->id,
        'visitor_id' => 'anon-visitor-99',
        'message' => 'Hi there',
    ])->assertOk();

    expect(Customer::where('email', 'visitor_anon-visitor-99@livechat.local')->exists())->toBeTrue();
});

test('live chat agent console requires auth', function () {
    $this->get('/live-chat')->assertRedirect('/login');
});

test('authenticated agent can view live chat console', function () {
    $user = agentUser($this->workspace);

    $this->actingAs($user)
        ->get('/live-chat')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('LiveChat/Index')->has('conversations'));
});

test('HandleLiveChatMessageJob creates a thread on the conversation', function () {
    Queue::fake([]);

    $customer = Customer::factory()->create(['workspace_id' => $this->workspace->id]);
    $conversation = Conversation::factory()->create([
        'workspace_id' => $this->workspace->id,
        'customer_id' => $customer->id,
        'channel_type' => 'chat',
    ]);

    $job = new HandleLiveChatMessageJob($conversation, $customer, 'My order is late');
    $job->handle();

    expect(Thread::where('conversation_id', $conversation->id)->exists())->toBeTrue();
});

test('messages endpoint returns threads for a visitor conversation', function () {
    Queue::fake([]);

    $customer = Customer::factory()->create([
        'workspace_id' => $this->workspace->id,
        'email' => 'visitor_msg-test-visitor@livechat.local',
    ]);
    $conversation = Conversation::factory()->create([
        'workspace_id' => $this->workspace->id,
        'customer_id' => $customer->id,
        'channel_type' => 'chat',
    ]);
    $conversation->threads()->create([
        'customer_id' => $customer->id,
        'type' => 'message',
        'body' => 'Hello agent',
        'source' => 'chat',
    ]);

    $this->getJson('/api/livechat/messages?'.http_build_query([
        'workspace_id' => $this->workspace->id,
        'visitor_id' => 'msg-test-visitor',
        'conversation_id' => $conversation->id,
    ]))
        ->assertOk()
        ->assertJsonCount(1, 'threads');
});

test('messages endpoint returns empty threads for unknown visitor', function () {
    $this->getJson('/api/livechat/messages?'.http_build_query([
        'workspace_id' => $this->workspace->id,
        'visitor_id' => 'unknown-xyz',
        'conversation_id' => 999,
    ]))
        ->assertOk()
        ->assertJson(['threads' => []]);
});

test('subsequent messages from same visitor append threads to the same conversation', function () {
    Queue::fake([]);

    $customer = Customer::factory()->create(['workspace_id' => $this->workspace->id]);
    $conversation = Conversation::factory()->create([
        'workspace_id' => $this->workspace->id,
        'customer_id' => $customer->id,
        'channel_type' => 'chat',
    ]);

    (new HandleLiveChatMessageJob($conversation, $customer, 'First message'))->handle();
    (new HandleLiveChatMessageJob($conversation, $customer, 'Second message'))->handle();

    expect(Conversation::where('workspace_id', $this->workspace->id)->count())->toBe(1);
    expect(Thread::where('conversation_id', $conversation->id)->count())->toBe(2);
});

// ── chat token: the public widget channel must be unguessable ─────────────────

test('starting a chat returns a random chat token bound to that conversation', function () {
    Queue::fake([]);

    $first = $this->postJson('/api/livechat/message', [
        'workspace_id' => $this->workspace->id,
        'visitor_id' => 'visitor-token-a',
        'message' => 'Hello',
    ])->assertOk()->json();

    $second = $this->postJson('/api/livechat/message', [
        'workspace_id' => $this->workspace->id,
        'visitor_id' => 'visitor-token-b',
        'message' => 'Hello',
    ])->assertOk()->json();

    expect($first['chat_token'])->toBeString()->toHaveLength(48)
        ->and($second['chat_token'])->not->toBe($first['chat_token']);

    $conversation = Conversation::findOrFail($first['conversation_id']);
    expect($conversation->chat_token)->toBe($first['chat_token'])
        ->and($conversation->chatChannelName())->toBe('livechat.'.$first['chat_token'])
        // never serialised with the model (Inertia props, JSON)
        ->and($conversation->toArray())->not->toHaveKey('chat_token');

    // The same visitor keeps the same conversation and token.
    $again = $this->postJson('/api/livechat/message', [
        'workspace_id' => $this->workspace->id,
        'visitor_id' => 'visitor-token-a',
        'message' => 'Still there?',
    ])->assertOk()->json();
    expect($again['conversation_id'])->toBe($first['conversation_id'])
        ->and($again['chat_token'])->toBe($first['chat_token']);
});

test('posting with another visitor email does not hand over their chat', function () {
    Queue::fake([]);

    $victim = $this->postJson('/api/livechat/message', [
        'workspace_id' => $this->workspace->id,
        'visitor_id' => 'victim-visitor',
        'visitor_email' => 'victim@example.com',
        'message' => 'My address is ...',
    ])->assertOk()->json();

    $attacker = $this->postJson('/api/livechat/message', [
        'workspace_id' => $this->workspace->id,
        'visitor_id' => 'attacker-visitor',
        'visitor_email' => 'victim@example.com',
        'message' => 'hi',
    ])->assertOk()->json();

    expect($attacker['conversation_id'])->not->toBe($victim['conversation_id'])
        ->and($attacker['chat_token'])->not->toBe($victim['chat_token']);
});

test('history needs the matching chat token (or the visitor id) and returns the token', function () {
    Queue::fake([]);

    $start = $this->postJson('/api/livechat/message', [
        'workspace_id' => $this->workspace->id,
        'visitor_id' => 'history-visitor',
        'visitor_email' => 'history@example.com',
        'message' => 'Hello',
    ])->assertOk()->json();
    Conversation::findOrFail($start['conversation_id'])->threads()->create([
        'type' => 'message', 'body' => 'Hello', 'source' => 'chat',
    ]);

    $query = fn (array $extra) => '/api/livechat/messages?'.http_build_query([
        'workspace_id' => $this->workspace->id, 'conversation_id' => $start['conversation_id'], ...$extra,
    ]);

    $this->getJson($query(['chat_token' => $start['chat_token']]))
        ->assertOk()->assertJsonCount(1, 'threads')->assertJsonPath('chat_token', $start['chat_token']);

    // Older widget session: proves itself with the visitor id it started with.
    $this->getJson($query(['visitor_id' => 'history-visitor']))
        ->assertOk()->assertJsonCount(1, 'threads')->assertJsonPath('chat_token', $start['chat_token']);

    // Wrong token (even with the right visitor id), or another visitor id: nothing, no token.
    $this->getJson($query(['chat_token' => str_repeat('z', 48), 'visitor_id' => 'history-visitor']))
        ->assertOk()->assertExactJson(['threads' => []]);
    $this->getJson($query(['visitor_id' => 'someone-else']))
        ->assertOk()->assertExactJson(['threads' => []]);
});
