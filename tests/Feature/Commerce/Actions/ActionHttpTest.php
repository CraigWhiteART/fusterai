<?php

require_once __DIR__.'/ActionTestHelpers.php';

use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Pipeline\DeterministicValidator;
use App\Domains\Agent\Pipeline\EvidenceLedger;
use App\Domains\Agent\Pipeline\InstructionSet;
use App\Domains\Agent\Pipeline\PipelineInput;
use App\Domains\Agent\Pipeline\PlaybookSpec;
use App\Domains\Agent\Pipeline\RunEffects;
use App\Domains\Agent\Pipeline\UntrustedData;
use App\Domains\Agent\Pipeline\VoiceSpec;
use App\Domains\Agent\Pipeline\WriterSession;
use App\Domains\Agent\Proposals\OpsApplier;
use App\Domains\Agent\Tools\ProposeActionTool;
use App\Domains\Commerce\Actions\ActionApprovals;
use App\Domains\Commerce\Actions\ActionOutcomeEvidence;
use App\Domains\Commerce\Actions\Models\ActionExecution;
use App\Domains\Commerce\Actions\Models\ActionPolicy;
use App\Domains\Commerce\Actions\Models\ActionSettings;
use App\Domains\Commerce\Actions\Support\ActionClaims;
use App\Domains\Commerce\Actions\Support\Redactor;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;

function actTPendingRefund(array $case): ActionExecution
{
    return ActionExecution::create([
        'workspace_id' => $case['workspace']->id, 'conversation_id' => $case['conversation']->id, 'purpose' => 'live',
        'action_key' => 'refund', 'params' => ['amount' => '20.00', 'line_items' => [], 'notify_customer' => true, 'reason' => 'abc', 'shipping' => 'none', 'shipping_amount' => null],
        'status' => 'pending_approval', 'route' => 'approval', 'order_number' => 'SD8421', 'amount' => '20.00', 'currency' => 'AUD',
        'customer_email' => 'buyer@example.com', 'idempotency_key' => 'h-'.uniqid(), 'description' => '=HYPERLINK("x")',
    ]);
}

test('guests get 401 on every action route', function () {
    foreach ([
        ['get', '/agent/actions/catalogue'], ['get', '/agent/actions/settings'], ['patch', '/agent/actions/settings'],
        ['get', '/agent/actions/policies'], ['put', '/agent/actions/policies/refund'], ['delete', '/agent/actions/policies/1'],
        ['get', '/agent/actions/executions'], ['get', '/agent/actions/executions/1'], ['post', '/agent/actions/executions/1/approve'],
        ['post', '/agent/actions/executions/1/reject'], ['post', '/agent/actions/executions/1/refresh'], ['get', '/agent/actions/audit'],
        ['get', '/agent/actions/conversations/1'], ['get', '/agent/actions/runs/1/gate'],
    ] as [$method, $url]) {
        $this->{$method.'Json'}($url)->assertUnauthorized();
    }
});

test('only admins read and change policies and settings', function () {
    $case = actTCase();

    foreach (['agent', 'manager'] as $role) {
        $this->actingAs($case[$role])->getJson('/agent/actions/policies')->assertForbidden();
        $this->actingAs($case[$role])->putJson('/agent/actions/policies/refund', ['mode' => 'always'])->assertForbidden();
        $this->actingAs($case[$role])->getJson('/agent/actions/settings')->assertForbidden();
        $this->actingAs($case[$role])->patchJson('/agent/actions/settings', ['approver_role_money' => 'agent'])->assertForbidden();
        $this->actingAs($case[$role])->getJson('/agent/actions/audit')->assertForbidden();
    }
    expect(ActionPolicy::count())->toBe(0);

    $this->actingAs($case['admin'])->putJson('/agent/actions/policies/refund', ['mode' => 'approval', 'limits' => ['max_amount' => 40, 'allowed_countries' => ['au']]])
        ->assertOk()->assertJsonPath('effective.mode', 'approval')->assertJsonPath('effective.limits.allowed_countries', ['AU']);
    $this->actingAs($case['admin'])->getJson('/agent/actions/policies')->assertOk()->assertJsonPath('policies.4.action_key', 'refund');
    $this->actingAs($case['admin'])->patchJson('/agent/actions/settings', ['approver_role_money' => 'manager', 'refund_hard_ceiling' => 80])
        ->assertOk()->assertJsonPath('settings.approver_role_money', 'manager');

    // Another workspace's playbook cannot be targeted.
    $other = actTCase();
    $playbook = Playbook::create(['workspace_id' => $other['workspace']->id, 'slug' => 'x', 'name' => 'X', 'mode' => 'draft']);
    $this->actingAs($case['admin'])->putJson('/agent/actions/policies/refund', ['mode' => 'always', 'playbook_id' => $playbook->id])->assertNotFound();
    $this->actingAs($case['admin'])->putJson('/agent/actions/policies/not_an_action', ['mode' => 'always'])->assertNotFound();
});

test('approving money actions needs the configured role; other actions any staff', function () {
    $case = actTCase();
    Queue::fake();
    $money = actTPendingRefund($case);
    $note = ActionExecution::create(['workspace_id' => $case['workspace']->id, 'conversation_id' => $case['conversation']->id, 'action_key' => 'add_order_note', 'params' => ['note' => 'hi there'], 'status' => 'pending_approval', 'idempotency_key' => 'n1']);

    $this->actingAs($case['agent'])->postJson("/agent/actions/executions/{$money->id}/approve")->assertForbidden();
    $this->actingAs($case['manager'])->postJson("/agent/actions/executions/{$money->id}/reject", ['reason' => 'no'])->assertForbidden();
    $this->actingAs($case['agent'])->getJson("/agent/actions/executions/{$money->id}")->assertOk()->assertJsonPath('execution.can_approve', false)->assertJsonPath('execution.required_role', 'admin');
    $this->actingAs($case['agent'])->postJson("/agent/actions/executions/{$note->id}/approve")->assertStatus(202);

    ActionSettings::forWorkspace($case['workspace']->id)->update(['approver_role_money' => 'manager']);
    $this->actingAs($case['manager'])->postJson("/agent/actions/executions/{$money->id}/approve")->assertStatus(202);
    expect($money->fresh()->status)->toBe('approved')->and($money->fresh()->approved_by)->toBe($case['manager']->id);
});

test('executions in another workspace are invisible', function () {
    $case = actTCase();
    $other = actTCase();
    $e = actTPendingRefund($other);

    $this->actingAs($case['admin'])->getJson("/agent/actions/executions/{$e->id}")->assertNotFound();
    $this->actingAs($case['admin'])->postJson("/agent/actions/executions/{$e->id}/approve")->assertNotFound();
    $this->actingAs($case['admin'])->getJson('/agent/actions/executions')->assertOk()->assertJsonPath('meta.total', 0);
    $this->actingAs($case['admin'])->getJson("/agent/actions/conversations/{$other['conversation']->id}")->assertForbidden();
});

test('the queue lists pending approvals and the conversation endpoint shows reply state', function () {
    $case = actTCase();
    actTPendingRefund($case);

    $this->actingAs($case['admin'])->getJson('/agent/actions/executions')->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('counts.pending_approval', 1)->assertJsonPath('data.0.can_approve', true);
    $this->actingAs($case['admin'])->getJson('/agent/actions/executions?status=succeeded')->assertOk()->assertJsonPath('meta.total', 0);

    $run = actTRun($case, [['action' => 'refund', 'params' => []]]);
    $this->actingAs($case['agent'])->getJson("/agent/actions/conversations/{$case['conversation']->id}")->assertOk()
        ->assertJsonPath('latest_run.id', $run->id)->assertJsonPath('latest_run.blocks_sending', true);
    $this->actingAs($case['agent'])->getJson("/agent/actions/runs/{$run->id}/gate")->assertOk()->assertJsonPath('reply.state', 'waiting');
});

test('rejecting needs a reason and records feedback', function () {
    $case = actTCase();
    $e = actTPendingRefund($case);

    $this->actingAs($case['admin'])->postJson("/agent/actions/executions/{$e->id}/reject", [])->assertStatus(422);
    $this->actingAs($case['admin'])->postJson("/agent/actions/executions/{$e->id}/reject", ['reason' => 'Not eligible'])->assertOk()->assertJsonPath('execution.status', 'rejected');
    $this->actingAs($case['admin'])->postJson("/agent/actions/executions/{$e->id}/reject", ['reason' => 'again'])->assertStatus(409);
});

test('approving with edited params re-validates them', function () {
    $case = actTCase();
    Queue::fake();
    $e = actTPendingRefund($case);

    $this->actingAs($case['admin'])->postJson("/agent/actions/executions/{$e->id}/approve", ['params' => ['amount' => '-5', 'shipping' => 'none', 'notify_customer' => true, 'reason' => 'abc']])
        ->assertStatus(422)->assertJsonStructure(['errors' => ['amount']]);
    expect($e->fresh()->status)->toBe('pending_approval');

    $this->actingAs($case['admin'])->postJson("/agent/actions/executions/{$e->id}/approve", ['params' => ['amount' => '15', 'shipping' => 'none', 'notify_customer' => false, 'reason' => 'Partial goodwill']])
        ->assertStatus(202);
    expect($e->fresh()->params['amount'])->toBe('15.00')
        ->and($e->fresh()->amount)->toBe('15.00')
        ->and($e->fresh()->params_edited['before']['amount'])->toBe('20.00');
});

test('the audit log exports CSV without formula injection', function () {
    $case = actTCase();
    actTPendingRefund($case);

    $this->actingAs($case['admin'])->getJson('/agent/actions/audit')->assertOk()->assertJsonPath('meta.total', 1);
    $csv = $this->actingAs($case['admin'])->get('/agent/actions/audit?format=csv')->assertOk()->streamedContent();

    expect($csv)->toStartWith('id,created_at,action')
        ->toContain('refund,pending_approval')
        ->toContain("'=HYPERLINK")
        ->and(substr_count(trim($csv), "\n"))->toBe(1);
});

test('no AI path can write action policies or settings', function () {
    // Only the admin controller writes ActionPolicy / ActionSettings.
    $writers = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $code = (string) file_get_contents($file->getPathname());
        if (preg_match('/ActionPolicy::(?:create|updateOrCreate|firstOrCreate|insert|upsert)|new ActionPolicy\b|ActionSettings::forWorkspace\([^)]*\)->(?:update|fill)/', $code) === 1) {
            $writers[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    expect(array_map(fn ($p) => str_replace('\\', '/', $p), $writers))->toBe(['app/Domains/Commerce/Actions/Http/Controllers/ActionPolicyController.php']);

    // Proposal ops that would change permissions are stripped (Phase 5) and never reach the policy table.
    expect(OpsApplier::class)->toBeString();
    $reflection = new ReflectionClass(OpsApplier::class);
    $source = (string) file_get_contents((string) $reflection->getFileName());
    expect($source)->toContain("'set_action_policy'")->not->toContain('ActionPolicy');
});

test('the validator rejects a claimed action nobody proposed', function () {
    $check = fn (string $draft, EvidenceLedger $ledger) => array_column(app(DeterministicValidator::class)->check($draft, [], $ledger, [], new VoiceSpec(null, 'friendly'), []), 'check');
    $empty = new EvidenceLedger;

    expect($check("I've refunded your order in full.", $empty))->toContain('claimed_action')
        ->and($check('Your order has been cancelled.', $empty))->toContain('claimed_action')
        ->and($check('I have updated your shipping address.', $empty))->toContain('claimed_action')
        ->and($check('We have sent a replacement.', $empty))->toContain('claimed_action')
        ->and($check('I can ask the team about a refund.', $empty))->not->toContain('claimed_action');

    $proposed = new EvidenceLedger;
    $proposed->add('action', 'action.proposed.change_address.1', 'Action proposed', 'Change the address');
    expect($check('I have updated your shipping address.', $proposed))->not->toContain('claimed_action')
        ->and($check("I've refunded your order.", $proposed))->toContain('claimed_action');

    $done = new EvidenceLedger;
    $done->add('action', 'action.succeeded.refund.4', 'Shopify action done', 'Refund AUD 20.00');
    expect($check("I've refunded your order.", $done))->not->toContain('claimed_action');
});

test('the redactor masks credentials by key and by shape', function () {
    $out = Redactor::redact(['headers' => ['X-Shopify-Access-Token' => 'shpat_abc'], 'client_secret' => 'x', 'body' => 'token shpca_123abc here', 'idempotency_key' => 'keep']);

    expect($out['headers']['X-Shopify-Access-Token'])->toBe('[redacted]')
        ->and($out['client_secret'])->toBe('[redacted]')
        ->and($out['body'])->toBe('token [redacted] here')
        ->and($out['idempotency_key'])->toBe('keep');
});

test('the agent cannot propose a disabled or unavailable action', function () {
    $case = actTCase(scopes: 'read_orders,write_orders');
    $session = actTSession($case, ['refund', 'discount_code']);
    $tool = new ProposeActionTool($session);
    $call = fn (array $args) => $tool->handle(new Request($args));
    $args = ['action' => 'refund', 'order_number' => 'SD8421', 'reason' => 'E1', 'details' => null, 'params' => ['amount' => '20', 'shipping' => 'none', 'notify_customer' => true, 'reason' => 'Scratched']];

    $call($args); // policy never (default)
    actTPolicy($case['workspace']->id, 'refund', 'approval');
    $call(['...' => 1] + $args);
    $call(['action' => 'discount_code'] + $args); // write_discounts missing
    $call(['params' => ['amount' => 'lots']] + $args);

    expect(array_column($session->proposedActions, 'status'))->toBe(['rejected_disabled', 'proposed', 'rejected_unavailable', 'rejected_invalid_params'])
        ->and($session->ledger->facts('action'))->toHaveCount(1)
        ->and($session->ledger->facts('action')[0]['value'])->toContain('20.00')
        ->and(ActionPolicy::count())->toBe(1)
        ->and(User::count())->toBeGreaterThan(0);
});

function actTSession(array $case, array $allowed): WriterSession
{
    $input = PipelineInput::fromConversation($case['conversation']);
    $instructions = InstructionSet::live($case['workspace']->id);
    $playbook = new PlaybookSpec(null, null, 'damaged', 'Damaged', 'draft', 0, false, 0, 'damaged item', [], [], $allowed, [], null);

    return new WriterSession($input, $instructions, $playbook, new EvidenceLedger, new UntrustedData, '', 'AU');
}

test('RunEffects records a live run\'s proposals once, alongside the draft', function () {
    $case = actTCase();
    actTPolicy($case['workspace']->id, 'add_order_note', 'approval');
    actTShopify();
    $run = actTRun($case, [['action' => 'add_order_note', 'params' => ['note' => 'Asked for a call back']], ['action' => 'refund', 'status' => 'rejected_disabled', 'params' => []]])->fresh();

    app(RunEffects::class)->apply($run, $case['conversation']);
    app(RunEffects::class)->apply($run->fresh(), $case['conversation']);

    $run->refresh();
    expect(ActionExecution::count())->toBe(1)
        ->and($run->proposed_actions[0]['execution_status'])->toBe('pending_approval')
        ->and($run->proposed_actions[1])->not->toHaveKey('execution_id')
        ->and($run->ai_suggestion_id)->not->toBeNull();
});

test('a rejected action is in the next run\'s evidence and no longer backs a claim', function () {
    $case = actTCase();
    $e = actTPendingRefund($case);
    app(ActionApprovals::class)->reject($e, $case['admin'], 'Outside the return window');

    $ledger = new EvidenceLedger;
    ActionOutcomeEvidence::addTo($ledger, $case['conversation']);

    expect($ledger->facts('action')[0]['key'])->toBe("action.rejected.refund.{$e->id}")
        ->and($ledger->facts('action')[0]['value'])->toContain('Outside the return window')
        ->and(implode(' ', $ledger->notes()))->toContain('has NOT happened')
        ->and(array_column(ActionClaims::unbacked("I've refunded your order.", $ledger), 'check'))->toBe(['claimed_action']);
});
