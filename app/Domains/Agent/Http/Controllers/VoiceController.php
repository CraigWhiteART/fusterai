<?php

namespace App\Domains\Agent\Http\Controllers;

use App\Domains\Agent\AutoSend\AutoModeGuard;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\VoiceVersion;
use App\Domains\Agent\Services\VersionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VoiceController extends AgentController
{
    public function index(Request $request): Response
    {
        $workspaceId = $this->workspaceFor($request);
        $liveId = AgentSetting::forWorkspace($workspaceId)->live_voice_version_id;

        $versions = VoiceVersion::with('creator')
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('version')
            ->get();

        return Inertia::render('Agent/Voice', [
            'live' => $versions->firstWhere('id', $liveId)?->toUiArray(),
            'versions' => $versions->map(fn (VoiceVersion $v) => [
                ...$v->toUiArray(),
                'is_live' => $v->id === $liveId,
            ])->values(),
        ]);
    }

    public function store(Request $request, VersionService $versions): RedirectResponse
    {
        $workspaceId = $this->workspaceFor($request);
        $data = $request->validate(self::rules());

        $voice = $versions->createVoiceVersion($workspaceId, $data, (int) $request->user()->id, $data['change_note'] ?? null);
        app(AutoModeGuard::class)->afterVoiceChanged($workspaceId, "voice version {$voice->version} saved");

        return back()->with('success', "Voice version {$voice->version} is live.");
    }

    public function restore(Request $request, VoiceVersion $version, VersionService $versions): RedirectResponse
    {
        $workspaceId = $this->workspaceFor($request);
        $this->ensureWorkspace($version, $workspaceId);

        $voice = $versions->restoreVoice($version, (int) $request->user()->id);
        app(AutoModeGuard::class)->afterVoiceChanged($workspaceId, "voice version {$version->version} restored");

        return back()->with('success', "Restored version {$version->version} as version {$voice->version}.");
    }

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        $list = fn (string $field) => [
            $field => ['nullable', 'array', 'max:50'],
            "{$field}.*.id" => ['nullable', 'string', 'max:64'],
            "{$field}.*.text" => ['required', 'string', 'max:500'],
        ];

        return [
            'personality' => ['required', 'string', 'max:5000'],
            ...$list('do_phrases'),
            ...$list('dont_phrases'),
            ...$list('greetings'),
            ...$list('sign_offs'),
            'max_words' => ['nullable', 'integer', 'min:20', 'max:2000'],
            'length_guidance' => ['nullable', 'string', 'max:1000'],
            'change_note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
