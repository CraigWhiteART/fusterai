<?php

namespace App\Domains\Agent\Manager\Tools;

use App\Domains\Agent\Models\Playbook;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class ListPlaybooksTool extends ManagerTool
{
    public function name(): string
    {
        return 'list_playbooks';
    }

    public function description(): string
    {
        return 'List the playbooks (situations the agent handles): id, slug, name, mode, rollout, always_human, live version and rule count.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'include_archived' => $schema->boolean()->description('Also list archived playbooks'),
        ];
    }

    protected function label(array $arguments): string
    {
        return 'Listing playbooks';
    }

    protected function run(array $arguments): string
    {
        $query = Playbook::with('liveVersion')->where('workspace_id', $this->session->workspaceId)->orderBy('slug');
        if (! ($arguments['include_archived'] ?? false)) {
            $query->whereNull('archived_at');
        }

        return self::json(['playbooks' => $query->get()->map(fn (Playbook $p) => [
            'id' => $p->id,
            'slug' => $p->slug,
            'name' => $p->name,
            'mode' => $p->mode,
            'rollout_percent' => $p->rollout_percent,
            'always_human' => (bool) $p->always_human,
            'archived' => $p->archived_at !== null,
            'live_version' => $p->liveVersion?->version,
            'rule_count' => count((array) ($p->liveVersion->rules ?? [])),
            'when' => self::cut($p->liveVersion?->when_text, 300),
        ])->values()->all()]);
    }
}
