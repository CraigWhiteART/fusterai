<?php

namespace App\Domains\Agent\Proposals;

use App\Domains\Agent\Models\AgentExample;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\KnowledgeItem;
use App\Domains\Agent\Models\Playbook;

/**
 * One hash of everything a proposal is written against: the live voice
 * version, each playbook's live version, and the content + status of every
 * knowledge item and example. A proposal whose base hash no longer matches
 * is stale (the live config moved underneath it). Embedding refreshes do not
 * change it: only ids, statuses, scopes, windows and text hashes count.
 */
class ConfigFingerprint
{
    public function forWorkspace(int $workspaceId): string
    {
        return hash('sha256', (string) json_encode($this->parts($workspaceId)));
    }

    /** @return array<string, mixed> */
    public function parts(int $workspaceId): array
    {
        $playbooks = Playbook::where('workspace_id', $workspaceId)
            ->orderBy('id')
            ->get(['id', 'slug', 'live_version_id', 'archived_at'])
            ->map(fn (Playbook $p) => [$p->id, $p->slug, $p->live_version_id, $p->archived_at !== null])
            ->all();

        $knowledge = KnowledgeItem::where('workspace_id', $workspaceId)
            ->selectRaw("count(*) as n, coalesce(md5(string_agg(id::text || ':' || status || ':' || md5(coalesce(title, '') || body) || ':' || scope::text || ':' || coalesce(valid_from::text, '') || ':' || coalesce(valid_until::text, ''), ',' order by id)), '') as h")
            ->first();

        $examples = AgentExample::where('workspace_id', $workspaceId)
            ->selectRaw("count(*) as n, coalesce(md5(string_agg(id::text || ':' || status || ':' || md5(reply), ',' order by id)), '') as h")
            ->first();

        return [
            'voice' => AgentSetting::forWorkspace($workspaceId)->live_voice_version_id,
            'playbooks' => $playbooks,
            'knowledge' => [(int) ($knowledge->n ?? 0), (string) ($knowledge->h ?? '')],
            'examples' => [(int) ($examples->n ?? 0), (string) ($examples->h ?? '')],
        ];
    }
}
