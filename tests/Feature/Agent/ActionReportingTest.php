<?php

require_once __DIR__.'/AgentTestHelpers.php';
require_once __DIR__.'/LoopTestHelpers.php';

use App\Domains\Agent\Http\Controllers\OverviewController;
use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Reporting\PerformanceReport;
use App\Domains\Commerce\Actions\Models\ActionExecution;
use Illuminate\Support\Carbon;

/*
 * Overview + performance report count auto-send decisions and Shopify action
 * executions (live only; dry runs and other workspaces never count).
 */

function arptTFixture(): array
{
    $f = loopTWorkspace();
    $ws = $f['workspace']->id;
    $at = Carbon::parse('2026-11-10 10:00');

    foreach (['auto_send', 'auto_send', 'draft', 'handover'] as $i => $decision) {
        AgentRun::query()->forceCreate([
            'workspace_id' => $ws, 'purpose' => 'live', 'conversation_id' => $f['conversation']->id, 'customer_thread_id' => $f['thread']->id,
            'idempotency_key' => "arpt-{$i}", 'status' => 'completed', 'config' => [], 'config_hash' => str_repeat('a', 64),
            'playbook_id' => $f['playbook']->id, 'decision' => $decision, 'draft' => $decision === 'handover' ? null : 'Draft',
            'created_at' => $at, 'updated_at' => $at, 'completed_at' => $at,
        ]);
    }

    $exec = fn (string $key, string $status, array $extra = []) => ActionExecution::query()->forceCreate(array_merge([
        'workspace_id' => $ws, 'conversation_id' => $f['conversation']->id, 'playbook_id' => $f['playbook']->id, 'purpose' => 'live',
        'action_key' => $key, 'params' => [], 'status' => $status, 'idempotency_key' => 'e'.uniqid('', true),
        'created_at' => $at, 'updated_at' => $at,
    ], $extra));
    $exec('refund', 'succeeded', ['amount' => '20.00', 'currency' => 'AUD', 'approved_at' => $at, 'route' => 'unattended']);
    $exec('refund', 'succeeded', ['amount' => '15.50', 'currency' => 'AUD', 'approved_at' => $at, 'route' => 'approval']);
    $exec('refund', 'failed', ['amount' => '99.00', 'currency' => 'AUD', 'approved_at' => $at, 'route' => 'approval']);
    $exec('add_order_tag', 'rejected', ['route' => 'approval']);
    $exec('add_order_note', 'pending_approval', ['route' => 'approval']);
    // Never counted: a dry run and a non-live purpose.
    $exec('refund', 'cancelled', ['amount' => '50.00', 'dry_run' => true, 'purpose' => 'simulation']);

    return $f;
}

test('the overview counts auto-send decisions and action executions', function () {
    $f = arptTFixture();

    $summary = OverviewController::summary($f['workspace']->id, Carbon::parse('2026-11-12 12:00'));

    expect($summary['runs']['auto_send'])->toBe(2)
        ->and($summary['runs']['draft'])->toBe(1)
        ->and($summary['runs']['handover'])->toBe(1)
        ->and(collect($summary['runs']['daily'])->firstWhere('date', '2026-11-10')['auto_send'])->toBe(2)
        ->and($summary['actions'])->toBe([
            'proposed' => 5, 'approved' => 3, 'unattended' => 1, 'succeeded' => 2, 'failed' => 1, 'rejected' => 1,
            'refunded_amount' => 35.5, 'refunded_currency' => 'AUD',
        ]);
});

test('the performance report counts auto-send decisions and actions per period and playbook', function () {
    $f = arptTFixture();

    $report = app(PerformanceReport::class)->build($f['workspace']->id, Carbon::parse('2026-11-09'), Carbon::parse('2026-11-15')->endOfDay());

    $expected = ['proposed' => 5, 'approved' => 3, 'unattended' => 1, 'succeeded' => 2, 'failed' => 1, 'rejected' => 1, 'refunded_amount' => 35.5];
    $day = collect($report['periods'])->firstWhere('period_start', '2026-11-10');
    $playbook = collect($report['playbooks'])->firstWhere('playbook_id', $f['playbook']->id);

    expect($report['totals']['auto_send_decisions'])->toBe(2)
        ->and($report['totals']['actions'])->toEqual($expected)
        ->and($day['actions'])->toEqual($expected)
        ->and($day['auto_send_decisions'])->toBe(2)
        ->and($playbook['actions']['succeeded'])->toBe(2)
        ->and($report['definitions'])->toHaveKeys(['auto_send_decisions', 'actions']);

    $empty = collect($report['periods'])->firstWhere('period_start', '2026-11-11');
    expect($empty['actions']['proposed'])->toBe(0)->and($empty['auto_send_decisions'])->toBe(0);
});

test('the report CSV carries the new columns at the end', function () {
    $f = arptTFixture();
    $csv = $this->actingAs(adminUser($f['workspace']))->get('/agent/reports/performance.csv?from=2026-11-09&to=2026-11-15')->assertOk()->streamedContent();
    $rows = array_map('str_getcsv', array_values(array_filter(explode("\n", $csv))));
    $header = $rows[0];
    $total = collect($rows)->first(fn ($r) => $r[0] === 'total');

    expect(array_slice($header, -8))->toBe(['auto_send_decisions', 'actions_proposed', 'actions_approved', 'actions_unattended', 'actions_succeeded', 'actions_failed', 'actions_rejected', 'refunded_amount'])
        ->and($total[array_search('actions_succeeded', $header, true)])->toBe('2')
        ->and($total[array_search('refunded_amount', $header, true)])->toBe('35.5');
});
