<?php

namespace App\Domains\Agent\Http\Controllers;

use App\Domains\Agent\AutoSend\AutoSendAudit;
use App\Domains\Agent\AutoSend\AutoSendCounts;
use App\Domains\Agent\AutoSend\AutoSendService;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Commerce\Models\CommerceSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AgentSettingsController extends AgentController
{
    public function show(Request $request): Response
    {
        $workspaceId = $this->workspaceFor($request);
        $settings = AgentSetting::forWorkspace($workspaceId);

        return Inertia::render('Agent/Settings', [
            'settings' => [
                'mode' => $settings->mode,
                'kill_switch' => $settings->kill_switch,
                'live_voice_version_id' => $settings->live_voice_version_id,
                'caps' => array_replace(AgentSetting::defaultCaps(), array_filter((array) ($settings->caps ?? []), fn ($v) => $v !== null)),
                'auto_send_delay_seconds' => $settings->auto_send_delay_seconds,
                'link_allowlist' => $settings->link_allowlist ?? [],
                // Phase 8 (docs/agent-autosend.md)
                'auto_send' => $settings->autoSendOptions(),
                'paused_until' => $settings->isPaused() ? $settings->paused_until?->toIso8601String() : null,
                'paused_reason' => $settings->isPaused() ? $settings->paused_reason : null,
            ],
            'modes' => AgentSetting::MODES,
            'default_link_domains' => array_values(array_filter([
                CommerceSetting::forWorkspace($workspaceId)->shopDomain(),
                ...(array) config('agent.carrier_domains', []),
            ])),
            'autonomy_enforced' => true,
            'auto_send_status' => [
                'auto_playbooks' => Playbook::where('workspace_id', $workspaceId)->where('mode', Playbook::MODE_AUTO)->whereNull('archived_at')
                    ->orderBy('name')->get(['id', 'slug', 'name', 'rollout_percent', 'auto_hold'])
                    ->map(fn (Playbook $p) => ['id' => $p->id, 'slug' => $p->slug, 'name' => $p->name, 'rollout_percent' => $p->rollout_percent, 'auto_hold' => $p->auto_hold])->values(),
                'sent_today' => AutoSendCounts::today($workspaceId),
                'last_hour' => AutoSendCounts::lastHour($workspaceId),
                'pending' => AutoSendService::pendingQuery($workspaceId)->count(),
                'max_per_hour' => (int) config('agent.auto_send.max_per_hour', 10),
                'pause_hours' => (int) config('agent.auto_send.pause_hours', 24),
            ],
            'language_options' => ['en', 'de', 'fr', 'es', 'it', 'nl', 'pt', 'sv', 'da', 'no', 'fi', 'pl', 'ja', 'zh'],
            'channel_options' => ['email', 'chat', 'whatsapp'],
        ]);
    }

    public function update(Request $request, AutoSendService $autoSend): RedirectResponse
    {
        $workspaceId = $this->workspaceFor($request);

        $data = $request->validate([
            'mode' => ['required', Rule::in(AgentSetting::MODES)],
            'kill_switch' => ['required', 'boolean'],
            'confirm_live' => ['sometimes', 'boolean'],
            'auto_send_delay_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'caps' => ['nullable', 'array'],
            'caps.auto_send_per_day' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'caps.auto_send_per_customer_per_day' => ['nullable', 'integer', 'min:0', 'max:100'],
            'caps.auto_send_consecutive_per_conversation' => ['nullable', 'integer', 'min:0', 'max:20'],
            'caps.actions_per_day' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'link_allowlist' => ['nullable', 'array', 'max:50'],
            'link_allowlist.*' => ['string', 'max:253', 'regex:/^(?!-)[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/'],
            'auto_send' => ['nullable', 'array'],
            'auto_send.allowed_languages' => ['nullable', 'array', 'max:30'],
            'auto_send.allowed_languages.*' => ['string', 'regex:/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/i'],
            'auto_send.channels' => ['nullable', 'array'],
            'auto_send.channels.*' => ['string', Rule::in(['email', 'chat', 'whatsapp'])],
            'auto_send.disclosure_line' => ['nullable', 'string', 'max:300'],
            'auto_send.status_after_send' => ['nullable', Rule::in(['pending', 'open'])],
        ]);

        $settings = AgentSetting::forWorkspace($workspaceId);
        $before = ['mode' => $settings->mode, 'kill_switch' => $settings->kill_switch];

        if ($data['mode'] === AgentSetting::MODE_LIVE && $settings->mode !== AgentSetting::MODE_LIVE && ! ($data['confirm_live'] ?? false)) {
            throw ValidationException::withMessages(['confirm_live' => 'Confirm that the agent may send replies for auto playbooks without review.']);
        }

        $options = $settings->autoSendOptions();
        $incoming = (array) ($data['auto_send'] ?? []);
        $settings->fill([
            'mode' => $data['mode'],
            'kill_switch' => (bool) $data['kill_switch'],
            'auto_send_delay_seconds' => (int) ($data['auto_send_delay_seconds'] ?? $settings->auto_send_delay_seconds),
            'caps' => array_map('intval', array_replace(AgentSetting::defaultCaps(), array_filter((array) ($settings->caps ?? []), fn ($v) => $v !== null), array_filter((array) ($data['caps'] ?? []), fn ($v) => $v !== null))),
            'link_allowlist' => array_values(array_unique(array_map('strtolower', (array) ($data['link_allowlist'] ?? $settings->link_allowlist ?? [])))),
            'auto_send' => [
                'allowed_languages' => array_key_exists('allowed_languages', $incoming) ? array_values(array_unique(array_map('strtolower', (array) $incoming['allowed_languages']))) : $options['allowed_languages'],
                'channels' => array_key_exists('channels', $incoming) ? array_values(array_unique((array) $incoming['channels'])) : $options['channels'],
                'disclosure_line' => array_key_exists('disclosure_line', $incoming) ? trim((string) $incoming['disclosure_line']) : $options['disclosure_line'],
                'status_after_send' => $incoming['status_after_send'] ?? $options['status_after_send'],
            ],
        ])->save();

        $userId = (int) $request->user()->id;
        if ($before['kill_switch'] !== $settings->kill_switch || $before['mode'] !== $settings->mode) {
            AutoSendAudit::record('agent_settings_changed', "Agent mode {$before['mode']} -> {$settings->mode}, kill switch ".($settings->kill_switch ? 'on' : 'off'), $settings, null, [
                'from' => $before, 'to' => ['mode' => $settings->mode, 'kill_switch' => $settings->kill_switch],
            ], $userId);
        }

        // Kill switch or leaving live: stop every scheduled auto-reply now
        // (SendReplyJob would refuse them anyway; this makes them drafts at once).
        if ($settings->kill_switch || $settings->mode !== AgentSetting::MODE_LIVE) {
            $autoSend->cancelPending($workspaceId, $settings->kill_switch ? 'kill_switch' : 'agent_mode', userId: $userId);
        }

        return back()->with('success', 'Agent settings saved.');
    }
}
