<?php

require_once __DIR__.'/AutoSendTestHelpers.php';
require_once __DIR__.'/../../Commerce/Actions/ActionTestHelpers.php';

use App\Domains\Agent\AutoSend\AutoSendContext;
use App\Domains\Agent\AutoSend\AutoSendService;
use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Pipeline\PolicyGate;
use App\Domains\Agent\Pipeline\RunEffects;
use App\Domains\AI\Jobs\GenerateReplySuggestionJob;
use App\Domains\Commerce\Actions\ActionApprovals;
use App\Domains\Commerce\Actions\ActionExecutor;
use App\Domains\Commerce\Actions\ActionGate;
use App\Domains\Commerce\Actions\Jobs\ExecuteActionJob;
use App\Domains\Commerce\Actions\Models\ActionExecution;
use App\Domains\Commerce\Actions\Models\ActionSettings;
use App\Domains\Commerce\Models\CommerceSetting;
use App\Domains\Conversation\Jobs\SendReplyJob;
use App\Domains\Conversation\Models\AiSuggestion;
use App\Domains\Conversation\Models\Thread;
use App\Enums\ThreadType;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
 * Auto-send x Shopify actions (docs/agent-autosend.md "Replies that depend on
 * an action"; docs/agent-actions.md "Reply coupling"). A reply that depends on
 * actions is auto-sent only when every one of them ran unattended and
 * succeeded; anything needing a human, failing or unknown turns it back into
 * a draft. Plus the kill switch / pause checks right before an execution.
 */

const ASA_REFUND = ['amount' => '20.00', 'shipping' => 'none', 'notify_customer' => true, 'reason' => 'Arrived scratched'];

/** Live auto workspace with Shopify connected and a refund policy. */
function asaT(string $mode = 'always', array $limits = ['max_amount' => 50]): array
{
    $f = autoTWorkspace(['caps' => ['auto_send_per_day' => 20, 'auto_send_per_customer_per_day' => 5, 'auto_send_consecutive_per_conversation' => 2, 'actions_per_day' => 20]]);
    CommerceSetting::forWorkspace($f['workspace']->id)->update([
        'shopify_shop_domain' => 'test-shop.myshopify.com',
        'shopify_client_id' => '11111111111111111111111111111111',
        'shopify_client_secret' => Crypt::encryptString('shpss_secret'),
        'shopify_access_token' => Crypt::encryptString('shpca_test_token_value'),
        'shopify_access_token_expires_at' => now()->addHours(23),
        'shopify_granted_scopes' => 'read_orders,write_orders,write_discounts,write_draft_orders,write_third_party_fulfillment_orders,read_all_orders',
    ]);
    ActionSettings::forWorkspace($f['workspace']->id);
    actTPolicy($f['workspace']->id, 'refund', $mode, $limits);
    $f['case'] = ['workspace' => $f['workspace'], 'conversation' => $f['conversation']];

    return $f;
}

/** A live run the gate decided to auto-send, which proposed a refund. */
function asaTRun(array $f, string $decision = PolicyGate::AUTO_SEND): AgentRun
{
    return AgentRun::create([
        'workspace_id' => $f['workspace']->id,
        'purpose' => AgentRun::PURPOSE_LIVE,
        'conversation_id' => $f['conversation']->id,
        'customer_thread_id' => $f['thread']->id,
        'playbook_id' => $f['playbook']->id,
        'idempotency_key' => 'live:'.Str::uuid7(),
        'status' => AgentRun::STATUS_COMPLETED,
        'config' => ['config_hash' => 'x'],
        'config_hash' => 'x',
        'decision' => $decision,
        'tokens_in' => 0,
        'tokens_out' => 0,
        'draft' => 'Hi Alex, I have refunded AUD 20.00 to your card.',
        'auto_send' => ['blockers' => []],
        'evidence' => ['facts' => [['id' => 'E1', 'source' => 'shopify', 'key' => 'shopify.order_number', 'label' => 'Order', 'value' => '#SD8421']], 'meta' => ['shopify_payload' => ['order_number' => '#SD8421', 'shopify_order_id' => 'gid://shopify/Order/99', 'order_email' => 'buyer@example.com']]],
        'proposed_actions' => [['action' => 'refund', 'params' => ASA_REFUND, 'order_number' => 'SD8421', 'reason' => 'Customer asked (E1).', 'details' => null, 'status' => 'proposed']],
    ]);
}

/** Shopify fake for a refund that succeeds (verify finds the marker). */
function asaTShopifyRefund(?callable $onOrderRead = null, array $refundPayload = []): void
{
    $order = function () use ($onOrderRead) {
        if ($onOrderRead !== null) {
            $onOrderRead();
        }
        $id = (int) ActionExecution::max('id');

        return ['data' => ['order' => actTOrder(['refunds' => ActionExecution::whereKey($id)->value('status') === ActionExecution::EXECUTING && actTSent('refundCreate') !== []
            ? [['id' => 'gid://shopify/Refund/5', 'note' => "Refund - fusterai-action-{$id}", 'createdAt' => now()->toIso8601String(), 'totalRefundedSet' => ['shopMoney' => ['amount' => '20.00', 'currencyCode' => 'AUD']]]]
            : []])]];
    };
    actTShopify([
        'ActionOrderState' => $order,
        'ActionSuggestedRefund' => ['data' => ['order' => ['id' => 'gid://shopify/Order/99', 'suggestedRefund' => [
            'amountSet' => ['shopMoney' => ['amount' => '90.00', 'currencyCode' => 'AUD']],
            'maximumRefundableSet' => ['shopMoney' => ['amount' => '100.00', 'currencyCode' => 'AUD']],
            'shipping' => ['amountSet' => ['shopMoney' => ['amount' => '10.00']], 'maximumRefundableSet' => ['shopMoney' => ['amount' => '10.00']]],
        ]]]],
        'ActionRefund' => ['data' => ['refundCreate' => array_replace(['refund' => ['id' => 'gid://shopify/Refund/5', 'note' => 'x', 'totalRefundedSet' => ['shopMoney' => ['amount' => '20.00', 'currencyCode' => 'AUD']]], 'userErrors' => []], $refundPayload)]],
    ]);
}

function asaTExecution(AgentRun $run): ActionExecution
{
    return ActionExecution::where('run_id', $run->id)->firstOrFail();
}

beforeEach(function () {
    Http::preventStrayRequests();
    Notification::fake();
});

// ── Gate ─────────────────────────────────────────────────────────────────────

test('the gate auto-sends an action-dependent reply only when every action can run unattended', function () {
    $f = asaT();
    $context = array_replace(autoTContext(), ['actions_unattended' => true]);
    $base = autoTGate(['proposed_actions' => [['action' => 'refund', 'status' => 'proposed']]], $context);

    expect(PolicyGate::decide($base)['decision'])->toBe(PolicyGate::AUTO_SEND);

    $base['auto_send']['actions_unattended'] = false;
    expect(PolicyGate::decide($base)['auto_send_blockers'])->toBe(['actions_pending']);

    unset($base['auto_send']['actions_unattended']);
    expect(PolicyGate::decide($base)['auto_send_blockers'])->toBe(['actions_pending']); // missing = blocked
});

test('the auto-send context says unattended only for policy always on an unattended-capable action', function () {
    $f = asaT();
    $context = app(AutoSendContext::class);
    $check = fn (array $actions) => (fn () => $this->actionsUnattended($f['workspace']->id, $f['playbook']->id, $actions))->call($context);

    expect($check([]))->toBeTrue()
        ->and($check([['action' => 'refund', 'status' => 'proposed']]))->toBeTrue()
        ->and($check([['action' => 'replacement', 'status' => 'proposed']]))->toBeFalse() // approval-only type
        ->and($check([['action' => 'no_such_action', 'status' => 'proposed']]))->toBeFalse();

    actTPolicy($f['workspace']->id, 'refund', 'approval');
    expect($check([['action' => 'refund', 'status' => 'proposed']]))->toBeFalse();
});

// ── Reply waits for its actions ─────────────────────────────────────────────

test('an unattended refund that succeeds lets the held auto-reply go out', function () {
    Bus::fake([SendReplyJob::class, GenerateReplySuggestionJob::class]);
    $f = asaT();
    asaTShopifyRefund();
    $run = asaTRun($f);

    app(RunEffects::class)->apply($run, $f['conversation']);

    $execution = asaTExecution($run);
    expect($execution->route)->toBe(ActionExecution::ROUTE_UNATTENDED)
        ->and($execution->status)->toBe(ActionExecution::SUCCEEDED)
        ->and($run->fresh()->decision)->toBe(PolicyGate::AUTO_SEND);

    autoTExecute(autoTPending($f['conversation']));

    expect(autoTSent())->toHaveCount(1)
        ->and(actTSent('refundCreate'))->toHaveCount(1)
        ->and(AutoSendService::state(autoTPending($f['conversation'])->fresh())['status'])->toBe('sent');
});

test('while the unattended action runs the guard holds the reply and re-checks; it sends once the action succeeded', function () {
    Bus::fake([SendReplyJob::class, GenerateReplySuggestionJob::class, ExecuteActionJob::class]);
    $f = asaT();
    asaTShopifyRefund();
    $run = asaTRun($f);

    app(RunEffects::class)->apply($run, $f['conversation']);
    $thread = autoTPending($f['conversation']);
    $execution = asaTExecution($run);
    expect($execution->status)->toBe(ActionExecution::APPROVED)
        ->and(AutoSendService::state($thread)['awaiting_actions'])->toBeTrue()
        ->and(AutoSendService::state($thread)['wait_until'])->not->toBeNull();

    // Due, but the refund has not run yet: not sent, not cancelled, re-check queued.
    autoTExecute($thread);
    expect(autoTSent())->toBe([])
        ->and(AutoSendService::state($thread->fresh())['status'])->toBe('scheduled')
        ->and($thread->fresh()->type)->toBe(ThreadType::Message);
    Bus::assertDispatched(SendReplyJob::class, 2); // the schedule + one re-check

    app(ActionExecutor::class)->execute($execution->id);
    expect($execution->fresh()->status)->toBe(ActionExecution::SUCCEEDED);

    autoTExecute($thread);
    expect(autoTSent())->toHaveCount(1)->and(actTSent('refundCreate'))->toHaveCount(1);
});

test('a failed unattended action cancels the held auto-reply back to a draft flagged for rewrite', function () {
    Bus::fake([SendReplyJob::class, GenerateReplySuggestionJob::class, ExecuteActionJob::class]);
    $f = asaT();
    asaTShopifyRefund(refundPayload: ['refund' => null, 'userErrors' => [['field' => ['amount'], 'message' => 'Refund exceeds the refundable amount']]]);
    $run = asaTRun($f);
    app(RunEffects::class)->apply($run, $f['conversation']);
    $thread = autoTPending($f['conversation']);

    app(ActionExecutor::class)->execute(asaTExecution($run)->id);

    $thread->refresh();
    expect(asaTExecution($run)->status)->toBe(ActionExecution::FAILED)
        ->and($thread->type)->toBe(ThreadType::Note)
        ->and(AutoSendService::state($thread)['cancel_reason'])->toBe('action_failed')
        ->and(AiSuggestion::where('agent_run_id', $run->id)->exists())->toBeTrue()
        ->and(ActionGate::state($run->fresh())['state'])->toBe('needs_rewrite');

    autoTExecute($thread);
    expect(autoTSent())->toBe([]);
});

test('a held reply whose action never finishes is turned back into a draft after the wait', function () {
    Bus::fake([SendReplyJob::class, GenerateReplySuggestionJob::class, ExecuteActionJob::class]);
    $f = asaT();
    asaTShopifyRefund();
    $run = asaTRun($f);
    app(RunEffects::class)->apply($run, $f['conversation']);
    $thread = autoTPending($f['conversation']);

    $this->travel(config('agent.auto_send.action_wait_seconds') + config('agent.auto_send_delay_seconds', 120) + 60)->seconds();
    autoTExecute($thread);

    expect(autoTSent())->toBe([])
        ->and(AutoSendService::state($thread->fresh())['cancel_reason'])->toBe('action_timeout')
        ->and(AiSuggestion::where('agent_run_id', $run->id)->exists())->toBeTrue();
});

test('a reply whose action needs approval is never auto-sent: it becomes a draft with the approval card', function () {
    Bus::fake([SendReplyJob::class, GenerateReplySuggestionJob::class]);
    // Policy always, but over the per-action limit: the recorder routes it to approval.
    $f = asaT('always', ['max_amount' => 10]);
    asaTShopifyRefund();
    $run = asaTRun($f);

    app(RunEffects::class)->apply($run, $f['conversation']);

    $run->refresh();
    expect(asaTExecution($run)->status)->toBe(ActionExecution::PENDING_APPROVAL)
        ->and($run->decision)->toBe(PolicyGate::DRAFT)
        ->and($run->auto_send['status'])->toBe('downgraded')
        ->and($run->auto_send['blockers'])->toContain('action_needs_approval')
        ->and(Thread::where('conversation_id', $f['conversation']->id)->where('origin', Thread::ORIGIN_AI)->exists())->toBeFalse()
        ->and(AiSuggestion::where('agent_run_id', $run->id)->exists())->toBeTrue()
        ->and(actTSent('refundCreate'))->toBe([]);
    Bus::assertNotDispatched(SendReplyJob::class);
});

test('an action moved back to approval after scheduling cancels the held reply', function () {
    Bus::fake([SendReplyJob::class, GenerateReplySuggestionJob::class, ExecuteActionJob::class]);
    $f = asaT();
    asaTShopifyRefund();
    $run = asaTRun($f);
    app(RunEffects::class)->apply($run, $f['conversation']);
    $thread = autoTPending($f['conversation']);

    actTPolicy($f['workspace']->id, 'refund', 'always', ['max_amount' => 5]);
    app(ActionExecutor::class)->execute(asaTExecution($run)->id);

    expect(asaTExecution($run)->status)->toBe(ActionExecution::PENDING_APPROVAL)
        ->and(AutoSendService::state($thread->fresh())['cancel_reason'])->toBe('action_needs_approval')
        ->and(actTSent('refundCreate'))->toBe([]);
});

test('the pre-send guard re-checks the actions even when the thread was not marked as waiting', function () {
    Bus::fake([SendReplyJob::class, GenerateReplySuggestionJob::class, ExecuteActionJob::class]);
    $f = asaT();
    asaTShopifyRefund();
    $run = asaTRun($f);
    app(RunEffects::class)->apply($run, $f['conversation']);
    $thread = autoTPending($f['conversation']);
    // Simulate a thread scheduled without the waiting flag (e.g. an older row).
    $meta = $thread->meta;
    unset($meta['auto_send']['awaiting_actions'], $meta['auto_send']['wait_until']);
    $thread->forceFill(['meta' => $meta])->save();

    autoTExecute($thread);

    expect(autoTSent())->toBe([])
        ->and(AutoSendService::state($thread->fresh())['cancel_reason'])->toBe('action_timeout');
});

test('on a sync queue the re-check does not recurse; the reply just stays held', function () {
    Bus::fake([GenerateReplySuggestionJob::class, ExecuteActionJob::class]); // SendReplyJob runs for real (sync)
    $f = asaT();
    asaTShopifyRefund();
    $run = asaTRun($f);
    app(RunEffects::class)->apply($run, $f['conversation']); // sync: SendReplyJob runs at once (delay ignored)
    $thread = autoTPending($f['conversation']);

    autoTExecute($thread);

    expect(autoTSent())->toBe([])
        ->and(AutoSendService::state($thread->fresh())['status'])->toBe('scheduled');
});

// ── Human sends ──────────────────────────────────────────────────────────────

test('a person cannot send the draft while its action has not succeeded, unless they edit it and confirm', function () {
    Bus::fake([SendReplyJob::class, GenerateReplySuggestionJob::class]);
    $f = asaT('approval');
    asaTShopifyRefund();
    $run = asaTRun($f, PolicyGate::DRAFT);
    app(RunEffects::class)->apply($run, $f['conversation']);
    expect(asaTExecution($run)->status)->toBe(ActionExecution::PENDING_APPROVAL);
    $user = adminUser($f['workspace']);
    $url = "/conversations/{$f['conversation']->id}/threads";

    $this->actingAs($user)->postJson($url, ['type' => 'message', 'body' => '<p>'.$run->draft.'</p>'])
        ->assertStatus(422)->assertJsonValidationErrors('send_anyway');
    $this->actingAs($user)->postJson($url, ['type' => 'message', 'body' => '<p>'.$run->draft.'</p>', 'send_anyway' => true])
        ->assertStatus(422)->assertJsonValidationErrors('send_anyway');
    $this->actingAs($user)->postJson($url, ['type' => 'message', 'body' => '<p>Hi Alex, we are looking into a refund and will confirm shortly.</p>'])
        ->assertStatus(422)->assertJsonValidationErrors('send_anyway');
    expect(Thread::where('conversation_id', $f['conversation']->id)->whereNotNull('user_id')->count())->toBe(0);

    // Notes are never blocked.
    $this->actingAs($user)->post($url, ['type' => 'note', 'body' => '<p>Checking the refund</p>'])->assertRedirect();

    $this->actingAs($user)->post($url, ['type' => 'message', 'body' => '<p>Hi Alex, we are looking into a refund and will confirm shortly.</p>', 'send_anyway' => true])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(Thread::where('conversation_id', $f['conversation']->id)->where('type', 'message')->whereNotNull('user_id')->count())->toBe(1);
});

test('once the action succeeded the draft sends normally', function () {
    Bus::fake([SendReplyJob::class, GenerateReplySuggestionJob::class]);
    $f = asaT('approval');
    asaTShopifyRefund();
    $run = asaTRun($f, PolicyGate::DRAFT);
    app(RunEffects::class)->apply($run, $f['conversation']);
    $user = adminUser($f['workspace']);
    app(ActionApprovals::class)->approve(asaTExecution($run), $user);
    expect(asaTExecution($run)->status)->toBe(ActionExecution::SUCCEEDED);

    $this->actingAs($user)->post("/conversations/{$f['conversation']->id}/threads", ['type' => 'message', 'body' => '<p>'.$run->draft.'</p>'])
        ->assertRedirect()->assertSessionHasNoErrors();
});

// ── Kill switch / pause immediately before an execution ─────────────────────

test('the kill switch flipped between queueing and execution cancels an unattended action', function () {
    Bus::fake([ExecuteActionJob::class]);
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'refund', 'always', ['max_amount' => 50]);
    asaTShopifyRefund();
    $e = actTRecord($case, 'refund', ASA_REFUND);
    expect($e->status)->toBe(ActionExecution::APPROVED);

    AgentSetting::forWorkspace($case['workspace']->id)->update(['kill_switch' => true]);
    app(ActionExecutor::class)->execute($e->id);

    expect($e->fresh()->status)->toBe(ActionExecution::CANCELLED)->and(actTSent('refundCreate'))->toBe([]);
});

test('the kill switch flipped while the executor reads the order stops the mutation', function (string $route) {
    Bus::fake([ExecuteActionJob::class]);
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'refund', $route === 'unattended' ? 'always' : 'approval', ['max_amount' => 50]);
    asaTShopifyRefund();
    $e = actTRecord($case, 'refund', ASA_REFUND);
    if ($route === 'approval') {
        expect(app(ActionApprovals::class)->approve($e, $case['admin'])['ok'])->toBeTrue();
    }
    expect($e->fresh()->status)->toBe(ActionExecution::APPROVED);

    asaTShopifyRefund(fn () => AgentSetting::where('workspace_id', $case['workspace']->id)->update(['kill_switch' => true]));
    app(ActionExecutor::class)->execute($e->id);

    expect($e->fresh()->status)->toBe(ActionExecution::CANCELLED)
        ->and($e->fresh()->error)->toContain('kill switch')
        ->and(actTSent('refundCreate'))->toBe([]);
})->with(['unattended', 'approval']);

test('pause or a mode change before execution sends an unattended action to approval', function (array $settings, string $reason) {
    Bus::fake([ExecuteActionJob::class]);
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'refund', 'always', ['max_amount' => 50]);
    asaTShopifyRefund();
    $e = actTRecord($case, 'refund', ASA_REFUND);
    expect($e->status)->toBe(ActionExecution::APPROVED);

    asaTShopifyRefund(fn () => AgentSetting::where('workspace_id', $case['workspace']->id)->update($settings));
    app(ActionExecutor::class)->execute($e->id);

    expect($e->fresh()->status)->toBe(ActionExecution::PENDING_APPROVAL)
        ->and($e->fresh()->route)->toBe(ActionExecution::ROUTE_APPROVAL)
        ->and($e->fresh()->approval_reasons)->toContain($reason)
        ->and(actTSent('refundCreate'))->toBe([]);
})->with([
    'paused' => [['paused_until' => now()->addDay()], 'autonomy_paused'],
    'mode draft' => [['mode' => 'draft'], 'agent_mode_not_live'],
]);

test('a human-approved action still runs while auto-sending is paused or the agent is in draft', function (array $settings) {
    Bus::fake([ExecuteActionJob::class]);
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'refund', 'approval', ['max_amount' => 50]);
    asaTShopifyRefund();
    $e = actTRecord($case, 'refund', ASA_REFUND);
    app(ActionApprovals::class)->approve($e, $case['admin']);

    AgentSetting::where('workspace_id', $case['workspace']->id)->update($settings);
    app(ActionExecutor::class)->execute($e->id);

    expect($e->fresh()->status)->toBe(ActionExecution::SUCCEEDED)->and(actTSent('refundCreate'))->toHaveCount(1);
})->with([
    'paused' => [['paused_until' => now()->addDay()]],
    'mode draft' => [['mode' => 'draft']],
]);
