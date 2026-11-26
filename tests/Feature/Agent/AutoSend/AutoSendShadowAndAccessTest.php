<?php

require_once __DIR__.'/AutoSendTestHelpers.php';

use App\Domains\Agent\Agents\SimulationJudgeAgent;
use App\Domains\Agent\AutoSend\Shadow\ShadowComparer;
use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\FrozenCase;
use App\Domains\Agent\Models\ShadowComparison;
use App\Domains\Agent\Pipeline\AgentPipeline;
use App\Domains\Agent\Pipeline\PipelineInput;
use App\Domains\AI\Jobs\GenerateReplySuggestionJob;
use App\Domains\Conversation\Jobs\SendReplyJob;
use App\Domains\Conversation\Models\AiSuggestion;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Customer\Models\Customer;
use App\Domains\Mailbox\Models\Mailbox;
use App\Enums\ThreadType;
use App\Events\AiSuggestionReady;
use App\Events\NewThreadReceived;
use App\Models\Workspace;
use App\Services\ThreadService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Http::preventStrayRequests();
    Bus::fake([SendReplyJob::class, GenerateReplySuggestionJob::class]);
    Notification::fake();
    loopTFakePipeline(['Hi Alex, your order is being packed now.']);
});

function autoTShadowRun(array $f): AgentRun
{
    return app(AgentPipeline::class)->run(PipelineInput::fromConversation($f['conversation']->fresh(), AgentRun::PURPOSE_SHADOW))->run;
}

test('a shadow run records that it would have auto-sent, and touches nothing', function () {
    $f = autoTWorkspace(['mode' => 'shadow']);

    $run = autoTShadowRun($f);

    expect($run->purpose)->toBe('shadow')
        ->and($run->decision)->toBe('draft')
        ->and($run->auto_send['would_auto_send'])->toBeTrue()
        ->and($run->auto_send['eligible_if_auto'])->toBeTrue()
        ->and(autoTPending($f['conversation']))->toBeNull()
        ->and(AiSuggestion::count())->toBe(0);
    Bus::assertNotDispatched(SendReplyJob::class);
});

test('the human reply is compared with the shadow draft, without side effects', function () {
    $f = autoTWorkspace(['mode' => 'shadow']);
    $run = autoTShadowRun($f);
    loopTFakeJudge('guaranteed');
    $staff = loopTStaffReply($f['conversation'], 'Hi Alex, it is being packed now. Cheers');

    $threads = Thread::count();
    $suggestions = AiSuggestion::count();
    $before = $f['conversation']->fresh()->only(['status', 'handover_reason', 'updated_at', 'last_reply_at']);
    Event::fake([AiSuggestionReady::class, NewThreadReceived::class]);
    Mail::fake();

    $comparison = app(ShadowComparer::class)->compare($run, $staff);

    expect($comparison->status)->toBe('ok')
        ->and($comparison->agreed)->toBeTrue()
        ->and($comparison->would_auto_send)->toBeTrue()
        ->and($comparison->score)->toBeGreaterThan(3)
        ->and(Thread::count())->toBe($threads)
        ->and(AiSuggestion::count())->toBe($suggestions)
        ->and($f['conversation']->fresh()->only(['status', 'handover_reason', 'updated_at', 'last_reply_at']))->toEqual($before)
        ->and(FrozenCase::count())->toBe(0);
    Event::assertNothingDispatched();
    Mail::assertNothingSent();
    Bus::assertNotDispatched(SendReplyJob::class);
    SimulationJudgeAgent::assertPrompted(fn ($p) => str_contains($p->prompt, 'it is being packed now'));
});

test('a staff reply sent from the app queues the shadow comparison', function () {
    $f = autoTWorkspace(['mode' => 'shadow']);
    $run = autoTShadowRun($f);
    loopTFakeJudge('guaranteed');

    app(ThreadService::class)->store($f['conversation']->fresh(), '<p>Packed today!</p>', ThreadType::Message, agentUser($f['workspace']));

    expect(ShadowComparison::where('run_id', $run->id)->sole()->status)->toBe('ok');
});

test('sampling and the daily cap bound judge cost', function () {
    $f = autoTWorkspace(['mode' => 'shadow']);
    loopTFakeJudge('guaranteed');

    config(['agent.auto_send.shadow.sample_rate' => 0.0]);
    $run = autoTShadowRun($f);
    $c = app(ShadowComparer::class)->compare($run, loopTStaffReply($f['conversation'], 'One'));
    expect($c->status)->toBe('skipped')->and($c->skip_reason)->toBe('sampled_out');

    config(['agent.auto_send.shadow.sample_rate' => 1.0, 'agent.auto_send.shadow.daily_cap' => 0]);
    $g = autoTWorkspace(['mode' => 'shadow']);
    $c2 = app(ShadowComparer::class)->compare(autoTShadowRun($g), loopTStaffReply($g['conversation'], 'Two'));
    expect($c2->skip_reason)->toBe('daily_cap');
    SimulationJudgeAgent::assertNeverPrompted();
});

test('the shadow report gives would-have-auto-sent counts, agreement per playbook and the worst disagreements', function () {
    $f = autoTWorkspace(['mode' => 'shadow']);
    $good = autoTShadowRun($f);
    loopTFakeJudge('guaranteed');
    app(ShadowComparer::class)->compare($good, loopTStaffReply($f['conversation'], 'Packed.'));

    loopTFakePipeline(['Your order is guaranteed to arrive soon.']);
    $g = autoTWorkspace(['mode' => 'shadow']);
    // Same workspace for the report: move the second case's rows over.
    $bad = autoTShadowRun($g);
    $bad->forceFill(['workspace_id' => $f['workspace']->id, 'playbook_id' => $f['playbook']->id])->save();
    $comparison = app(ShadowComparer::class)->compare($bad, loopTStaffReply($g['conversation'], 'It ships Monday.'));

    $report = $this->actingAs(adminUser($f['workspace']))->getJson('/agent/autosend/shadow-report?days=7')->assertOk()->json();

    expect($report['totals']['shadow_runs'])->toBe(2)
        ->and($report['totals']['would_auto_send'])->toBe(2)
        ->and($report['totals']['compared'])->toBe(2)
        ->and($report['totals']['agreement_rate'])->toBe(0.5)
        ->and($report['totals']['would_auto_send_compared'])->toBe(2)
        ->and($report['totals']['would_auto_send_agreed'])->toBe(1)
        ->and($report['totals']['would_auto_send_agreement_rate'])->toBe(0.5)
        ->and($report['playbooks'][0]['playbook']['slug'])->toBe('order_status')
        ->and($report['playbooks'][0]['would_auto_send_agreement_rate'])->toBe(0.5)
        ->and($report['worst_disagreements'])->toHaveCount(1)
        ->and($report['worst_disagreements'][0]['run_id'])->toBe($bad->id)
        ->and($report['worst_disagreements'][0]['ai_reply'])->toContain('guaranteed')
        ->and($report['worst_disagreements'][0]['human_reply'])->toContain('Monday');
});

// ── Authorization on every new route ─────────────────────────────────────────

test('auto-send routes require login, the right role and the same workspace', function () {
    $f = autoTWorkspace();
    autoTRun($f['conversation']);
    $pending = autoTPending($f['conversation']);
    $agent = agentUser($f['workspace']);
    $outsider = agentUser(Workspace::factory()->create());
    $outsiderAdmin = adminUser(Workspace::find($outsider->workspace_id));

    // Guests
    foreach ([
        ['postJson', "/agent/auto-sends/{$pending->id}/cancel"],
        ['postJson', "/agent/auto-sends/{$pending->id}/wrong"],
        ['postJson', '/agent/autosend/pause'],
        ['postJson', '/agent/autosend/resume'],
        ['getJson', '/agent/autosend/shadow-report'],
        ['getJson', "/agent/playbooks/{$f['playbook']->id}/autosend"],
    ] as [$method, $url]) {
        $this->{$method}($url)->assertUnauthorized();
    }

    // Admin-only for an agent
    $this->actingAs($agent)->postJson('/agent/autosend/pause')->assertForbidden();
    $this->actingAs($agent)->postJson('/agent/autosend/resume')->assertForbidden();
    $this->actingAs($agent)->getJson('/agent/autosend/shadow-report')->assertForbidden();
    $this->actingAs($agent)->getJson("/agent/playbooks/{$f['playbook']->id}/autosend")->assertForbidden();

    // Another workspace
    $this->actingAs($outsider)->postJson("/agent/auto-sends/{$pending->id}/cancel")->assertForbidden();
    $this->actingAs($outsider)->postJson("/agent/auto-sends/{$pending->id}/wrong", ['reason' => 'x x x'])->assertForbidden();
    $this->actingAs($outsiderAdmin)->getJson("/agent/playbooks/{$f['playbook']->id}/autosend")->assertNotFound();
    expect($pending->fresh()->meta['auto_send']['status'])->toBe('scheduled');

    // An admin of the workspace
    $admin = adminUser($f['workspace']);
    $this->actingAs($admin)->getJson("/agent/playbooks/{$f['playbook']->id}/autosend")->assertOk()->assertJsonStructure(['preconditions', 'breakers', 'history', 'rollout_preview']);
    $this->actingAs($admin)->getJson('/agent/autosend/shadow-report')->assertOk()->assertJsonStructure(['totals', 'playbooks', 'worst_disagreements']);
});

test('switching the workspace to live needs an explicit confirmation', function () {
    $f = autoTWorkspace(['mode' => 'draft']);
    $admin = adminUser($f['workspace']);

    $this->actingAs($admin)->patch('/agent/settings', ['mode' => 'live', 'kill_switch' => false])->assertSessionHasErrors('confirm_live');
    $this->actingAs($admin)->patch('/agent/settings', [
        'mode' => 'live', 'kill_switch' => false, 'confirm_live' => true,
        'auto_send_delay_seconds' => 600,
        'caps' => ['auto_send_per_day' => 7, 'auto_send_consecutive_per_conversation' => 1],
        'auto_send' => ['allowed_languages' => ['en', 'DE'], 'disclosure_line' => 'Sent by our AI assistant.', 'status_after_send' => 'open'],
    ])->assertSessionHasNoErrors();

    $settings = $f['settings']->fresh();
    expect($settings->mode)->toBe('live')
        ->and($settings->auto_send_delay_seconds)->toBe(600)
        ->and($settings->cap('auto_send_per_day'))->toBe(7)
        ->and($settings->cap('auto_send_consecutive_per_conversation'))->toBe(1)
        ->and($settings->autoSendOptions()['allowed_languages'])->toBe(['en', 'de'])
        ->and($settings->autoSendOptions()['status_after_send'])->toBe('open');

    $this->actingAs($admin)->patch('/agent/settings', ['mode' => 'live', 'kill_switch' => false, 'auto_send' => ['status_after_send' => 'closed']])
        ->assertSessionHasErrors('auto_send.status_after_send');
});

// ── Loose end: a dismissed draft does not come back ─────────────────────────

test('a dismissed draft does not reappear after reload', function () {
    $workspace = Workspace::factory()->create();
    $user = agentUser($workspace);
    $mailbox = Mailbox::factory()->create(['workspace_id' => $workspace->id]);
    $customer = Customer::factory()->create(['workspace_id' => $workspace->id]);
    $conversation = Conversation::factory()->create(['workspace_id' => $workspace->id, 'mailbox_id' => $mailbox->id, 'customer_id' => $customer->id, 'status' => 'open']);
    Thread::factory()->create(['conversation_id' => $conversation->id, 'customer_id' => $customer->id, 'type' => 'message', 'body' => '<p>Hi</p>', 'created_at' => now()->subMinutes(5)]);
    $older = AiSuggestion::create(['conversation_id' => $conversation->id, 'type' => 'reply', 'content' => 'Older draft', 'model' => 'x']);
    $older->forceFill(['created_at' => now()->subMinutes(2)])->save();
    $draft = AiSuggestion::create(['conversation_id' => $conversation->id, 'type' => 'reply', 'content' => 'Hello!', 'model' => 'x']);

    $this->actingAs($user)->get("/conversations/{$conversation->id}")
        ->assertInertia(fn ($page) => $page->where('detail.aiSuggestion.id', $draft->id)->etc());

    $this->actingAs($user)->postJson("/agent/suggestions/{$draft->id}/dismiss")->assertOk();

    $this->actingAs($user)->get("/conversations/{$conversation->id}")
        ->assertInertia(fn ($page) => $page->where('detail.aiSuggestion', null)->etc());
    $this->actingAs($user)->get("/conversations?conversation={$conversation->id}")
        ->assertInertia(fn ($page) => $page->where('selected.aiSuggestion', null)->etc());
});
