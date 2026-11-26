<?php

namespace App\Domains\Agent\Http\Controllers;

use App\Domains\Agent\AutoSend\AutoModeGuard;
use App\Domains\Agent\AutoSend\AutoSendAudit;
use App\Domains\Agent\AutoSend\AutoSendService;
use App\Domains\Agent\AutoSend\CircuitBreaker;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Models\PlaybookVersion;
use App\Domains\Agent\Services\VersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PlaybookController extends AgentController
{
    public function index(Request $request): Response
    {
        $workspaceId = $this->workspaceFor($request);

        $playbooks = Playbook::with('liveVersion')
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('priority')
            ->orderBy('slug')
            ->get()
            ->map(fn (Playbook $p) => [
                ...$p->toSummaryArray(),
                'when_text' => $p->liveVersion?->when_text,
                'rule_count' => count($p->liveVersion->rules ?? []),
            ])->values();

        return Inertia::render('Agent/Playbooks/Index', ['playbooks' => $playbooks]);
    }

    /**
     * Small JSON list for filters and pickers (test cases, simulations, the
     * feedback box target). Any signed-in user of the workspace: names only.
     */
    public function list(Request $request): JsonResponse
    {
        $data = $request->validate(['include_archived' => ['nullable', 'boolean']]);

        $playbooks = Playbook::where('workspace_id', (int) $request->user()->workspace_id)
            ->when(! (bool) ($data['include_archived'] ?? false), fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('name')
            ->get(['id', 'slug', 'name', 'mode', 'archived_at'])
            ->map(fn (Playbook $p) => ['id' => $p->id, 'slug' => $p->slug, 'name' => $p->name, 'mode' => $p->mode, 'archived' => $p->archived_at !== null])
            ->values();

        return response()->json(['data' => $playbooks]);
    }

    public function create(Request $request): Response
    {
        $this->workspaceFor($request);

        return Inertia::render('Agent/Playbooks/Edit', [
            'playbook' => null,
            'live' => null,
            'versions' => [],
            ...$this->options(),
        ]);
    }

    public function store(Request $request, VersionService $versions): RedirectResponse
    {
        $workspaceId = $this->workspaceFor($request);

        $data = $request->validate([
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_.-]*$/'],
            ...self::settingsRules(required: true),
            ...self::contentRules(),
        ]);

        $slug = filled($data['slug'] ?? null) ? (string) $data['slug'] : Str::slug((string) $data['name'], '_');
        if ($slug === '' || Playbook::where('workspace_id', $workspaceId)->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['slug' => "A playbook with slug \"{$slug}\" already exists (or the slug is empty)."]);
        }

        $playbook = $versions->createPlaybook($workspaceId, $slug, $this->safeSettings($data), $data, (int) $request->user()->id, $data['change_note'] ?? 'Created');

        return redirect()->route('agent.playbooks.show', $playbook)->with('success', 'Playbook created.');
    }

    public function show(Request $request, Playbook $playbook, AutoModeGuard $guard, CircuitBreaker $breakers): Response
    {
        $workspaceId = $this->workspaceFor($request);
        $this->ensureWorkspace($playbook, $workspaceId);

        $playbook->load(['liveVersion', 'versions.creator']);

        return Inertia::render('Agent/Playbooks/Edit', [
            'playbook' => $playbook->toSummaryArray(),
            'live' => $playbook->liveVersion?->toUiArray(),
            'versions' => $playbook->versions->map(fn (PlaybookVersion $v) => [
                ...$v->toUiArray(),
                'is_live' => $v->id === $playbook->live_version_id,
            ])->values(),
            ...$this->options(),
            // Phase 8: preconditions checklist, breakers, history, rollout preview.
            'auto_send' => AutoSendController::playbookPayload($playbook, $guard, $breakers),
        ]);
    }

    public function updateSettings(Request $request, Playbook $playbook, VersionService $versions, AutoModeGuard $guard, AutoSendService $autoSend): RedirectResponse
    {
        $workspaceId = $this->workspaceFor($request);
        $this->ensureWorkspace($playbook, $workspaceId);

        $data = $request->validate([...self::settingsRules(required: false, allowAuto: true), 'confirm_auto' => ['sometimes', 'boolean']]);
        $settings = $this->safeSettings($data);
        $userId = (int) $request->user()->id;
        $wasAuto = $playbook->mode === Playbook::MODE_AUTO;
        $before = ['mode' => $playbook->mode, 'rollout_percent' => $playbook->rollout_percent];

        // Turning auto on goes through the preconditions + explicit confirmation.
        $enableAuto = ($settings['mode'] ?? null) === Playbook::MODE_AUTO && ! $wasAuto;
        if ($enableAuto) {
            unset($settings['mode']);
        }
        DB::transaction(function () use ($versions, $playbook, $settings, $enableAuto, $guard, $userId, $data) {
            $versions->updatePlaybookSettings($playbook, $settings);
            if ($enableAuto) {
                // Throws (422) when a precondition fails: nothing is saved.
                $guard->enable($playbook->refresh(), $userId, (bool) ($data['confirm_auto'] ?? false));
            }
        });

        $playbook->refresh();
        if ($wasAuto && $playbook->mode !== Playbook::MODE_AUTO) {
            AutoSendAudit::record('playbook_auto_disabled', "Playbook \"{$playbook->name}\" switched from auto to {$playbook->mode} by a team member", $playbook, null, ['playbook_id' => $playbook->id], $userId);
            $autoSend->cancelPending((int) $playbook->workspace_id, 'playbook_mode', playbookId: $playbook->id, userId: $userId);
        } elseif ($playbook->mode === Playbook::MODE_AUTO && $before['rollout_percent'] !== $playbook->rollout_percent) {
            AutoSendAudit::record('playbook_rollout_changed', "Playbook \"{$playbook->name}\" rollout {$before['rollout_percent']}% -> {$playbook->rollout_percent}%", $playbook, null, [
                'playbook_id' => $playbook->id, 'from' => $before['rollout_percent'], 'to' => $playbook->rollout_percent,
            ], $userId);
        }

        return back()->with('success', 'Playbook settings saved.');
    }

    public function storeVersion(Request $request, Playbook $playbook, VersionService $versions): RedirectResponse
    {
        $workspaceId = $this->workspaceFor($request);
        $this->ensureWorkspace($playbook, $workspaceId);

        $data = $request->validate(self::contentRules());
        $version = $versions->createPlaybookVersion($playbook, $data, (int) $request->user()->id, $data['change_note'] ?? null);
        // An auto playbook's new version is held and re-tested before it auto-sends again.
        app(AutoModeGuard::class)->afterVersionChanged($playbook, "version {$version->version} saved");

        return back()->with('success', "Version {$version->version} is live.");
    }

    public function restore(Request $request, Playbook $playbook, PlaybookVersion $version, VersionService $versions): RedirectResponse
    {
        $workspaceId = $this->workspaceFor($request);
        $this->ensureWorkspace($playbook, $workspaceId);
        abort_unless($version->playbook_id === $playbook->id, 404);

        $restored = $versions->restorePlaybook($playbook, $version, (int) $request->user()->id);
        app(AutoModeGuard::class)->afterVersionChanged($playbook, "version {$version->version} restored");

        return back()->with('success', "Restored version {$version->version} as version {$restored->version}.");
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function safeSettings(array $data): array
    {
        return array_intersect_key($data, array_flip(['name', 'mode', 'rollout_percent', 'always_human', 'priority']));
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'modes' => Playbook::MODES,
            // Phase 8: `auto` can be chosen once the playbook meets the preconditions.
            'auto_mode_available' => true,
            'actions' => config('agent.actions'),
            'evidence_kinds' => ['order', 'tracking', 'photos'],
        ];
    }

    /** @return array<string, mixed> */
    public static function settingsRules(bool $required, bool $allowAuto = false): array
    {
        $req = $required ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:120'],
            // `auto` only on update, through AutoModeGuard (a new playbook starts off / draft).
            'mode' => [$req, Rule::in($allowAuto ? Playbook::MODES : [Playbook::MODE_OFF, Playbook::MODE_DRAFT])],
            'rollout_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'always_human' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:-1000', 'max:1000'],
        ];
    }

    /** @return array<string, mixed> */
    public static function contentRules(): array
    {
        return [
            'when_text' => ['required', 'string', 'max:2000'],
            'rules' => ['nullable', 'array', 'max:60'],
            'rules.*.id' => ['nullable', 'string', 'max:64'],
            'rules.*.if' => ['nullable', 'string', 'max:500'],
            'rules.*.then' => ['required', 'string', 'max:2000'],
            'steps' => ['nullable', 'array', 'max:30'],
            'steps.*.id' => ['nullable', 'string', 'max:64'],
            'steps.*.text' => ['required', 'string', 'max:1000'],
            'allowed_actions' => ['nullable', 'array'],
            'allowed_actions.*' => ['string', Rule::in(array_keys((array) config('agent.actions', [])))],
            'required_evidence' => ['nullable', 'array'],
            'required_evidence.*' => ['string', Rule::in(['order', 'tracking', 'photos'])],
            'escalation' => ['nullable', 'string', 'max:2000'],
            'change_note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
