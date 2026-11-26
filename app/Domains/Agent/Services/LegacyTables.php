<?php

namespace App\Domains\Agent\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The Commerce Assist tables that were imported into the agent tables
 * (LegacyImporter) and are then dropped. Used by the migration
 * 2026_12_02_000000_drop_commerce_assist_tables and by
 * `php artisan agent:import-legacy --drop-old-tables` (the retry path when
 * the migration refused to drop).
 *
 * commerce_assist_settings / _shopify_snapshots / _tracking_snapshots are not
 * here: they were renamed to commerce_* (2026_11_27_000001) and are live.
 */
final class LegacyTables
{
    /** Drop order (children first). */
    public const TABLES = [
        'commerce_assist_history_learnings',
        'commerce_assist_history_scans',
        'commerce_assist_replay_runs',
        'commerce_assist_approved_responses',
        'commerce_assist_generations',
        'commerce_assist_live_facts',
        'commerce_assist_intents',
    ];

    /**
     * Rows that were never copied into the agent tables, per workspace:
     * approved responses without an agent_examples row, live facts without an
     * agent_knowledge_items row, and workspaces with saved intents but no
     * playbooks. Empty = safe to drop.
     *
     * @return array<int, array<string, int>> workspace id => {kind => count}
     */
    public static function unimported(): array
    {
        $missing = [];
        $add = function (iterable $rows, string $kind) use (&$missing): void {
            foreach ($rows as $row) {
                $missing[(int) $row->workspace_id][$kind] = (int) $row->n;
            }
        };

        if (Schema::hasTable('commerce_assist_approved_responses')) {
            $add(DB::table('commerce_assist_approved_responses as r')
                ->whereNotExists(fn ($q) => $q->from('agent_examples as e')->whereColumn('e.legacy_approved_response_id', 'r.id'))
                ->selectRaw('r.workspace_id, count(*) as n')->groupBy('r.workspace_id')->get(), 'approved_responses');
        }
        if (Schema::hasTable('commerce_assist_live_facts')) {
            $add(DB::table('commerce_assist_live_facts as f')
                ->whereNotExists(fn ($q) => $q->from('agent_knowledge_items as k')->where('k.source_type', 'live_fact')->whereColumn('k.source_id', 'f.id'))
                ->selectRaw('f.workspace_id, count(*) as n')->groupBy('f.workspace_id')->get(), 'live_facts');
        }
        if (Schema::hasTable('commerce_assist_intents')) {
            $add(DB::table('commerce_assist_intents as i')
                ->whereNotExists(fn ($q) => $q->from('agent_playbooks as p')->whereColumn('p.workspace_id', 'i.workspace_id'))
                ->selectRaw('i.workspace_id, count(*) as n')->groupBy('i.workspace_id')->get(), 'intents');
        }

        ksort($missing);

        return $missing;
    }

    /** True when every old table is gone. */
    public static function dropped(): bool
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Drop the old tables, but only when nothing is left un-imported.
     * Returns the un-imported rows that stopped it (empty = dropped).
     *
     * @return array<int, array<string, int>>
     */
    public static function dropIfImported(): array
    {
        $missing = self::unimported();
        if ($missing !== []) {
            Log::critical('Commerce Assist tables NOT dropped: some rows were never imported into the agent tables. Run `php artisan agent:import-legacy`, check the result, then `php artisan agent:import-legacy --drop-old-tables`.', [
                'unimported' => $missing,
            ]);

            return $missing;
        }

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
        DB::table('modules')->where('alias', 'CommerceAssist')->delete();

        return [];
    }
}
