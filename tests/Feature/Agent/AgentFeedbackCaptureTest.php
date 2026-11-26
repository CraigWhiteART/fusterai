<?php

require_once __DIR__.'/AgentTestHelpers.php';

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\FeedbackEvent;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Conversation\Support\InboundMessageEffects;
use App\Enums\ThreadType;
use App\Models\User;
use App\Services\ThreadService;
use App\Support\Hooks;
use Illuminate\Support\Facades\Queue;

function agentTDraftRun(array $case, string $draft): AgentRun
{
    return AgentRun::create([
        'workspace_id' => $case['workspace']->id,
        'purpose' => 'live',
        'conversation_id' => $case['conversation']->id,
        'customer_thread_id' => $case['thread']->id,
        'idempotency_key' => 'live:test:'.uniqid(),
        'status' => 'completed',
        'config' => ['models' => ['writer' => 'claude-opus-5-5']],
        'config_hash' => 'h',
        'decision' => 'draft',
        'draft' => $draft,
        'created_at' => now()->subMinute(),
    ]);
}

test('a reply sent from fusterai records sent_as_is or sent_edited against the pending draft', function () {
    Queue::fake();
    $case = agentTCase('Where is my order?');
    $user = User::factory()->create(['workspace_id' => $case['workspace']->id, 'role' => 'agent']);
    $draft = 'Hi Alex, could you send me your order number so I can check?';
    $run = agentTDraftRun($case, $draft);

    app(ThreadService::class)->store($case['conversation'], $draft, ThreadType::Message, $user);

    $event = FeedbackEvent::where('run_id', $run->id)->sole();
    expect($event->kind)->toBe('sent_as_is')
        ->and($event->percent_changed)->toBe(0)
        ->and($event->user_id)->toBe($user->id);

    // A later reply on the same draft does not record again.
    app(ThreadService::class)->store($case['conversation'], 'Another message', ThreadType::Message, $user);
    expect(FeedbackEvent::where('run_id', $run->id)->count())->toBe(1);

    $case2 = agentTCase('Where is my order?');
    $run2 = agentTDraftRun($case2, $draft);
    app(ThreadService::class)->store($case2['conversation'], 'Hi Alex, could you send your order number so I can check today?', ThreadType::Message, $user);
    $edited = FeedbackEvent::where('run_id', $run2->id)->sole();
    expect($edited->kind)->toBe('sent_edited')
        ->and($edited->percent_changed)->toBeGreaterThan(0)->toBeLessThan(70)
        ->and($edited->draft_before)->toBe($draft);
});

test('a reply unrelated to the draft is recorded as rejected without a percent', function () {
    Queue::fake();
    $case = agentTCase('Where is my order?');
    $user = User::factory()->create(['workspace_id' => $case['workspace']->id, 'role' => 'agent']);
    $run = agentTDraftRun($case, 'Hi Alex, could you send me your order number so I can check?');

    app(ThreadService::class)->store($case['conversation'], 'Totally different: we have refunded your purchase in full and closed this ticket. Sorry for the trouble with the courier.', ThreadType::Message, $user);

    $event = FeedbackEvent::where('run_id', $run->id)->sole();
    expect($event->kind)->toBe('rejected')
        ->and($event->percent_changed)->toBeNull()
        ->and($event->meta['derived'])->toBeFalse();
});

test('a staff reply ingested from Gmail records gmail_reply', function () {
    $case = agentTCase('Where is my order?');
    $draft = 'Hi Alex, could you send me your order number so I can check?';
    $run = agentTDraftRun($case, $draft);

    $thread = Thread::create([
        'conversation_id' => $case['conversation']->id, 'customer_id' => null, 'user_id' => null, 'origin' => 'gmail',
        'type' => 'message', 'body' => '<p>'.$draft.'</p>', 'body_plain' => $draft, 'source' => 'email',
    ]);
    app(InboundMessageEffects::class)->afterAgentMessage($case['conversation'], $thread);
    // thread.created firing twice for one thread never double-counts.
    Hooks::doAction('thread.created', $thread);

    $event = FeedbackEvent::where('run_id', $run->id)->sole();
    expect($event->kind)->toBe('gmail_reply')
        ->and($event->percent_changed)->toBe(0)
        ->and($event->thread_id)->toBe($thread->id);
});

test('customer messages, notes, imported threads and conversations without a draft record nothing', function () {
    Queue::fake();
    $case = agentTCase('Where is my order?');
    $user = User::factory()->create(['workspace_id' => $case['workspace']->id, 'role' => 'agent']);

    app(ThreadService::class)->store($case['conversation'], 'No draft here', ThreadType::Message, $user);
    agentTDraftRun($case, 'Draft text');
    app(ThreadService::class)->store($case['conversation'], 'internal', ThreadType::Note, $user);
    $import = Thread::create(['conversation_id' => $case['conversation']->id, 'origin' => 'import', 'type' => 'message', 'body' => 'Draft text', 'body_plain' => 'Draft text', 'source' => 'email']);
    Hooks::doAction('thread.created', $import);

    expect(FeedbackEvent::count())->toBe(0);
});
