<?php

namespace App\Domains\Agent\Http\Controllers;

use App\Domains\Agent\Reporting\PerformanceReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** "AI performance" report (JSON + CSV, manage-settings). docs/agent-qa.md "Report". */
class ReportController extends AgentController
{
    public const MAX_DAYS = 400;

    public function __construct(private readonly PerformanceReport $report) {}

    public function performance(Request $request): JsonResponse
    {
        [$ws, $from, $to, $group, $playbook] = $this->input($request);

        return response()->json($this->report->build($ws, $from, $to, $group, $playbook));
    }

    public function csv(Request $request): StreamedResponse
    {
        [$ws, $from, $to, $group, $playbook] = $this->input($request);
        $data = $this->report->build($ws, $from, $to, $group, $playbook);
        $name = 'ai-performance-'.$from->toDateString().'-to-'.$to->toDateString().'.csv';

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fputcsv($out, self::COLUMNS);
            foreach ($data['periods'] as $row) {
                fputcsv($out, self::csvRow('period', $row));
            }
            fputcsv($out, self::csvRow('total', $data['totals']));
            foreach ($data['playbooks'] as $row) {
                fputcsv($out, self::csvRow('playbook', $row));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public const COLUMNS = [
        'section', 'period_start', 'playbook', 'conversations_received', 'drafts_produced', 'conversations_with_draft', 'conversations_sent_as_is',
        'automation_rate', 'auto_sent', 'auto_sent_rate', 'handovers', 'top_handover_reasons', 'sent_as_is', 'sent_edited', 'gmail_reply', 'rejected',
        'edit_rate', 'avg_percent_changed', 'gmail_reply_rate', 'qa_ai_reviewed', 'qa_ai_avg', 'qa_ai_edited_reviewed', 'qa_ai_edited_avg',
        'qa_human_reviewed', 'qa_human_avg', 'qa_ai_triage_avg', 'qa_human_triage_avg', 'failure_classes', 'cost_usd', 'cost_by_role',
        'cost_by_purpose', 'resolved_conversations', 'cost_per_resolved_usd', 'time_to_first_draft_avg_s', 'time_to_first_draft_median_s',
        'auto_send_decisions', 'actions_proposed', 'actions_approved', 'actions_unattended', 'actions_succeeded', 'actions_failed', 'actions_rejected',
        'refunded_amount',
    ];

    /**
     * @param  array<string, mixed>  $r
     * @return list<string|int|float|null>
     */
    public static function csvRow(string $section, array $r): array
    {
        $qa = $r['qa']['by_author_type'];
        $pairs = fn (array $map) => implode('; ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($map), $map));

        return [
            $section,
            $r['period_start'] ?? '',
            $section === 'playbook' ? ($r['playbook_slug'] ?? '(none)') : '',
            $r['conversations_received'],
            $r['drafts_produced'],
            $r['conversations_with_draft'],
            $r['conversations_sent_as_is'],
            $r['automation_rate'],
            $r['auto_sent'],
            $r['auto_sent_rate'],
            $r['handovers'],
            implode('; ', array_map(fn ($x) => "{$x['reason']}={$x['count']}", array_slice($r['handover_reasons'], 0, 5))),
            $r['feedback']['sent_as_is'],
            $r['feedback']['sent_edited'],
            $r['feedback']['gmail_reply'],
            $r['feedback']['rejected'],
            $r['edit_rate'],
            $r['avg_percent_changed'],
            $r['gmail_reply_rate'],
            $qa['ai']['reviewed'], $qa['ai']['review_avg'],
            $qa['ai_edited']['reviewed'], $qa['ai_edited']['review_avg'],
            $qa['human']['reviewed'], $qa['human']['review_avg'],
            $qa['ai']['triage_avg'], $qa['human']['triage_avg'],
            $pairs(array_filter($r['qa']['failure_classes'])),
            $r['cost']['total_usd'],
            $pairs($r['cost']['by_role']),
            $pairs($r['cost']['by_purpose']),
            $r['resolved_conversations'],
            $r['cost_per_resolved_usd'],
            $r['time_to_first_draft']['avg_seconds'],
            $r['time_to_first_draft']['median_seconds'],
            $r['auto_send_decisions'],
            $r['actions']['proposed'],
            $r['actions']['approved'],
            $r['actions']['unattended'],
            $r['actions']['succeeded'],
            $r['actions']['failed'],
            $r['actions']['rejected'],
            $r['actions']['refunded_amount'],
        ];
    }

    /** @return array{0: int, 1: Carbon, 2: Carbon, 3: string, 4: int|null} */
    private function input(Request $request): array
    {
        $ws = $this->workspaceFor($request);
        $f = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'group' => ['nullable', Rule::in(PerformanceReport::GROUPS)],
            'playbook_id' => ['nullable', 'integer'],
        ]);
        $to = isset($f['to']) ? Carbon::parse($f['to'])->endOfDay() : now()->endOfDay();
        $from = isset($f['from']) ? Carbon::parse($f['from'])->startOfDay() : $to->copy()->subDays(29)->startOfDay();
        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }
        if ($from->diffInDays($to) > self::MAX_DAYS) {
            $from = $to->copy()->subDays(self::MAX_DAYS)->startOfDay();
        }

        return [$ws, $from, $to, $f['group'] ?? 'day', isset($f['playbook_id']) ? (int) $f['playbook_id'] : null];
    }
}
