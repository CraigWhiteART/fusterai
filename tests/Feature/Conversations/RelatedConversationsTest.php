<?php

use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Conversation\Support\RelatedConversations;
use App\Domains\Customer\Models\Customer;
use App\Domains\Mailbox\Models\Mailbox;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
 * Customers write several times about the same thing, and every Gmail thread
 * is its own conversation. RelatedConversations finds the same customer's
 * other conversations for the conversation view, the inbox badge and the
 * agent.
 */

beforeEach(function () {
    Queue::fake();
    Event::fake();

    $this->workspace = Workspace::factory()->create();
    $this->user = agentUser($this->workspace);
    $this->mailbox = Mailbox::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->customer = Customer::factory()->create(['workspace_id' => $this->workspace->id, 'email' => 'buyer@example.com']);
});

function relatedTConversation(array $attributes = [], ?Customer $customer = null): Conversation
{
    $t = test();

    return Conversation::factory()->create(array_merge([
        'workspace_id' => $t->workspace->id,
        'mailbox_id' => $t->mailbox->id,
        'customer_id' => ($customer ?? $t->customer)->id,
        'status' => 'open',
        'last_reply_at' => now()->subHour(),
    ], $attributes));
}

function relatedTCustomerMessage(Conversation $conversation, string $text, $at = null): Thread
{
    return Thread::factory()->create([
        'conversation_id' => $conversation->id,
        'customer_id' => $conversation->customer_id,
        'user_id' => null,
        'type' => 'message',
        'body' => '<p>'.e($text).'</p>',
        'body_plain' => $text,
        'created_at' => $at ?? now()->subHour(),
    ]);
}

function relatedTStaffThread(Conversation $conversation, string $text, $at = null, array $attributes = []): Thread
{
    return Thread::factory()->create(array_merge([
        'conversation_id' => $conversation->id,
        'customer_id' => null,
        'user_id' => test()->user->id,
        'type' => 'message',
        'body' => '<p>'.e($text).'</p>',
        'body_plain' => $text,
        'created_at' => $at ?? now()->subMinutes(30),
    ], $attributes));
}

/** @return array<int, array<string, mixed>> keyed by conversation id */
function relatedTFor(Conversation $conversation): array
{
    return collect(app(RelatedConversations::class)->for($conversation->fresh()))->keyBy('id')->all();
}

test('the same customer\'s other conversations are returned, open ones first, and never the conversation itself', function () {
    $current = relatedTConversation(['subject' => 'Where is my order?']);
    $closed = relatedTConversation(['subject' => 'Thanks', 'status' => 'closed', 'last_reply_at' => now()->subMinutes(5)]);
    $pending = relatedTConversation(['subject' => 'Order question', 'status' => 'pending', 'last_reply_at' => now()->subDays(3)]);
    $open = relatedTConversation(['subject' => 'New customer message', 'last_reply_at' => now()->subHours(2)]);
    relatedTCustomerMessage($open, 'Hello,   has my parcel shipped?');

    $related = app(RelatedConversations::class)->for($current);

    expect(array_column($related, 'id'))->toBe([$open->id, $pending->id, $closed->id])
        ->and($related[0])->toMatchArray([
            'subject' => 'New customer message',
            'status' => 'open',
            'channel' => 'email',
            'last_customer_excerpt' => 'Hello, has my parcel shipped?',
            'last_staff_at' => null,
            'last_staff_excerpt' => null,
            'last_speaker' => 'customer',
        ])
        ->and($related[0]['last_customer_at'])->not->toBeNull();
});

test('another customer\'s conversations are not related, nor the same email in another workspace', function () {
    $current = relatedTConversation();
    $stranger = Customer::factory()->create(['workspace_id' => $this->workspace->id, 'email' => 'someone.else@example.com']);
    relatedTConversation([], $stranger);

    $elsewhere = Workspace::factory()->create();
    Conversation::factory()->create([
        'workspace_id' => $elsewhere->id,
        'mailbox_id' => Mailbox::factory()->create(['workspace_id' => $elsewhere->id])->id,
        'customer_id' => Customer::factory()->create(['workspace_id' => $elsewhere->id, 'email' => 'buyer@example.com'])->id,
        'status' => 'open',
    ]);

    expect(relatedTFor($current))->toBe([]);
});

test('the same person stored as two customer rows is matched by normalised email', function () {
    $current = relatedTConversation();
    $sameAddress = Customer::factory()->create(['workspace_id' => $this->workspace->id, 'email' => ' Buyer@Example.COM']);
    $other = relatedTConversation(['subject' => 'From the chat widget'], $sameAddress);

    expect(array_keys(relatedTFor($current)))->toBe([$other->id])
        ->and(array_keys(relatedTFor($other)))->toBe([$current->id]);
});

test('spam, deleted conversations and closed ones older than 30 days are left out', function () {
    $current = relatedTConversation();
    relatedTConversation(['status' => 'spam']);
    relatedTConversation()->delete();
    relatedTConversation(['status' => 'closed', 'last_reply_at' => now()->subDays(31)]);
    relatedTConversation(['status' => 'closed', 'imported' => true, 'last_reply_at' => now()->subDays(200)]);
    $recent = relatedTConversation(['status' => 'closed', 'last_reply_at' => now()->subDays(29)]);
    $oldButOpen = relatedTConversation(['status' => 'open', 'last_reply_at' => now()->subDays(90)]);

    expect(array_keys(relatedTFor($current)))->toBe([$oldButOpen->id, $recent->id]);
});

test('the list is capped', function () {
    $current = relatedTConversation();
    foreach (range(1, RelatedConversations::LIMIT + 3) as $i) {
        relatedTConversation(['last_reply_at' => now()->subMinutes($i)]);
    }

    expect(relatedTFor($current))->toHaveCount(RelatedConversations::LIMIT);
});

test('waiting on us means open and the customer wrote last', function () {
    $current = relatedTConversation();

    $unanswered = relatedTConversation();
    relatedTCustomerMessage($unanswered, 'Any news on my order?');

    $answered = relatedTConversation();
    relatedTCustomerMessage($answered, 'Where is it?', now()->subHours(3));
    relatedTStaffThread($answered, 'It shipped on Monday.', now()->subHours(2));

    $wroteAgain = relatedTConversation();
    relatedTCustomerMessage($wroteAgain, 'Where is it?', now()->subHours(3));
    relatedTStaffThread($wroteAgain, 'It shipped on Monday.', now()->subHours(2));
    relatedTCustomerMessage($wroteAgain, 'It still has not arrived.', now()->subHour());

    $closedAfterThanks = relatedTConversation(['status' => 'closed']);
    relatedTStaffThread($closedAfterThanks, 'It shipped on Monday.', now()->subHours(2));
    relatedTCustomerMessage($closedAfterThanks, 'Thanks!', now()->subHour());

    $related = relatedTFor($current);

    expect($related[$unanswered->id]['waiting_on_us'])->toBeTrue()
        ->and($related[$answered->id]['waiting_on_us'])->toBeFalse()
        ->and($related[$answered->id]['last_speaker'])->toBe('staff')
        ->and($related[$answered->id]['last_staff_excerpt'])->toBe('It shipped on Monday.')
        ->and($related[$wroteAgain->id]['waiting_on_us'])->toBeTrue()
        ->and($related[$wroteAgain->id]['last_customer_excerpt'])->toBe('It still has not arrived.')
        ->and($related[$closedAfterThanks->id]['waiting_on_us'])->toBeFalse()
        ->and($related[$closedAfterThanks->id]['last_speaker'])->toBe('customer');
});

test('only replies that were really sent count as a staff reply: no notes, AI drafts or scheduled replies', function () {
    $current = relatedTConversation();
    $other = relatedTConversation();
    relatedTCustomerMessage($other, 'Can I change my address?', now()->subHours(3));
    relatedTStaffThread($other, 'Internal: check with the warehouse.', now()->subHours(2), ['type' => 'note']);
    relatedTStaffThread($other, 'AI draft text', now()->subHours(2), ['type' => 'ai_suggestion', 'user_id' => null]);
    relatedTStaffThread($other, 'Scheduled reply, not sent yet.', now()->subHour(), ['send_at' => now()->addHour()]);

    $related = relatedTFor($current)[$other->id];

    expect($related['last_staff_at'])->toBeNull()
        ->and($related['last_staff_excerpt'])->toBeNull()
        ->and($related['waiting_on_us'])->toBeTrue();
});

test('excerpts are short plain text', function () {
    $current = relatedTConversation();
    $other = relatedTConversation();
    relatedTCustomerMessage($other, str_repeat('long message ', 100));

    $excerpt = relatedTFor($current)[$other->id]['last_customer_excerpt'];

    expect(mb_strlen($excerpt))->toBeLessThanOrEqual(161)->and($excerpt)->toEndWith('…');
});

test('a user restricted to some mailboxes only sees related conversations in those mailboxes', function () {
    $current = relatedTConversation();
    $elsewhere = Mailbox::factory()->create(['workspace_id' => $this->workspace->id]);
    relatedTConversation(['mailbox_id' => $elsewhere->id]);
    $visible = relatedTConversation();

    $restricted = User::factory()->create(['workspace_id' => $this->workspace->id, 'role' => 'agent']);
    $restricted->mailboxes()->attach($this->mailbox->id);

    $ids = fn (User $user) => array_column(app(RelatedConversations::class)->for($current, mailboxIds: RelatedConversations::visibleMailboxIds($user)), 'id');

    expect($ids($restricted))->toBe([$visible->id])
        ->and($ids($this->user))->toHaveCount(2);
});

// ── Conversation view payload ────────────────────────────────────────────────

test('the conversation payload carries the related conversations', function () {
    $current = relatedTConversation();
    $other = relatedTConversation(['subject' => 'Same question again']);
    relatedTCustomerMessage($other, 'Hello?');

    $this->actingAs($this->user)->get("/conversations/{$current->id}")->assertOk()->assertInertia(fn ($page) => $page
        ->where('detail.related.0.id', $other->id)
        ->where('detail.related.0.subject', 'Same question again')
        ->where('detail.related.0.waiting_on_us', true)
        ->etc());

    $this->actingAs($this->user)->get("/conversations/{$other->id}")->assertOk()->assertInertia(fn ($page) => $page
        ->has('detail.related', 1)
        ->where('detail.related.0.id', $current->id)
        ->etc());
});

// ── Inbox badge ──────────────────────────────────────────────────────────────

/** @return array<int, int> conversation id => related_open_count */
function relatedTInboxCounts(): array
{
    $t = test();
    $counts = [];
    $t->actingAs($t->user)->get('/conversations')->assertOk()->assertInertia(function ($page) use (&$counts) {
        $page->where('conversations.data', function ($rows) use (&$counts) {
            foreach ($rows as $row) {
                $counts[$row['id']] = $row['related_open_count'];
            }

            return true;
        })->etc();
    });

    return $counts;
}

test('an inbox row counts the customer\'s other open conversations', function () {
    $first = relatedTConversation();
    $second = relatedTConversation();
    $third = relatedTConversation(['status' => 'pending']);
    relatedTConversation(['status' => 'closed']);
    relatedTConversation(['status' => 'spam']);

    $sameAddress = Customer::factory()->create(['workspace_id' => $this->workspace->id, 'email' => 'BUYER@example.com']);
    $otherRow = relatedTConversation([], $sameAddress);

    $stranger = Customer::factory()->create(['workspace_id' => $this->workspace->id, 'email' => 'someone.else@example.com']);
    $alone = relatedTConversation([], $stranger);

    $counts = relatedTInboxCounts();

    // The list shows open conversations; the pending one is counted but not listed.
    expect($counts)->toHaveKeys([$first->id, $second->id, $otherRow->id, $alone->id])
        ->and($counts[$first->id])->toBe(3)
        ->and($counts[$second->id])->toBe(3)
        ->and($counts[$otherRow->id])->toBe(3)
        ->and($counts[$alone->id])->toBe(0)
        ->and(array_key_exists($third->id, $counts))->toBeFalse();
});

test('the badge counts for a page of conversations come from one query', function () {
    foreach (range(1, 4) as $i) {
        $customer = Customer::factory()->create(['workspace_id' => $this->workspace->id]);
        relatedTConversation([], $customer);
        relatedTConversation([], $customer);
    }
    $conversations = Conversation::with('customer')->where('workspace_id', $this->workspace->id)->get();

    DB::enableQueryLog();
    $counts = app(RelatedConversations::class)->openCounts($conversations);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1)
        ->and($counts)->toHaveCount(8)
        ->and(array_unique(array_values($counts)))->toBe([1]);
});
