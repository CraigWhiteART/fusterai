<?php

namespace App\Domains\Agent\Manager\Tools;

use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\VoiceVersion;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class GetVoiceTool extends ManagerTool
{
    public function name(): string
    {
        return 'get_voice';
    }

    public function description(): string
    {
        return "The live brand voice: personality, do / don't phrases, greetings, sign-offs (each with an id for replace/remove ops), max words, length guidance, and recent versions.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function label(array $arguments): string
    {
        return 'Reading the brand voice';
    }

    protected function run(array $arguments): string
    {
        $liveId = AgentSetting::forWorkspace($this->session->workspaceId)->live_voice_version_id;
        $voice = $liveId !== null ? VoiceVersion::where('workspace_id', $this->session->workspaceId)->find($liveId) : null;
        if ($voice === null) {
            return 'This workspace has no live voice yet.';
        }
        $this->session->cite('voice', $voice->id, "Brand voice v{$voice->version}");

        return self::json([
            'version_id' => $voice->id,
            'version' => $voice->version,
            'personality' => $voice->personality,
            'do_phrases' => (array) ($voice->do_phrases ?? []),
            'dont_phrases' => (array) ($voice->dont_phrases ?? []),
            'greetings' => (array) ($voice->greetings ?? []),
            'sign_offs' => (array) ($voice->sign_offs ?? []),
            'max_words' => $voice->max_words,
            'length_guidance' => $voice->length_guidance,
            'versions' => VoiceVersion::where('workspace_id', $this->session->workspaceId)->orderByDesc('version')->limit(8)
                ->get(['id', 'version', 'change_note', 'created_at'])
                ->map(fn (VoiceVersion $v) => ['id' => $v->id, 'version' => $v->version, 'change_note' => self::cut($v->change_note, 200), 'created_at' => $v->created_at?->toDateString()])->all(),
        ]);
    }
}
