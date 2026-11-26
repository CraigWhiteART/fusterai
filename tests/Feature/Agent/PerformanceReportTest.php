<?php

require_once __DIR__.'/AgentTestHelpers.php';
require_once __DIR__.'/LoopTestHelpers.php';

use App\Domains\Agent\Http\Controllers\OverviewController;
use App\Domains\Agent\Manager\Models\ManagerChat;
use App\Domains\Agent\Manager\Models\ManagerMessage;
use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\FeedbackEvent;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Qa\Models\QaReview;
use App\Domains\Agent\Reporting\PerformanceReport;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use Illuminate\Support\Carbon;

/*
 * Report maths against fixed fixtures (two ISO weeks, Mon 2026-11-02 ..
 * Sun 2026-11-15). Every expected number is derived by hand in the
 * comments; imported history must never count.
 */

/** @return array<string, mixed> */
function rptTFixture(): array
{
    $f = loopTWorkspace();
    $ws = $f['workspace']->id;
    $order = $f['playbook'];
    $refund = Playbook::where('workspace_id', $ws)->where('slug', 'refund_request')->sole();

    $conv = function (string $created, string $updated, string $status = 'open', bool $imported = false) use ($f) {
        return Conversation::factory()->create(['workspace_id' => $f['workspace']->id, 'mailbox_id' => $f['mailbox']->id, 'customer_id' => $f['customer']->id,
            'status' => $status, 'imported' => $imported, 'created_at' => Carbon::parse($created), 'updated_at' => Carbon::parse($updated)]);
    };
    $msg = fn (Conversation $c, string $at, bool $customer = true, array $extra = []) => Thread::factory()->create(array_merge([
        'conversation_id' => $c->id, 'customer_id' => $customer ? $f['customer']->id : null, 'user_id' => null, 'type' => 'message',
        'body' => 'x', 'body_plain' => 'x', 'created_at' => Carbon::parse($at), 'sent_at' => Carbon::parse($at),
    ], $extra));
    $run = fn (Conversation $c, ?Thread $t, ?Playbook $p, string $decision, string $created, ?string $completed, array $extra = []) => AgentRun::query()->forceCreate(array_merge([
        'workspace_id' => $f['workspace']->id, 'purpose' => 'live', 'conversation_id' => $c->id, 'customer_thread_id' => $t?->id,
        'idempotency_key' => 'k'.uniqid('', true), 'status' => 'completed', 'config' => [], 'config_hash' => str_repeat('a', 64),
        'playbook_id' => $p?->id, 'decision' => $decision, 'draft' => $decision === 'draft' ? 'Draft' : null,
        'created_at' => Carbon::parse($created), 'updated_at' => Carbon::parse($created), 'completed_at' => $completed !== null ? Carbon::parse($completed) : null,
    ], $extra));
    $feedback = fn (AgentRun $r, string $kind, string $at, ?int $pct = null) => FeedbackEvent::query()->forceCreate([
        'workspace_id' => $f['workspace']->id, 'run_id' => $r->id, 'conversation_id' => $r->conversation_id, 'kind' => $kind,
        'percent_changed' => $pct, 'created_at' => Carbon::parse($at), 'updated_at' => Carbon::parse($at),
    ]);
    $qa = fn (Conversation $c, Thread $t, string $author, string $status, ?int $triage, ?string $overall, string $replied, array $extra = []) => QaReview::query()->forceCreate(array_merge([
        'workspace_id' => $f['workspace']->id, 'conversation_id' => $c->id, 'reviewed_thread_id' => $t->id, 'author_type' => $author, 'status' => $status,
        'triage_score' => $triage, 'overall' => $overall, 'reviewer_prompt_version' => 'qa-v1', 'replied_at' => Carbon::parse($replied),
        'created_at' => Carbon::parse($replied), 'updated_at' => Carbon::parse($replied),
    ], $extra));

    // Week 1 (2026-11-02): c1, c2 received.
    $c1 = $conv('2026-11-03 10:00', '2026-11-05 09:00', 'closed');      // resolved in week 1
    $c2 = $conv('2026-11-03 12:00', '2026-11-04 12:00');
    $t1 = $msg($c1, '2026-11-03 10:00');
    $t2 = $msg($c2, '2026-11-03 12:00');
    $t2b = $msg($c2, '2026-11-04 08:00');
    $r1 = $run($c1, $t1, $order, 'draft', '2026-11-03 10:05', '2026-11-03 10:05:30', ['cost_micro_usd' => 1300,   // ttfd 330s
        'trace' => ['usage' => [['step' => 'triage', 'cost_micro_usd' => 100], ['step' => 'writer', 'cost_micro_usd' => 1000], ['step' => 'validator', 'cost_micro_usd' => 200]]]]);
    $r2 = $run($c2, $t2, $refund, 'draft', '2026-11-03 12:10', '2026-11-03 12:12', ['cost_micro_usd' => 2000]);   // ttfd 720s, no trace => unattributed
    $run($c2, $t2b, $refund, 'handover', '2026-11-04 08:01', '2026-11-04 08:02', ['handover_reason' => 'missing_evidence:order']);
    $feedback($r1, 'sent_as_is', '2026-11-03 10:20', 0);
    $feedback($r2, 'sent_edited', '2026-11-03 12:30', 40);
    $s1 = $msg($c1, '2026-11-03 10:20', false);
    $s2 = $msg($c2, '2026-11-03 12:30', false);
    $qa($c1, $s1, 'ai', 'reviewed', 5, '4.00', '2026-11-03 10:20', ['playbook_id' => $order->id, 'failure_class' => 'none', 'triage_cost_micro_usd' => 100, 'review_cost_micro_usd' => 500]);
    $qa($c2, $s2, 'human', 'reviewed', 2, '2.00', '2026-11-03 12:30', ['playbook_id' => $refund->id, 'failure_class' => 'human_error']);
    $chat = ManagerChat::create(['workspace_id' => $ws]);
    ManagerMessage::query()->forceCreate(['chat_id' => $chat->id, 'workspace_id' => $ws, 'role' => 'assistant', 'status' => 'completed', 'cost_micro_usd' => 3100,
        'created_at' => Carbon::parse('2026-11-04 15:00'), 'updated_at' => Carbon::parse('2026-11-04 15:00')]);

    // Week 2 (2026-11-09): c3 received, drafted, answered from Gmail, auto-sent once.
    $c3 = $conv('2026-11-10 08:59', '2026-11-12 10:00', 'closed');
    $t3 = $msg($c3, '2026-11-10 08:59');
    $r4 = $run($c3, $t3, $order, 'draft', '2026-11-10 09:00', '2026-11-10 09:01');   // ttfd 120s
    $feedback($r4, 'gmail_reply', '2026-11-10 09:30');
    $s3 = $msg($c3, '2026-11-10 09:30', false, ['origin' => 'gmail']);
    $qa($c3, $s3, 'human', 'triaged', 4, null, '2026-11-10 09:30', ['playbook_id' => $order->id]);
    $qa($c3, $t3, 'human', 'reviewed', 1, '1.00', '2026-11-10 09:30', ['failure_class' => 'human_error', 'human_verdict' => 'incorrect']);   // dismissed: excluded
    $msg($c3, '2026-11-11 10:00', false, ['origin' => 'ai']);

    // Imported history (must not count anywhere): received, drafted, sent as-is, reviewed.
    $c4 = $conv('2026-11-03 09:00', '2026-11-03 09:00', 'closed', true);
    $t4 = $msg($c4, '2026-11-03 09:00');
    $r5 = $run($c4, $t4, $order, 'draft', '2026-11-03 09:01', '2026-11-03 09:02');
    $feedback($r5, 'sent_as_is', '2026-11-03 09:10', 0);
    $qa($c4, $msg($c4, '2026-11-03 09:10', false), 'ai', 'reviewed', 1, '1.00', '2026-11-03 09:10', ['failure_class' => 'agent_error']);
    // Spam conversation: not "received".
    $conv('2026-11-03 11:00', '2026-11-03 11:00', 'spam');

    return [...$f, 'order' => $order, 'refund' => $refund];
}

function rptTBuild(array $f, string $group = 'week', ?int $playbook = null): array
{
    return app(PerformanceReport::class)->build($f['workspace']->id, Carbon::parse('2026-11-02')->startOfDay(), Carbon::parse('2026-11-15')->endOfDay(), $group, $playbook);
}

test('weekly report maths', function (int $week, string $path, mixed $expected) {
    $report = rptTBuild(rptTFixture());

    expect($report['periods'])->toHaveCount(2)
        ->and(data_get($report['periods'][$week], $path))->toBe($expected);
})->with([
    'w1 start' => [0, 'period_start', '2026-11-02'],
    'w1 received (imported + spam excluded)' => [0, 'conversations_received', 2],
    'w1 drafts' => [0, 'drafts_produced', 2],
    'w1 handovers' => [0, 'handovers', 1],
    'w1 handover reason' => [0, 'handover_reasons.0.reason', 'missing_evidence'],
    'w1 with draft' => [0, 'conversations_with_draft', 2],
    'w1 sent as-is' => [0, 'conversations_sent_as_is', 1],
    'w1 automation rate' => [0, 'automation_rate', 0.5],
    'w1 auto-sent' => [0, 'auto_sent', 0],
    'w1 auto-sent rate' => [0, 'auto_sent_rate', 0.0],
    'w1 edit rate' => [0, 'edit_rate', 0.5],
    'w1 avg changed' => [0, 'avg_percent_changed', 40],
    'w1 gmail rate' => [0, 'gmail_reply_rate', 0.0],
    'w1 qa ai avg' => [0, 'qa.by_author_type.ai.review_avg', 4.0],
    'w1 qa ai triage' => [0, 'qa.by_author_type.ai.triage_avg', 5.0],
    'w1 qa human avg' => [0, 'qa.by_author_type.human.review_avg', 2.0],
    'w1 failure classes' => [0, 'qa.failure_classes.human_error', 1],
    'w1 imported qa excluded' => [0, 'qa.failure_classes.agent_error', 0],
    'w1 cost total' => [0, 'cost.total_micro_usd', 1300 + 2000 + 100 + 500 + 3100],
    'w1 cost writer' => [0, 'cost.by_role.writer', 0.001],
    'w1 cost triage (run + qa)' => [0, 'cost.by_role.triage', 0.0002],
    'w1 cost unattributed' => [0, 'cost.by_role.unattributed', 0.002],
    'w1 cost judge' => [0, 'cost.by_role.simulation_judge', 0.0005],
    'w1 cost manager' => [0, 'cost.by_role.manager', 0.0031],
    'w1 cost live' => [0, 'cost.by_purpose.live', 0.0033],
    'w1 cost qa' => [0, 'cost.by_purpose.qa', 0.0006],
    'w1 cost manager chat' => [0, 'cost.by_purpose.manager_chat', 0.0031],
    'w1 resolved' => [0, 'resolved_conversations', 1],
    'w1 cost per resolved' => [0, 'cost_per_resolved_usd', 0.007],
    'w1 live cost per resolved' => [0, 'live_cost_per_resolved_usd', 0.0033],
    'w1 ttfd count' => [0, 'time_to_first_draft.count', 2],
    'w1 ttfd avg' => [0, 'time_to_first_draft.avg_seconds', 525],
    'w1 ttfd median' => [0, 'time_to_first_draft.median_seconds', 525],
    'w2 received' => [1, 'conversations_received', 1],
    'w2 automation rate' => [1, 'automation_rate', 0.0],
    'w2 auto-sent' => [1, 'auto_sent', 1],
    'w2 auto-sent rate' => [1, 'auto_sent_rate', 1.0],
    'w2 edit rate (none)' => [1, 'edit_rate', null],
    'w2 gmail rate' => [1, 'gmail_reply_rate', 1.0],
    'w2 qa human triage only' => [1, 'qa.by_author_type.human.triage_avg', 4.0],
    'w2 qa human reviewed (dismissed excluded)' => [1, 'qa.by_author_type.human.reviewed', 0],
    'w2 resolved' => [1, 'resolved_conversations', 1],
    'w2 cost per resolved' => [1, 'cost_per_resolved_usd', 0.0],
    'w2 ttfd' => [1, 'time_to_first_draft.avg_seconds', 120],
]);

test('totals, per-playbook rows, daily buckets and the playbook filter', function () {
    $f = rptTFixture();
    $report = rptTBuild($f);
    $order = collect($report['playbooks'])->firstWhere('playbook_slug', 'order_status');
    $refund = collect($report['playbooks'])->firstWhere('playbook_slug', 'refund_request');

    expect($report['totals']['conversations_received'])->toBe(3)
        ->and($report['totals']['drafts_produced'])->toBe(3)
        ->and($report['totals']['automation_rate'])->toBe(0.3333)
        ->and($report['totals']['feedback'])->toBe(['sent_as_is' => 1, 'sent_edited' => 1, 'gmail_reply' => 1, 'rejected' => 0])
        ->and($report['totals']['time_to_first_draft']['median_seconds'])->toBe(330)
        ->and($order['drafts_produced'])->toBe(2)
        ->and($order['automation_rate'])->toBe(0.5)
        ->and($order['conversations_received'])->toBeNull()
        ->and($order['qa']['by_author_type']['ai']['review_avg'])->toBe(4.0)
        ->and($order['cost']['total_micro_usd'])->toBe(1300 + 600)
        ->and($refund['handovers'])->toBe(1)
        ->and($refund['edit_rate'])->toBe(1.0);

    $daily = rptTBuild($f, 'day');
    expect($daily['periods'])->toHaveCount(14)
        ->and($daily['periods'][1]['period_start'])->toBe('2026-11-03')
        ->and($daily['periods'][1]['drafts_produced'])->toBe(2)
        ->and($daily['periods'][2]['handovers'])->toBe(1);

    $filtered = rptTBuild($f, 'week', $f['refund']->id);
    expect($filtered['totals']['drafts_produced'])->toBe(1)
        ->and($filtered['totals']['conversations_received'])->toBeNull()
        ->and($filtered['totals']['cost']['by_purpose'])->toBe(['live' => 0.002]);
});

test('the report API and CSV export are admin-only', function () {
    $f = rptTFixture();
    $admin = adminUser($f['workspace']);
    $agent = agentUser($f['workspace']);

    $this->actingAs($agent)->getJson('/agent/reports/performance')->assertForbidden();
    $this->actingAs($admin)->getJson('/agent/reports/performance?from=2026-11-02&to=2026-11-15&group=week')->assertOk()
        ->assertJsonPath('group', 'week')->assertJsonCount(2, 'periods')->assertJsonPath('totals.drafts_produced', 3)
        ->assertJsonStructure(['definitions' => ['automation_rate', 'cost', 'time_to_first_draft']]);

    $csv = $this->actingAs($admin)->get('/agent/reports/performance.csv?from=2026-11-02&to=2026-11-15&group=week')->assertOk();
    $lines = array_values(array_filter(explode("\n", $csv->streamedContent())));
    expect($lines[0])->toStartWith('section,period_start,playbook,conversations_received')
        ->and($lines[1])->toStartWith('period,2026-11-02,,2,2,2,1,0.5,0,0,1,missing_evidence=1,1,1,0,0,0.5,40')
        ->and($lines[3])->toStartWith('total,,,3,3')
        ->and(collect($lines)->filter(fn ($l) => str_starts_with($l, 'playbook,'))->count())->toBe(2);
    expect($csv->headers->get('content-type'))->toContain('text/csv');
});

test('the overview summary still works on plain aggregate rows', function () {
    $f = rptTFixture();
    $summary = OverviewController::summary($f['workspace']->id, Carbon::parse('2026-11-05 12:00'));

    expect($summary['runs']['draft'])->toBeGreaterThanOrEqual(2)
        ->and($summary['feedback']['sent_edited'])->toBe(1)
        ->and($summary['cost']['by_purpose'][0]['purpose'])->toBe('live');
});
