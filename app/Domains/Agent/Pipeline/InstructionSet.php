<?php

namespace App\Domains\Agent\Pipeline;

use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Models\VoiceVersion;

/**
 * The human-approved instructions one run uses: voice, playbooks, and the
 * knowledge / examples it may retrieve. Either the live versions, or the
 * live versions plus an in-memory overlay (preview of a proposed change,
 * simulation of a draft playbook) that is never written to the database.
 *
 * Overlay shape (every key optional):
 *   voice:            [personality, do_phrases, dont_phrases, greetings, sign_offs, max_words, length_guidance]
 *   playbooks:        [slug => [name, when_text, rules, steps, allowed_actions, required_evidence, escalation, mode, rollout_percent, always_human, priority]]
 *   knowledge_add:    [[title, body, kind, scope]]   (in-memory items, ids "KO1", "KO2", ...)
 *   knowledge_remove: [knowledge item id]           (hidden from retrieval)
 *   examples_remove:  [agent_examples id]           (hidden from example retrieval)
 */
final class InstructionSet
{
    /**
     * @param  array<string, PlaybookSpec>  $playbooks
     * @param  list<array{id: string, kind: string, title: string|null, body: string, scope: array<string, mixed>}>  $knowledgeAdd
     * @param  list<int>  $knowledgeRemove
     * @param  array<string, mixed>  $overlay
     * @param  list<int>  $examplesRemove
     */
    public function __construct(
        public readonly int $workspaceId,
        public readonly VoiceSpec $voice,
        public readonly array $playbooks,
        public readonly array $knowledgeAdd = [],
        public readonly array $knowledgeRemove = [],
        public readonly array $overlay = [],
        public readonly array $examplesRemove = [],
    ) {}

    public static function live(int $workspaceId): self
    {
        $settings = AgentSetting::forWorkspace($workspaceId);
        $voiceModel = $settings->live_voice_version_id
            ? VoiceVersion::find($settings->live_voice_version_id)
            : null;

        $playbooks = [];
        $rows = Playbook::with('liveVersion')
            ->where('workspace_id', $workspaceId)
            ->whereNull('archived_at')
            ->whereNotNull('live_version_id')
            ->orderByDesc('priority')
            ->orderBy('slug')
            ->get();

        foreach ($rows as $playbook) {
            if ($playbook->liveVersion !== null) {
                $playbooks[$playbook->slug] = PlaybookSpec::fromModels($playbook, $playbook->liveVersion);
            }
        }

        return new self(
            workspaceId: $workspaceId,
            voice: $voiceModel ? VoiceSpec::fromModel($voiceModel) : VoiceSpec::fallback(),
            playbooks: $playbooks,
        );
    }

    /** @param  array<string, mixed>  $overlay */
    public function withOverlay(array $overlay): self
    {
        $voice = is_array($overlay['voice'] ?? null) ? $this->voice->withOverlay($overlay['voice']) : $this->voice;

        $playbooks = $this->playbooks;
        foreach (($overlay['playbooks'] ?? []) as $slug => $fields) {
            if (! is_array($fields)) {
                continue;
            }
            $playbooks[$slug] = isset($playbooks[$slug])
                ? $playbooks[$slug]->withOverlay($fields)
                : PlaybookSpec::fromArray((string) $slug, $fields);
        }

        $added = $this->knowledgeAdd;
        foreach (array_values($overlay['knowledge_add'] ?? []) as $item) {
            if (! is_array($item) || trim((string) ($item['body'] ?? '')) === '') {
                continue;
            }
            $added[] = [
                'id' => 'KO'.(count($added) + 1),
                'kind' => (string) ($item['kind'] ?? 'fact'),
                'title' => isset($item['title']) ? (string) $item['title'] : null,
                'body' => (string) $item['body'],
                'scope' => is_array($item['scope'] ?? null) ? $item['scope'] : [],
            ];
        }

        $removed = array_values(array_unique(array_merge(
            $this->knowledgeRemove,
            array_map('intval', $overlay['knowledge_remove'] ?? []),
        )));

        $examplesRemoved = array_values(array_unique(array_merge(
            $this->examplesRemove,
            array_map('intval', $overlay['examples_remove'] ?? []),
        )));

        return new self(
            workspaceId: $this->workspaceId,
            voice: $voice,
            playbooks: $playbooks,
            knowledgeAdd: $added,
            knowledgeRemove: $removed,
            overlay: array_replace_recursive($this->overlay, $overlay),
            examplesRemove: $examplesRemoved,
        );
    }

    public function isOverlaid(): bool
    {
        return $this->overlay !== [];
    }

    public function playbook(?string $slug): ?PlaybookSpec
    {
        return $slug !== null ? ($this->playbooks[$slug] ?? null) : null;
    }

    /** @return array<string, PlaybookSpec> Playbooks triage may choose from. */
    public function catalogue(): array
    {
        return array_filter($this->playbooks, fn (PlaybookSpec $spec) => $spec->isSelectable() && trim($spec->whenText) !== '');
    }

    /**
     * Version ids for the run config. Overlaid artifacts record "overlay" plus
     * a hash of the overlay so the run is still reproducible.
     *
     * @return array<string, mixed>
     */
    public function versionConfig(): array
    {
        $playbooks = [];
        foreach ($this->playbooks as $slug => $spec) {
            $playbooks[$slug] = $spec->overlaid ? 'overlay' : $spec->versionId;
        }
        ksort($playbooks);

        return [
            'voice_version_id' => $this->voice->overlaid ? 'overlay' : $this->voice->versionId,
            'playbook_versions' => $playbooks,
            'overlay' => $this->isOverlaid() ? $this->overlay : null,
            'overlay_hash' => $this->isOverlaid() ? hash('sha256', (string) json_encode($this->overlay)) : null,
        ];
    }
}
