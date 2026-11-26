<?php

require_once __DIR__.'/AutoSendTestHelpers.php';

use App\Domains\Agent\Agents\TriageAgent;
use App\Domains\Agent\AutoSend\AutoSendAudit;
use App\Domains\Agent\AutoSend\AutoSendService;
use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Pipeline\AgentPipeline;
use App\Domains\Agent\Pipeline\InstructionSet;
use App\Domains\Agent\Pipeline\PipelineInput;
use App\Domains\Agent\Pipeline\PolicyGate;
use App\Domains\Agent\Simulation\CaseRunner;
use App\Domains\AI\Jobs\GenerateReplySuggestionJob;
use App\Domains\Conversation\Jobs\SendReplyJob;
use App\Domains\Conversation\Models\AiSuggestion;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Conversation\Support\InboundMessageEffects;
use App\Models\User;
use App\Notifications\AgentAutoSendNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Http::preventStrayRequests();
    Bus::fake([SendReplyJob::class, GenerateReplySuggestionJob::class]);
    Notification::fake();
    loopTFakePipeline(["Hi Alex,\n\nThanks for your patience. Your order is being packed now and you will get tracking as soon as it ships.\n\nCheers,"]);
});

test('an eligible reply is scheduled as an AI thread with the undo delay, not mailed', function () {
    $f = autoTWorkspace();
    $this->freezeSecond();

    $run = autoTRun($f['conversation']);
    $thread = autoTPending($f['conversation']);

    expect($run->decision)->toBe('auto_send')
        ->and($run->auto_send['status'])->toBe('scheduled')
        ->and($run->auto_send['thread_id'])->toBe($thread->id)
        ->and($thread->origin)->toBe('ai')
        ->and($thread->user_id)->toBeNull()
        ->and($thread->customer_id)->toBeNull()
        ->and($thread->type->value)->toBe('message')
        ->and($thread->meta['from_name'])->toBe('AI agent')
        ->and($thread->meta['auto_send']['status'])->toBe('scheduled')
        ->and($thread->send_at->equalTo(now()->addSeconds(120)))->toBeTrue()
        ->and($thread->body)->toContain('<p>Hi Alex,</p>')
        ->and(AiSuggestion::count())->toBe(0)
        ->and(autoTSent())->toBe([]);

    Bus::assertDispatched(SendReplyJob::class, fn (SendReplyJob $job) => $job->thread->is($thread) && $job->delay !== null);

    $events = Activity::where('log_name', AutoSendAudit::LOG)->orderBy('id')->get();
    expect($events->pluck('event')->all())->toBe(['auto_send_decision', 'auto_send_scheduled'])
        ->and($events->every(fn ($a) => $a->properties['run_id'] === $run->id))->toBeTrue()
        ->and(collect($run->fresh()->auto_send['events'])->pluck('event')->all())->toBe(['auto_send_decision', 'auto_send_scheduled']);
});

test('at send time it goes out with the mailbox signature and real threading headers, then waits on the customer', function () {
    $f = autoTWorkspace(['auto_send' => ['disclosure_line' => 'This reply was written by our AI assistant.']]);
    User::factory()->create(['workspace_id' => $f['workspace']->id, 'role' => 'agent', 'signature' => 'Sam from support']);
    $run = autoTRun($f['conversation']);
    $thread = autoTPending($f['conversation']);

    autoTExecute($thread);

    $sent = autoTSent();
    expect($sent)->toHaveCount(1);
    $email = $sent[0]->getOriginalMessage();
    $headers = $email->getHeaders();
    $thread->refresh();

    expect($headers->get('In-Reply-To')->getBodyAsString())->toBe('<cust-1@example.com>')
        ->and($headers->get('References')->getBodyAsString())->toContain('<cust-1@example.com>')
        ->and($headers->get('X-FusterAI-Thread')->getBodyAsString())->toBe((string) $thread->id)
        ->and($headers->get('Message-ID')->getBodyAsString())->toBe('<'.$thread->message_id.'>')
        ->and($email->getHtmlBody())->toContain('The Soul Dial team')
        ->and($email->getHtmlBody())->not->toContain('Sam from support')
        ->and($email->getHtmlBody())->toContain('This reply was written by our AI assistant.')
        ->and($thread->meta['auto_send']['status'])->toBe('sent')
        ->and($thread->send_at)->toBeNull()
        ->and($thread->sent_at)->not->toBeNull()
        ->and($f['conversation']->fresh()->status->value)->toBe('pending')
        ->and($run->fresh()->auto_send['status'])->toBe('sent')
        ->and(Activity::where('event', 'auto_send_sent')->where('properties->run_id', $run->id)->exists())->toBeTrue();

    // A retry of the same job never sends twice.
    autoTExecute($thread);
    expect(autoTSent())->toHaveCount(1);
});

test('status after send is configurable and never closed', function () {
    $f = autoTWorkspace(['auto_send' => ['status_after_send' => 'open']]);
    autoTRun($f['conversation']);
    autoTExecute(autoTPending($f['conversation']));

    expect($f['conversation']->fresh()->status->value)->toBe('open');
});

test('a zero delay sends on the next worker pick-up', function () {
    $f = autoTWorkspace(['auto_send_delay_seconds' => 0]);
    $this->freezeSecond();
    autoTRun($f['conversation']);
    $thread = autoTPending($f['conversation']);

    expect($thread->send_at->equalTo(now()))->toBeTrue();
    Bus::assertDispatched(SendReplyJob::class, fn (SendReplyJob $job) => $job->delay === null);

    autoTExecute($thread);
    expect(autoTSent())->toHaveCount(1);
});

test('Cancel during the delay stops the mail and brings the reply back as a draft', function () {
    $f = autoTWorkspace();
    $run = autoTRun($f['conversation']);
    $thread = autoTPending($f['conversation']);
    $agent = agentUser($f['workspace']);

    $this->actingAs($agent)->postJson("/agent/auto-sends/{$thread->id}/cancel")
        ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('edit', false);

    autoTExecute($thread);

    $thread->refresh();
    $suggestion = AiSuggestion::sole();
    expect(autoTSent())->toBe([])
        ->and($thread->type->value)->toBe('note')
        ->and($thread->send_at)->toBeNull()
        ->and($thread->meta['auto_send']['status'])->toBe('cancelled')
        ->and($thread->meta['auto_send']['cancel_reason'])->toBe('cancelled_by_user')
        ->and($thread->body)->toContain('AI auto-reply cancelled')
        ->and($suggestion->content)->toBe($run->draft)
        ->and($suggestion->agent_run_id)->toBe($run->id)
        ->and($run->fresh()->auto_send['status'])->toBe('cancelled')
        ->and(Activity::where('event', 'auto_send_cancelled')->where('causer_id', $agent->id)->where('properties->run_id', $run->id)->exists())->toBeTrue();

    // The restored draft is what the conversation view offers.
    $this->actingAs($agent)->get("/conversations/{$f['conversation']->id}")
        ->assertInertia(fn ($page) => $page->where('detail.aiSuggestion.id', $suggestion->id)->etc());

    // Cancelling twice is a 409, not a second draft.
    $this->actingAs($agent)->postJson("/agent/auto-sends/{$thread->id}/cancel")->assertStatus(409);
    expect(AiSuggestion::count())->toBe(1);
});

test('Edit instead cancels and returns the text for the composer', function () {
    $f = autoTWorkspace();
    $run = autoTRun($f['conversation']);
    $thread = autoTPending($f['conversation']);

    $this->actingAs(agentUser($f['workspace']))->postJson("/agent/auto-sends/{$thread->id}/cancel", ['edit' => true])
        ->assertOk()->assertJsonPath('edit', true)->assertJsonPath('draft', $run->draft);

    expect($thread->fresh()->meta['auto_send']['cancel_reason'])->toBe('edit_instead');
    autoTExecute($thread);
    expect(autoTSent())->toBe([]);
});

test('the legacy schedule-cancel route also turns an auto-reply back into a draft', function () {
    $f = autoTWorkspace();
    autoTRun($f['conversation']);
    $thread = autoTPending($f['conversation']);

    $this->actingAs(agentUser($f['workspace']))->deleteJson("/conversations/{$f['conversation']->id}/threads/{$thread->id}/schedule")->assertOk();

    expect($thread->fresh()->meta['auto_send']['status'])->toBe('cancelled')->and(AiSuggestion::count())->toBe(1);
});

test('flipping the kill switch between scheduling and sending prevents the mail', function () {
    $f = autoTWorkspace();
    $run = autoTRun($f['conversation']);
    $thread = autoTPending($f['conversation']);

    // Directly in the database, as if another process flipped it.
    AgentSetting::where('workspace_id', $f['workspace']->id)->update(['kill_switch' => true]);
    autoTExecute($thread);

    expect(autoTSent())->toBe([])
        ->and($thread->fresh()->meta['auto_send']['cancel_reason'])->toBe('kill_switch')
        ->and(AiSuggestion::where('agent_run_id', $run->id)->exists())->toBeTrue();
});

test('the kill switch in settings cancels scheduled auto-replies at once', function () {
    $f = autoTWorkspace();
    autoTRun($f['conversation']);
    $thread = autoTPending($f['conversation']);

    $this->actingAs(adminUser($f['workspace']))->patch('/agent/settings', ['mode' => 'live', 'kill_switch' => true])->assertRedirect();

    expect($thread->fresh()->meta['auto_send']['cancel_reason'])->toBe('kill_switch')
        ->and($thread->fresh()->type->value)->toBe('note');
    autoTExecute($thread);
    expect(autoTSent())->toBe([]);
});

test('a change of workspace mode, pause or playbook in between prevents the mail', function (callable $change, string $reason) {
    $f = autoTWorkspace();
    autoTRun($f['conversation']);
    $thread = autoTPending($f['conversation']);

    $change($f);
    autoTExecute($thread);

    expect(autoTSent())->toBe([])
        ->and($thread->fresh()->meta['auto_send']['cancel_reason'])->toBe($reason);
})->with([
    'workspace back to draft' => [fn ($f) => AgentSetting::where('workspace_id', $f['workspace']->id)->update(['mode' => 'draft']), 'agent_mode'],
    'paused' => [fn ($f) => AgentSetting::where('workspace_id', $f['workspace']->id)->update(['paused_until' => now()->addHour()]), 'paused'],
    'playbook back to draft' => [fn ($f) => Playbook::whereKey($f['playbook']->id)->update(['mode' => 'draft']), 'playbook_mode'],
    'playbook on retest hold' => [fn ($f) => Playbook::whereKey($f['playbook']->id)->update(['auto_hold' => 'retesting']), 'playbook_hold'],
    'rollout lowered to 0' => [fn ($f) => Playbook::whereKey($f['playbook']->id)->update(['rollout_percent' => 0]), 'rollout'],
    'conversation closed' => [fn ($f) => $f['conversation']->update(['status' => 'closed']), 'conversation_closed'],
    'customer blocked' => [fn ($f) => $f['customer']->update(['is_blocked' => true]), 'customer_blocked'],
]);

test('a new customer message cancels the pending auto-reply and re-runs the pipeline', function () {
    $f = autoTWorkspace();
    autoTRun($f['conversation']);
    $thread = autoTPending($f['conversation']);

    $new = autoTCustomerMessage($f['conversation'], 'Actually, can I change my address?');
    app(InboundMessageEffects::class)->afterCustomerMessage($f['conversation']->fresh(), $new, false);

    expect($thread->fresh()->meta['auto_send']['cancel_reason'])->toBe('new_customer_message')
        // The old answer is moot: no stale draft comes back.
        ->and(AiSuggestion::count())->toBe(0);
    Bus::assertDispatched(GenerateReplySuggestionJob::class);

    autoTExecute($thread);
    expect(autoTSent())->toBe([]);
});

test('a customer message that slips in without the ingest hook is caught at send time', function () {
    $f = autoTWorkspace();
    autoTRun($f['conversation']);
    $thread = autoTPending($f['conversation']);
    autoTCustomerMessage($f['conversation']);

    autoTExecute($thread);

    expect(autoTSent())->toBe([])->and($thread->fresh()->meta['auto_send']['cancel_reason'])->toBe('new_customer_message');
});

test('a human reply from Gmail in between cancels the auto-reply', function () {
    $f = autoTWorkspace();
    autoTRun($f['conversation']);
    $thread = autoTPending($f['conversation']);

    $gmail = loopTStaffReply($f['conversation'], 'Hi Alex, it ships today. Sam', origin: Thread::ORIGIN_GMAIL);
    app(InboundMessageEffects::class)->afterAgentMessage($f['conversation']->fresh(), $gmail);

    expect($thread->fresh()->meta['auto_send']['cancel_reason'])->toBe('staff_replied')->and(AiSuggestion::count())->toBe(0);
    autoTExecute($thread);
    expect(autoTSent())->toBe([]);
});

test('a staff reply that slips in without the hook is caught at send time', function () {
    $f = autoTWorkspace();
    autoTRun($f['conversation']);
    $thread = autoTPending($f['conversation']);
    loopTStaffReply($f['conversation'], 'Handled it myself.', origin: Thread::ORIGIN_GMAIL);

    autoTExecute($thread);

    expect(autoTSent())->toBe([])->and($thread->fresh()->meta['auto_send']['cancel_reason'])->toBe('staff_replied');
});

test('0% rollout sends nothing and 100% sends every eligible conversation', function () {
    $zero = autoTWorkspace(playbook: ['rollout_percent' => 0]);
    expect(autoTRun($zero['conversation'])->decision)->toBe('draft')
        ->and(autoTRun($zero['conversation'])->auto_send['blockers'])->toContain('rollout');

    $all = autoTWorkspace(playbook: ['rollout_percent' => 100]);
    expect(autoTRun($all['conversation'])->decision)->toBe('auto_send');
});

test('a rollout keeps the same conversations as the percentage changes', function () {
    $f = autoTWorkspace();
    $bucket = PolicyGate::rolloutBucket($f['conversation']->id);

    $f['playbook']->update(['rollout_percent' => $bucket]); // just outside its bucket
    expect(autoTRun($f['conversation'], force: true)->auto_send['blockers'])->toContain('rollout');

    foreach (array_unique([$bucket + 1, min(100, $bucket + 20), 100]) as $percent) { // inside, and stays inside as it grows
        $f['playbook']->update(['rollout_percent' => $percent]);
        expect(autoTRun($f['conversation'], force: true)->auto_send['blockers'])->not->toContain('rollout');
    }
});

test('consecutive auto-replies without a human are capped', function () {
    $f = autoTWorkspace(['caps' => ['auto_send_per_day' => 20, 'auto_send_per_customer_per_day' => 10, 'auto_send_consecutive_per_conversation' => 2]]);
    foreach ([1, 2] as $i) {
        Thread::factory()->create(['conversation_id' => $f['conversation']->id, 'type' => 'message', 'origin' => 'ai', 'user_id' => null, 'customer_id' => null,
            'created_at' => now()->subMinutes(10 - $i), 'meta' => ['auto_send' => ['status' => 'sent']]]);
    }
    autoTCustomerMessage($f['conversation'], 'Thanks! And the other order?');

    $run = autoTRun($f['conversation']);
    expect($run->decision)->toBe('draft')->and($run->auto_send['blockers'])->toBe(['consecutive_cap']);
});

test('the daily cap stops auto-sending for the rest of the day', function () {
    $f = autoTWorkspace(['caps' => ['auto_send_per_day' => 1, 'auto_send_per_customer_per_day' => 5, 'auto_send_consecutive_per_conversation' => 2]]);
    AgentRun::create(['workspace_id' => $f['workspace']->id, 'purpose' => 'live', 'idempotency_key' => 'prior', 'status' => 'completed',
        'config' => [], 'config_hash' => 'x', 'decision' => 'auto_send']);

    $run = autoTRun($f['conversation']);
    expect($run->decision)->toBe('draft')->and($run->auto_send['blockers'])->toBe(['daily_cap']);
});

test('an inbound auto-reply is never auto-answered', function () {
    $f = autoTWorkspace();
    $f['thread']->update(['meta' => ['auto_submitted' => true]]);

    $result = app(AgentPipeline::class)->run(PipelineInput::fromConversation($f['conversation']->fresh()));

    expect($result->skipped)->toBe('auto_reply')->and(autoTPending($f['conversation']))->toBeNull();
});

test('bulk / list mail that reaches the agent is drafted, never auto-answered', function () {
    $f = autoTWorkspace();
    // Classified as support by the ingest, but it carries list headers.
    $f['mail']->forceFill(['headers' => ['list-unsubscribe' => '<mailto:unsub@example.com>', 'precedence' => 'bulk']])->save();

    $run = autoTRun($f['conversation']);
    expect($run->decision)->toBe('draft')->and($run->auto_send['blockers'])->toBe(['inbound_automated']);
});

test('a regenerate requested by a human, a non-English or an angry message stays a draft', function () {
    $f = autoTWorkspace();
    expect(autoTRun($f['conversation'], force: true)->auto_send['blockers'])->toContain('requested_by_human');

    TriageAgent::fake(fn () => agentTStructured(['language' => 'de', 'sentiment' => 'angry', 'risk_flags' => [], 'playbook' => 'order_status', 'confidence' => 0.9, 'reason' => 'x']));
    $g = autoTWorkspace();
    $run = autoTRun($g['conversation']);
    expect($run->decision)->toBe('draft')->and($run->auto_send['blockers'])->toEqualCanonicalizing(['language', 'sentiment']);
});

test('a proposed action that cannot run unattended keeps the reply a draft', function () {
    $f = autoTWorkspace();
    $context = autoTRun($f['conversation'], force: true)->trace['auto_send_context'];
    $context['requested_by_human'] = false;
    // Every policy is `never` on install, so the context says "not unattended".
    $context['actions_unattended'] = false;

    $gate = PolicyGate::decide([
        'agent_mode' => 'live', 'kill_switch' => false, 'playbook' => ['mode' => 'auto', 'rollout_percent' => 100, 'always_human' => false],
        'rollout_bucket' => 1, 'risk_flags' => [], 'writer_handover' => null, 'order_withheld' => false, 'missing_evidence' => [],
        'validator_passed' => true, 'validator_error' => false, 'has_draft' => true,
        'proposed_actions' => [['action' => 'refund', 'status' => 'proposed']], 'auto_send' => $context,
    ]);

    expect($gate['decision'])->toBe('draft')->and($gate['auto_send_blockers'])->toBe(['actions_pending']);
});

test('chat conversations are not auto-sent unless the channel is enabled', function () {
    $f = autoTWorkspace();
    $f['conversation']->update(['channel_type' => 'chat']);
    expect(autoTRun($f['conversation'])->auto_send['blockers'])->toBe(['channel']);

    $g = autoTWorkspace(['auto_send' => ['channels' => ['email', 'chat']]]);
    $g['conversation']->update(['channel_type' => 'chat']);
    expect(autoTRun($g['conversation'])->decision)->toBe('auto_send');
});

test('preview and simulation runs never auto-send', function () {
    $f = autoTWorkspace();
    $case = loopTFrozenCase($f);
    $run = app(CaseRunner::class)->run($case, InstructionSet::live($f['workspace']->id));

    expect($run->decision)->toBe('draft')->and($run->auto_send['blockers'])->toBe(['no_context']);
    Bus::assertNotDispatched(SendReplyJob::class);
});

test('the hourly breaker pauses all auto-sending and notifies admins', function () {
    config(['agent.auto_send.max_per_hour' => 2]);
    $f = autoTWorkspace();
    $admin = adminUser($f['workspace']);
    foreach ([1, 2] as $i) {
        AgentRun::create(['workspace_id' => $f['workspace']->id, 'purpose' => 'live', 'idempotency_key' => "h{$i}", 'status' => 'completed',
            'config' => [], 'config_hash' => 'x', 'decision' => 'auto_send']);
    }

    $run = autoTRun($f['conversation']);
    $settings = $f['settings']->fresh();

    expect($run->decision)->toBe('draft')
        ->and($run->auto_send['blockers'])->toBe(['hourly_breaker'])
        ->and($settings->isPaused())->toBeTrue()
        ->and($settings->paused_reason)->toContain('Circuit breaker')
        ->and(Activity::where('event', 'autosend_paused')->exists())->toBeTrue();
    Notification::assertSentTo($admin, AgentAutoSendNotification::class, fn ($n) => $n->kind === AgentAutoSendNotification::KIND_PAUSED);

    // While paused, nothing auto-sends even after the hour has passed; nothing resumes on its own.
    $this->travel(2)->hours();
    $forced = autoTRun($f['conversation'], force: true);
    expect($forced->auto_send['blockers'])->toContain('paused')->and($f['settings']->fresh()->isPaused())->toBeTrue();
});

test('an admin can pause and resume auto-sending; pausing cancels what is scheduled', function () {
    $f = autoTWorkspace();
    autoTRun($f['conversation']);
    $thread = autoTPending($f['conversation']);
    $admin = adminUser($f['workspace']);

    $this->actingAs($admin)->postJson('/agent/autosend/pause', ['reason' => 'Holiday'])->assertOk()->assertJsonPath('cancelled', 1);
    expect($f['settings']->fresh()->isPaused())->toBeTrue()
        ->and($thread->fresh()->meta['auto_send']['cancel_reason'])->toBe('paused');

    $this->actingAs($admin)->postJson('/agent/autosend/resume')->assertOk()->assertJsonPath('paused_until', null);
    expect($f['settings']->fresh()->isPaused())->toBeFalse()
        ->and(Activity::where('event', 'autosend_resumed')->where('causer_id', $admin->id)->exists())->toBeTrue();
});

test('the run payload shows the auto-send lifecycle', function () {
    $f = autoTWorkspace();
    $run = autoTRun($f['conversation']);

    $this->actingAs(agentUser($f['workspace']))->getJson("/agent/conversations/{$f['conversation']->id}/run")
        ->assertOk()
        ->assertJsonPath('run.decision', 'auto_send')
        ->assertJsonPath('run.auto_send.status', 'scheduled')
        ->assertJsonPath('run.auto_send.events.1.event', 'auto_send_scheduled')
        ->assertJsonPath('run.auto_send.blockers', []);
    expect($run->id)->toBeInt();
});

test('scheduling failure falls back to a normal draft', function () {
    $f = autoTWorkspace();
    app()->instance(AutoSendService::class, new class extends AutoSendService
    {
        public function schedule(AgentRun $run, Conversation $conversation, bool $awaitingActions = false): Thread
        {
            throw new RuntimeException('boom');
        }
    });

    $run = autoTRun($f['conversation']);

    expect($run->decision)->toBe('auto_send')
        ->and(AiSuggestion::where('agent_run_id', $run->id)->exists())->toBeTrue()
        ->and(autoTPending($f['conversation']))->toBeNull()
        ->and($run->fresh()->auto_send['status'])->toBe('schedule_failed');
});
