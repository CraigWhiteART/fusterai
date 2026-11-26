<?php

use App\Domains\Conversation\Models\AiSuggestion;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Customer\Models\Customer;
use App\Domains\Mailbox\Models\Mailbox;
use App\Models\ConversationRead;
use App\Models\Workspace;

// Both conversation screens render the same ConversationView from the same
// payload (ConversationDetailPresenter): `selected` on the split inbox,
// `detail` on the deep-link page.

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    $this->user = agentUser($this->workspace);
    $this->mailbox = Mailbox::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->customer = Customer::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->conversation = Conversation::factory()->create([
        'workspace_id' => $this->workspace->id,
        'mailbox_id' => $this->mailbox->id,
        'customer_id' => $this->customer->id,
        'status' => 'open',
        'last_reply_at' => now()->subMinute(),
        'handover_reason' => 'Refund over limit',
    ]);
    Thread::factory()->create([
        'conversation_id' => $this->conversation->id,
        'customer_id' => $this->customer->id,
        'type' => 'message',
        'body' => '<p>Where is my order?</p>',
        'created_at' => now()->subMinutes(5),
    ]);
});

function draftFor(Conversation $conversation, array $attributes = []): AiSuggestion
{
    return AiSuggestion::create([
        'conversation_id' => $conversation->id,
        'type' => 'reply',
        'content' => "Hi there,\n\nIt ships today.",
        'model' => 'test',
        ...$attributes,
    ]);
}

test('split inbox and deep-link page receive the same detail shape', function () {
    $draft = draftFor($this->conversation);

    $assertDetail = fn (string $prefix) => function ($page) use ($prefix, $draft) {
        $page->where("{$prefix}.conversation.id", $this->conversation->id)
            ->where("{$prefix}.conversation.handover_reason", 'Refund over limit')
            ->has("{$prefix}.conversation.threads", 1)
            ->has("{$prefix}.conversation.customer")
            ->where("{$prefix}.isFollowing", false)
            ->where("{$prefix}.folderIds", [])
            ->where("{$prefix}.aiSuggestion.id", $draft->id)
            ->where("{$prefix}.aiSuggestion.content", $draft->content)
            ->has("{$prefix}.extra")
            ->etc();
    };

    $this->actingAs($this->user)
        ->get("/conversations?conversation={$this->conversation->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Conversations/Index')
            ->where('selected.conversation.id', $this->conversation->id)
            ->tap($assertDetail('selected')));

    $this->actingAs($this->user)
        ->get("/conversations/{$this->conversation->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Conversations/Show')
            ->tap($assertDetail('detail')));
});

test('accepted or answered drafts are not offered again', function () {
    draftFor($this->conversation, ['accepted' => true]);

    $this->actingAs($this->user)
        ->get("/conversations/{$this->conversation->id}")
        ->assertInertia(fn ($page) => $page->where('detail.aiSuggestion', null)->etc());

    $old = draftFor($this->conversation);
    $old->forceFill(['created_at' => now()->subMinutes(3)])->save();
    Thread::factory()->create([
        'conversation_id' => $this->conversation->id,
        'user_id' => $this->user->id,
        'type' => 'message',
        'body' => '<p>Sent by hand</p>',
        'created_at' => now()->subMinute(),
    ]);

    $this->actingAs($this->user)
        ->get("/conversations/{$this->conversation->id}")
        ->assertInertia(fn ($page) => $page->where('detail.aiSuggestion', null)->etc());
});

test('opening a conversation in the split inbox marks it read', function () {
    $this->actingAs($this->user)
        ->get("/conversations?conversation={$this->conversation->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('conversations.data.0.is_unread', false)->etc());

    expect(ConversationRead::where('user_id', $this->user->id)
        ->where('conversation_id', $this->conversation->id)
        ->exists())->toBeTrue();
});

test('split inbox does not open a conversation the agent cannot view', function () {
    $other = Mailbox::factory()->create(['workspace_id' => $this->workspace->id]);
    $allowed = Mailbox::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->user->mailboxes()->attach($allowed->id);
    $this->conversation->update(['mailbox_id' => $other->id]);

    $this->actingAs($this->user)
        ->get("/conversations?conversation={$this->conversation->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('selected', null)->etc());
});
