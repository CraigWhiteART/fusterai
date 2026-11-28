<?php

namespace App\Domains\Agent\Console;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Support\Categories;
use App\Enums\ConversationStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill conversations.agent_category for open and pending conversations
 * from the playbook of their latest completed live run. No AI calls; safe to
 * run again (only rows whose category would change are written).
 * Conversations the agent never ran on are left alone (the inbox shows them
 * under "Other").
 */
class CategoriseCommand extends Command
{
    protected $signature = 'agent:categorise
        {--workspace= : Only this workspace id (default: all)}
        {--dry-run : Show what would change without writing}';

    protected $description = 'Set the inbox category of open and pending conversations from their latest agent run';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $workspaceId = $this->option('workspace');

        $rows = DB::table('conversations as c')
            ->join('agent_runs as r', fn ($join) => $join->whereRaw(
                'r.id = (select max(x.id) from agent_runs x where x.conversation_id = c.id and x.purpose = ? and x.status = ?)',
                [AgentRun::PURPOSE_LIVE, AgentRun::STATUS_COMPLETED],
            ))
            ->leftJoin('agent_playbooks as p', 'p.id', '=', 'r.playbook_id')
            ->whereIn('c.status', [ConversationStatus::Open->value, ConversationStatus::Pending->value])
            ->whereNull('c.deleted_at')
            ->when($workspaceId !== null, fn ($query) => $query->where('c.workspace_id', (int) $workspaceId))
            ->orderBy('c.id')
            ->select(['c.id', 'c.agent_category', 'p.slug'])
            ->selectRaw("r.triage ->> 'category' as triage_category")
            ->get();

        $totals = array_fill_keys(Categories::keys(), 0);
        $unmapped = [];
        $changed = 0;

        foreach ($rows as $row) {
            $slug = $row->slug !== null ? (string) $row->slug : null;
            $category = Categories::resolve($slug, $row->triage_category);
            $totals[$category]++;
            if ($slug !== null && ! Categories::knowsPlaybook($slug)) {
                $unmapped[$slug] = ($unmapped[$slug] ?? 0) + 1;
            }
            if ($row->agent_category === $category) {
                continue;
            }

            $changed++;
            if (! $dryRun) {
                // A plain update: no model events, and updated_at stays as it was.
                DB::table('conversations')->where('id', $row->id)->update(['agent_category' => $category]);
            }
        }

        $labels = Categories::labels();
        $this->table(['Category', 'Conversations'], array_map(
            fn (string $key) => [$labels[$key], $totals[$key]],
            array_keys($totals),
        ));
        foreach ($unmapped as $slug => $count) {
            $this->warn("Playbook \"{$slug}\" is not in config/agent.php playbook_categories ({$count} conversations).");
        }
        $this->info($rows->count().' open or pending conversations with an agent run checked, '.$changed.($dryRun ? ' would change (dry run, nothing written).' : ' updated.'));

        return self::SUCCESS;
    }
}
