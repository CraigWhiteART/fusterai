<?php

namespace App\Domains\Agent\Services;

use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Models\PlaybookVersion;
use App\Domains\Agent\Models\VoiceVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Writes instruction versions. Every edit appends a version and moves the
 * live pointer in one transaction; restore appends a copy of an old version.
 * Item ids (voice phrases, playbook rules and steps) are preserved when
 * given, so a rule keeps its id across edits, and generated when missing.
 */
class VersionService
{
    // ── Voice ────────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $content */
    public function createVoiceVersion(int $workspaceId, array $content, ?int $userId = null, ?string $changeNote = null, ?int $restoredFromId = null, bool $isStarter = false): VoiceVersion
    {
        return DB::transaction(function () use ($workspaceId, $content, $userId, $changeNote, $restoredFromId, $isStarter) {
            $settings = AgentSetting::forWorkspace($workspaceId);
            AgentSetting::whereKey($settings->id)->lockForUpdate()->first();

            $next = (int) VoiceVersion::where('workspace_id', $workspaceId)->max('version') + 1;

            $voice = VoiceVersion::create([
                'workspace_id' => $workspaceId,
                'version' => $next,
                'personality' => trim((string) ($content['personality'] ?? '')),
                'do_phrases' => $this->items($content['do_phrases'] ?? [], 'do'),
                'dont_phrases' => $this->items($content['dont_phrases'] ?? [], 'dont'),
                'greetings' => $this->items($content['greetings'] ?? [], 'greet'),
                'sign_offs' => $this->items($content['sign_offs'] ?? [], 'sign'),
                'max_words' => isset($content['max_words']) && $content['max_words'] !== '' ? max(1, (int) $content['max_words']) : null,
                'length_guidance' => filled($content['length_guidance'] ?? null) ? trim((string) $content['length_guidance']) : null,
                'is_starter' => $isStarter,
                'change_note' => $changeNote,
                'created_by' => $userId,
                'restored_from_id' => $restoredFromId,
            ]);

            $settings->forceFill(['live_voice_version_id' => $voice->id])->save();

            return $voice;
        });
    }

    public function restoreVoice(VoiceVersion $old, ?int $userId = null): VoiceVersion
    {
        return $this->createVoiceVersion(
            $old->workspace_id,
            $old->toContentArray(),
            $userId,
            "Restored from version {$old->version}",
            $old->id,
        );
    }

    // ── Playbooks ────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $settings  name, mode, rollout_percent, always_human, priority
     * @param  array<string, mixed>  $content  when_text, rules, steps, allowed_actions, required_evidence, escalation
     */
    public function createPlaybook(int $workspaceId, string $slug, array $settings, array $content, ?int $userId = null, ?string $changeNote = null): Playbook
    {
        $slug = Str::slug($slug, '_') === '' ? Str::slug((string) ($settings['name'] ?? 'playbook'), '_') : $slug;

        return DB::transaction(function () use ($workspaceId, $slug, $settings, $content, $userId, $changeNote) {
            $playbook = Playbook::create([
                'workspace_id' => $workspaceId,
                'slug' => $slug,
                'name' => trim((string) ($settings['name'] ?? $slug)),
                'mode' => $this->playbookMode($settings['mode'] ?? Playbook::MODE_DRAFT),
                'rollout_percent' => $this->percent($settings['rollout_percent'] ?? 0),
                'always_human' => (bool) ($settings['always_human'] ?? false),
                'priority' => (int) ($settings['priority'] ?? 0),
            ]);

            $this->createPlaybookVersion($playbook, $content, $userId, $changeNote ?? 'Created');

            return $playbook->fresh(['liveVersion']) ?? $playbook;
        });
    }

    /** @param  array<string, mixed>  $settings */
    public function updatePlaybookSettings(Playbook $playbook, array $settings): Playbook
    {
        $playbook->fill(array_filter([
            'name' => isset($settings['name']) ? trim((string) $settings['name']) : null,
            'mode' => isset($settings['mode']) ? $this->playbookMode($settings['mode']) : null,
            'rollout_percent' => array_key_exists('rollout_percent', $settings) ? $this->percent($settings['rollout_percent']) : null,
            'priority' => array_key_exists('priority', $settings) ? (int) $settings['priority'] : null,
        ], fn ($v) => $v !== null));

        if (array_key_exists('always_human', $settings)) {
            $playbook->always_human = (bool) $settings['always_human'];
        }

        $playbook->save();

        return $playbook;
    }

    /** @param  array<string, mixed>  $content */
    public function createPlaybookVersion(Playbook $playbook, array $content, ?int $userId = null, ?string $changeNote = null, ?int $restoredFromId = null): PlaybookVersion
    {
        $whenText = trim((string) ($content['when_text'] ?? ''));
        if ($whenText === '') {
            throw new InvalidArgumentException('A playbook version needs a "when" description.');
        }

        return DB::transaction(function () use ($playbook, $content, $userId, $changeNote, $restoredFromId, $whenText) {
            Playbook::whereKey($playbook->id)->lockForUpdate()->first();
            $next = (int) PlaybookVersion::where('playbook_id', $playbook->id)->max('version') + 1;

            $version = PlaybookVersion::create([
                'playbook_id' => $playbook->id,
                'version' => $next,
                'when_text' => $whenText,
                'rules' => $this->rules($content['rules'] ?? []),
                'steps' => $this->items($content['steps'] ?? [], 'step'),
                'allowed_actions' => $this->actions($content['allowed_actions'] ?? []),
                'required_evidence' => array_values(array_intersect(
                    array_map('strval', (array) ($content['required_evidence'] ?? [])),
                    ['order', 'tracking', 'photos'],
                )),
                'escalation' => filled($content['escalation'] ?? null) ? trim((string) $content['escalation']) : null,
                'change_note' => $changeNote,
                'created_by' => $userId,
                'restored_from_id' => $restoredFromId,
            ]);

            $playbook->forceFill(['live_version_id' => $version->id])->save();

            return $version;
        });
    }

    public function restorePlaybook(Playbook $playbook, PlaybookVersion $old, ?int $userId = null): PlaybookVersion
    {
        if ($old->playbook_id !== $playbook->id) {
            throw new InvalidArgumentException('That version belongs to another playbook.');
        }

        return $this->createPlaybookVersion($playbook, $old->toContentArray(), $userId, "Restored from version {$old->version}", $old->id);
    }

    // ── Item normalisation ───────────────────────────────────────────────────

    /**
     * [{id?, text}] or plain strings → [{id, text}], ids kept when valid.
     *
     * @return list<array{id: string, text: string}>
     */
    public function items(mixed $items, string $prefix): array
    {
        $out = [];
        $seen = [];
        foreach ((array) $items as $item) {
            $text = trim((string) (is_array($item) ? ($item['text'] ?? '') : $item));
            if ($text === '') {
                continue;
            }
            $id = is_array($item) ? $this->cleanId($item['id'] ?? null) : null;
            if ($id === null || isset($seen[$id])) {
                $id = $prefix.'-'.Str::lower(Str::random(8));
            }
            $seen[$id] = true;
            $out[] = ['id' => $id, 'text' => $text];
        }

        return $out;
    }

    /** @return list<array{id: string, if: string, then: string}> */
    public function rules(mixed $rules): array
    {
        $out = [];
        $seen = [];
        foreach ((array) $rules as $rule) {
            if (! is_array($rule)) {
                $rule = ['if' => 'always', 'then' => (string) $rule];
            }
            $then = trim((string) ($rule['then'] ?? ''));
            if ($then === '') {
                continue;
            }
            $id = $this->cleanId($rule['id'] ?? null);
            if ($id === null || isset($seen[$id])) {
                $id = 'r-'.Str::lower(Str::random(8));
            }
            $seen[$id] = true;
            $out[] = ['id' => $id, 'if' => trim((string) ($rule['if'] ?? '')) ?: 'always', 'then' => $then];
        }

        return $out;
    }

    /** @return list<string> */
    private function actions(mixed $actions): array
    {
        $known = array_keys((array) config('agent.actions', []));

        return array_values(array_unique(array_intersect(array_map('strval', (array) $actions), $known)));
    }

    private function cleanId(mixed $id): ?string
    {
        $id = is_scalar($id) ? trim((string) $id) : '';

        return preg_match('/^[A-Za-z0-9._-]{1,64}$/', $id) === 1 ? $id : null;
    }

    private function playbookMode(mixed $mode): string
    {
        $mode = (string) $mode;

        return in_array($mode, Playbook::MODES, true) ? $mode : Playbook::MODE_DRAFT;
    }

    private function percent(mixed $value): int
    {
        return max(0, min(100, (int) $value));
    }
}
