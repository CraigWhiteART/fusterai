<?php

require_once __DIR__.'/ActionTestHelpers.php';

use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Commerce\Actions\Models\ActionExecution;
use App\Domains\Commerce\Actions\Models\ActionPolicy;
use App\Domains\Commerce\Actions\Models\ActionSettings;
use Illuminate\Support\Carbon;

/*
 * Server-side rules the policy screen relied on the browser for, the global
 * gates / hard maximums on the settings endpoint, and the audit `to` date.
 */

test('settings return the unattended gates, the shop currency and the hard maximums', function () {
    $case = actTCase();
    AgentSetting::forWorkspace($case['workspace']->id)->update(['paused_until' => now()->addHour(), 'paused_reason' => 'Breaker']);
    ActionExecution::query()->forceCreate(['workspace_id' => $case['workspace']->id, 'action_key' => 'refund', 'params' => [], 'status' => 'succeeded', 'currency' => 'AUD', 'idempotency_key' => 'cur-1']);
    config(['agent_actions.max_refund_ceiling' => 4000, 'agent_actions.max_discount_percentage' => 40, 'agent_actions.max_discount_expiry_days' => 60]);

    $this->actingAs($case['admin'])->getJson('/agent/actions/settings')->assertOk()
        ->assertJsonStructure(['settings', 'roles'])
        ->assertJsonPath('gates.mode', 'live')
        ->assertJsonPath('gates.kill_switch', false)
        ->assertJsonPath('gates.paused_reason', 'Breaker')
        ->assertJsonPath('gates.actions_per_day', 20)
        ->assertJsonPath('shop_currency', 'AUD')
        ->assertJsonPath('hard_limits.max_refund_ceiling', 4000)
        ->assertJsonPath('hard_limits.max_discount_percentage', 40)
        ->assertJsonPath('hard_limits.max_discount_expiry_days', 60)
        ->assertJson(fn ($json) => $json->where('gates.paused_until', fn ($v) => is_string($v))->etc());
});

test('the shop currency is null until an action recorded one, and pause shows only while it lasts', function () {
    $case = actTCase();
    AgentSetting::forWorkspace($case['workspace']->id)->update(['paused_until' => now()->subHour(), 'paused_reason' => 'Old']);

    $this->actingAs($case['admin'])->getJson('/agent/actions/settings')->assertOk()
        ->assertJsonPath('shop_currency', null)
        ->assertJsonPath('gates.paused_until', null)
        ->assertJsonPath('gates.paused_reason', null);
});

test('a money action cannot be set to run on its own without its maximum', function (string $action, array $limits, string $field) {
    $case = actTCase();

    $this->actingAs($case['admin'])->putJson("/agent/actions/policies/{$action}", ['mode' => 'always', 'limits' => $limits])
        ->assertStatus(422)->assertJsonValidationErrors("limits.{$field}");
    expect(ActionPolicy::where('action_key', $action)->exists())->toBeFalse();
})->with([
    'refund' => ['refund', [], 'max_amount'],
    'cancel with refund' => ['cancel_order', ['per_day_count' => 3], 'max_amount'],
    'discount (no templates)' => ['discount_code', ['max_amount' => 20], 'max_percentage'],
]);

test('with its maximum set, or on approval, the policy saves', function () {
    $case = actTCase();
    $admin = $case['admin'];

    $this->actingAs($admin)->putJson('/agent/actions/policies/refund', ['mode' => 'always', 'limits' => ['max_amount' => 30]])->assertOk();
    $this->actingAs($admin)->putJson('/agent/actions/policies/cancel_order', ['mode' => 'approval'])->assertOk();
    $this->actingAs($admin)->putJson('/agent/actions/policies/discount_code', ['mode' => 'always', 'limits' => ['max_percentage' => 15]])->assertOk();
    // Not a money-limit action: no maximum needed.
    $this->actingAs($admin)->putJson('/agent/actions/policies/add_order_note', ['mode' => 'always'])->assertOk();
});

test('discount needs the maximum for each kind of template the shop offers', function () {
    $case = actTCase();
    ActionSettings::forWorkspace($case['workspace']->id)->update(['discount_templates' => [
        ['key' => 'sorry10', 'label' => '10%', 'type' => 'percentage', 'value' => 10, 'expires_days' => 30],
        ['key' => 'five', 'label' => '5 off', 'type' => 'fixed', 'value' => 5, 'expires_days' => 30],
    ]]);

    $this->actingAs($case['admin'])->putJson('/agent/actions/policies/discount_code', ['mode' => 'always', 'limits' => ['max_percentage' => 15]])
        ->assertStatus(422)->assertJsonValidationErrors('limits.max_amount');
    $this->actingAs($case['admin'])->putJson('/agent/actions/policies/discount_code', ['mode' => 'always', 'limits' => ['max_percentage' => 15, 'max_amount' => 10]])
        ->assertOk();
});

test('a playbook row is checked on the limits that would apply', function () {
    $case = actTCase();
    $playbook = Playbook::create(['workspace_id' => $case['workspace']->id, 'slug' => 'damaged', 'name' => 'Damaged', 'mode' => 'draft']);
    $admin = $case['admin'];

    // Overriding the shop-wide row: its own limits must have the maximum.
    $this->actingAs($admin)->putJson('/agent/actions/policies/refund', ['mode' => 'always', 'playbook_id' => $playbook->id, 'override_workspace' => true])
        ->assertStatus(422)->assertJsonValidationErrors('limits.max_amount');

    // Tightening (no override): the shop-wide maximum carries over.
    actTPolicy($case['workspace']->id, 'refund', 'always', ['max_amount' => 50]);
    $this->actingAs($admin)->putJson('/agent/actions/policies/refund', ['mode' => 'always', 'playbook_id' => $playbook->id, 'limits' => ['per_day_count' => 2]])
        ->assertOk();
});

test('the audit `to` date includes the whole day', function () {
    $case = actTCase();
    $at = Carbon::parse('2026-11-20 18:30:00');
    ActionExecution::query()->forceCreate(['workspace_id' => $case['workspace']->id, 'action_key' => 'refund', 'params' => [], 'status' => 'succeeded', 'idempotency_key' => 'aud-1', 'created_at' => $at, 'updated_at' => $at]);
    ActionExecution::query()->forceCreate(['workspace_id' => $case['workspace']->id, 'action_key' => 'refund', 'params' => [], 'status' => 'succeeded', 'idempotency_key' => 'aud-2', 'created_at' => $at->copy()->addDay(), 'updated_at' => $at]);

    $this->actingAs($case['admin'])->getJson('/agent/actions/audit?from=2026-11-20&to=2026-11-20')->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($case['admin'])->getJson('/agent/actions/audit?to='.urlencode('2026-11-20 12:00:00'))->assertOk()->assertJsonCount(0, 'data');
});
