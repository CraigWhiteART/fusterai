<?php

namespace App\Domains\Commerce\Actions;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Commerce\Actions\Models\ActionExecution as E;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Action-execution counts for the overview and the performance report.
 * Live executions only (dry runs of previews / simulations never count),
 * anchored on when the action was proposed (created_at).
 *
 *   proposed   every recorded execution
 *   approved   approved by a person or routed unattended (approved_at set)
 *   unattended ran without a person (route unattended)
 *   succeeded / failed / rejected   current status (`unknown` is its own
 *              state and is not counted as failed)
 *   refunded_amount  sum of `amount` on succeeded refund / cancel-order
 *              executions (the refunded money); refunded_currency is the
 *              single currency involved, "mixed" when several, null when none
 */
final class ActionStats
{
    public const MONEY_BACK = ['refund', 'cancel_order'];

    /** SELECT list shared by summary() and the report's bucketed query. */
    public static function selectSql(): string
    {
        $money = "'".implode("','", self::MONEY_BACK)."'";

        return 'count(*) as proposed,
            count(*) filter (where e.approved_at is not null) as approved,
            count(*) filter (where e.route = \''.E::ROUTE_UNATTENDED.'\' and e.approved_at is not null) as unattended,
            count(*) filter (where e.status = \''.E::SUCCEEDED.'\') as succeeded,
            count(*) filter (where e.status = \''.E::FAILED.'\') as failed,
            count(*) filter (where e.status = \''.E::REJECTED.'\') as rejected,
            coalesce(sum(e.amount) filter (where e.status = \''.E::SUCCEEDED."' and e.action_key in ({$money})), 0) as refunded_amount";
    }

    public static function query(int $workspaceId, Carbon $from, Carbon $to, ?int $playbookId = null): Builder
    {
        return DB::table('agent_action_executions as e')
            ->where('e.workspace_id', $workspaceId)
            ->where('e.purpose', AgentRun::PURPOSE_LIVE)
            ->where('e.dry_run', false)
            ->whereBetween('e.created_at', [$from, $to])
            ->when($playbookId !== null, fn ($q) => $q->where('e.playbook_id', $playbookId));
    }

    /** @return array{proposed: int, approved: int, unattended: int, succeeded: int, failed: int, rejected: int, refunded_amount: float, refunded_currency: string|null} */
    public static function summary(int $workspaceId, Carbon $from, Carbon $to, ?int $playbookId = null): array
    {
        $row = self::query($workspaceId, $from, $to, $playbookId)->selectRaw(self::selectSql())->first();
        $currencies = self::query($workspaceId, $from, $to, $playbookId)
            ->where('e.status', E::SUCCEEDED)->whereIn('e.action_key', self::MONEY_BACK)->whereNotNull('e.currency')
            ->distinct()->pluck('e.currency');

        return [
            'proposed' => (int) ($row->proposed ?? 0),
            'approved' => (int) ($row->approved ?? 0),
            'unattended' => (int) ($row->unattended ?? 0),
            'succeeded' => (int) ($row->succeeded ?? 0),
            'failed' => (int) ($row->failed ?? 0),
            'rejected' => (int) ($row->rejected ?? 0),
            'refunded_amount' => round((float) ($row->refunded_amount ?? 0), 2),
            'refunded_currency' => match ($currencies->count()) {
                0 => null,
                1 => (string) $currencies->first(),
                default => 'mixed',
            },
        ];
    }
}
