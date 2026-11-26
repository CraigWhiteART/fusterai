<?php

namespace App\Domains\Agent\Http\Controllers;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\FeedbackEvent;
use App\Domains\Agent\Models\KnowledgeItem;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Pipeline\HandoverReasons;
use App\Domains\Commerce\Actions\ActionStats;
use App\Domains\Commerce\Models\CommerceSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /agent: read-only summary of the agent for admins (mode, instructions,
 * the last 7 days of runs, how drafts were used, and spend). Every number is
 * a plain aggregate over agent_runs / agent_feedback_events for the workspace.
 */
class OverviewController extends AgentController
{
    public const DAYS = 7;

    public function show(Request $request): Response
    {
        $workspaceId = $this->workspaceFor($request);

        return Inertia::render('Agent/Overview', self::summary($workspaceId, now()));
    }

    /** @return array<string, mixed> */
    public static function summary(int $workspaceId, Carbon $now): array
    {
        $since = $now->copy()->startOfDay()->subDays(self::DAYS - 1);
        $settings = AgentSetting::forWorkspace($workspaceId);
        $commerce = CommerceSetting::forWorkspace($workspaceId);

        $playbooks = Playbook::where('workspace_id', $workspaceId)->whereNull('archived_at');
        $knowledge = KnowledgeItem::where('workspace_id', $workspaceId)
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        // Runs that answered real conversations (live drafts and shadow runs).
        $runs = AgentRun::where('workspace_id', $workspaceId)
            ->whereIn('purpose', [AgentRun::PURPOSE_LIVE, AgentRun::PURPOSE_SHADOW])
            ->where('created_at', '>=', $since);

        $byDecision = (clone $runs)
            ->selectRaw("purpose, coalesce(decision, 'none') as decision, status, count(*) as n")
            ->groupBy('purpose', 'decision', 'status')
            ->toBase()->get();

        $count = fn (?string $purpose, ?string $decision = null, ?string $status = null) => (int) $byDecision
            ->filter(fn ($row) => ($purpose === null || $row->purpose === $purpose)
                && ($decision === null || $row->decision === $decision)
                && ($status === null || $row->status === $status))
            ->sum('n');

        $reasons = (clone $runs)
            ->where('decision', 'handover')
            ->whereNotNull('handover_reason')
            ->selectRaw('handover_reason, count(*) as n')
            ->groupBy('handover_reason')
            ->orderByDesc('n')
            ->limit(5)
            ->toBase()->get()
            ->map(fn ($row) => [
                'reason' => (string) $row->handover_reason,
                'label' => HandoverReasons::label((string) $row->handover_reason),
                'count' => (int) $row->n,
            ])
            ->values()
            ->all();

        $daily = (clone $runs)
            ->whereIn('decision', ['draft', 'handover', 'auto_send'])
            ->selectRaw('DATE(created_at) as day, decision, count(*) as n')
            ->groupByRaw('DATE(created_at), decision')
            ->toBase()->get();
        $days = [];
        for ($i = 0; $i < self::DAYS; $i++) {
            $day = $since->copy()->addDays($i)->toDateString();
            $days[] = [
                'date' => $day,
                'draft' => (int) $daily->filter(fn ($r) => substr((string) $r->day, 0, 10) === $day && $r->decision === 'draft')->sum('n'),
                'handover' => (int) $daily->filter(fn ($r) => substr((string) $r->day, 0, 10) === $day && $r->decision === 'handover')->sum('n'),
                'auto_send' => (int) $daily->filter(fn ($r) => substr((string) $r->day, 0, 10) === $day && $r->decision === 'auto_send')->sum('n'),
            ];
        }

        $feedback = FeedbackEvent::where('workspace_id', $workspaceId)
            ->where('created_at', '>=', $since)
            ->selectRaw('kind, count(*) as n, avg(percent_changed) as avg_changed')
            ->groupBy('kind')
            ->toBase()->get()
            ->keyBy('kind');
        $sentAsIs = (int) ($feedback[FeedbackEvent::KIND_SENT_AS_IS]->n ?? 0);
        $sentEdited = (int) ($feedback[FeedbackEvent::KIND_SENT_EDITED]->n ?? 0);
        $gmail = (int) ($feedback[FeedbackEvent::KIND_GMAIL_REPLY]->n ?? 0);
        $sentFromDraft = $sentAsIs + $sentEdited;

        $cost = AgentRun::where('workspace_id', $workspaceId)
            ->where('created_at', '>=', $since)
            ->selectRaw('purpose, sum(cost_micro_usd) as micro, count(*) as n')
            ->groupBy('purpose')
            ->toBase()->get();
        $totalMicro = (int) $cost->sum('micro');

        return [
            'days' => self::DAYS,
            'since' => $since->toDateString(),
            'settings' => [
                'mode' => $settings->mode,
                'kill_switch' => (bool) $settings->kill_switch,
            ],
            'connections' => [
                'shopify' => $commerce->hasShopifyCredentials(),
                'tracking' => $commerce->trackingEnabled(),
            ],
            'counts' => [
                'playbooks_active' => (clone $playbooks)->where('mode', '!=', Playbook::MODE_OFF)->count(),
                'playbooks_total' => (clone $playbooks)->count(),
                'knowledge_active' => (int) ($knowledge[KnowledgeItem::STATUS_ACTIVE] ?? 0),
                'knowledge_total' => (int) $knowledge->sum(),
                'knowledge_needs_scope' => (int) ($knowledge[KnowledgeItem::STATUS_NEEDS_SCOPE] ?? 0),
            ],
            'runs' => [
                'total' => $count(null),
                'live' => $count(AgentRun::PURPOSE_LIVE),
                'shadow' => $count(AgentRun::PURPOSE_SHADOW),
                'draft' => $count(null, 'draft'),
                'handover' => $count(null, 'handover'),
                // Runs the gate decided to auto-send (some may later have been cancelled).
                'auto_send' => $count(null, 'auto_send'),
                'failed' => $count(null, null, AgentRun::STATUS_FAILED),
                'top_handover_reasons' => $reasons,
                'daily' => $days,
            ],
            'feedback' => [
                'sent_as_is' => $sentAsIs,
                'sent_edited' => $sentEdited,
                'gmail_reply' => $gmail,
                'rejected' => (int) ($feedback[FeedbackEvent::KIND_REJECTED]->n ?? 0),
                // Of the drafts sent from fusterai, the share a person changed first.
                // Replies written in Gmail are reported separately (the draft was not used there).
                'edit_rate' => $sentFromDraft > 0 ? round($sentEdited / $sentFromDraft, 3) : null,
                'avg_percent_changed' => isset($feedback[FeedbackEvent::KIND_SENT_EDITED])
                    ? (int) round((float) $feedback[FeedbackEvent::KIND_SENT_EDITED]->avg_changed)
                    : null,
            ],
            // Shopify actions proposed in the period (live only): docs/agent-actions.md.
            'actions' => ActionStats::summary($workspaceId, $since, $now),
            'cost' => [
                'total_usd' => round($totalMicro / 1_000_000, 4),
                'per_run_usd' => $cost->sum('n') > 0 ? round($totalMicro / 1_000_000 / (int) $cost->sum('n'), 4) : null,
                'by_purpose' => $cost->map(fn ($row) => [
                    'purpose' => (string) $row->purpose,
                    'runs' => (int) $row->n,
                    'usd' => round(((int) $row->micro) / 1_000_000, 4),
                ])->sortByDesc('usd')->values()->all(),
            ],
        ];
    }
}
