<?php

namespace App\Domains\Agent\Services;

use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Models\VoiceVersion;

/**
 * Makes sure a workspace has agent settings, a voice and a playbook
 * catalogue. Idempotent and cheap after the first call per process.
 */
class AgentBootstrapper
{
    /** @var array<int, true> */
    private array $done = [];

    public function __construct(
        private readonly VersionService $versions,
        private readonly LegacyImporter $importer,
    ) {}

    public function ensureForWorkspace(int $workspaceId): void
    {
        if (isset($this->done[$workspaceId])) {
            return;
        }

        $settings = AgentSetting::forWorkspace($workspaceId);

        if ($settings->live_voice_version_id === null && ! VoiceVersion::where('workspace_id', $workspaceId)->exists()) {
            $this->versions->createVoiceVersion($workspaceId, StarterContent::voice(), null, 'Starter voice: edit to match your team', isStarter: true);
        }

        if (! Playbook::where('workspace_id', $workspaceId)->exists()) {
            $this->importer->importPlaybooks($workspaceId);
        }

        $this->done[$workspaceId] = true;
    }

    public function forget(): void
    {
        $this->done = [];
    }
}
