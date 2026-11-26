<?php

use App\Domains\Agent\Pipeline\PolicyGate;

function agentTGate(array $overrides = []): array
{
    return array_replace([
        'agent_mode' => 'draft',
        'kill_switch' => false,
        'triage_failed' => false,
        'playbook' => ['mode' => 'draft', 'rollout_percent' => 0, 'always_human' => false],
        'rollout_bucket' => 50,
        'risk_flags' => [],
        'writer_handover' => null,
        'order_withheld' => false,
        'missing_evidence' => [],
        'validator_passed' => true,
        'validator_error' => false,
        'has_draft' => true,
        'proposed_actions' => [],
        'auto_send' => null,
    ], $overrides);
}

dataset('gate cases', [
    'clean draft' => [[], 'draft', null],
    'kill switch' => [['kill_switch' => true], 'handover', 'kill_switch'],
    'triage failed' => [['triage_failed' => true], 'handover', 'triage_error'],
    'no playbook' => [['playbook' => null], 'handover', 'no_instruction'],
    'risk flag' => [['risk_flags' => ['legal', 'abuse']], 'handover', 'risk:legal'],
    'always human' => [['playbook' => ['mode' => 'draft', 'rollout_percent' => 0, 'always_human' => true]], 'handover', 'always_human'],
    'playbook off' => [['playbook' => ['mode' => 'off', 'rollout_percent' => 0, 'always_human' => false]], 'handover', 'playbook_off'],
    'order withheld' => [['order_withheld' => true], 'draft', null],
    'missing evidence' => [['missing_evidence' => ['order', 'tracking']], 'draft', null],
    'writer handover' => [['writer_handover' => 'policy_unclear'], 'handover', 'writer:policy_unclear'],
    'validator error' => [['validator_error' => true, 'validator_passed' => false], 'handover', 'validator_error'],
    'validator failed' => [['validator_passed' => false], 'handover', 'validation_failed'],
    'validator not run' => [['validator_passed' => null], 'handover', 'validation_failed'],
    'empty draft' => [['has_draft' => false], 'handover', 'no_draft'],
    'kill switch beats everything' => [['kill_switch' => true, 'playbook' => null, 'risk_flags' => ['legal']], 'handover', 'kill_switch'],
    'risk beats always human' => [['risk_flags' => ['chargeback'], 'playbook' => ['mode' => 'draft', 'rollout_percent' => 0, 'always_human' => true]], 'handover', 'risk:chargeback'],
    // Without an auto-send context (preview / simulation / test runs) an otherwise eligible reply stays a draft.
    'auto inputs without context stay draft' => [[
        'agent_mode' => 'live', 'playbook' => ['mode' => 'auto', 'rollout_percent' => 100, 'always_human' => false], 'rollout_bucket' => 10,
    ], 'draft', null],
]);

test('the policy gate decides', function (array $overrides, string $decision, ?string $reason) {
    $result = PolicyGate::decide(agentTGate($overrides));

    expect($result['decision'])->toBe($decision)
        ->and($result['reason'])->toBe($reason)
        ->and($result['decision'])->not->toBe(PolicyGate::AUTO_SEND);
})->with('gate cases');

test('rollout buckets are stable and spread over 0-99', function () {
    $buckets = collect(range(1, 500))->map(fn ($id) => PolicyGate::rolloutBucket($id));

    expect(PolicyGate::rolloutBucket(42))->toBe(PolicyGate::rolloutBucket(42))
        ->and($buckets->min())->toBeGreaterThanOrEqual(0)
        ->and($buckets->max())->toBeLessThanOrEqual(99)
        ->and($buckets->unique()->count())->toBeGreaterThan(80);
});
