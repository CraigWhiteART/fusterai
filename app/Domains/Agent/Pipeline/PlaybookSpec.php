<?php

namespace App\Domains\Agent\Pipeline;

use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Models\PlaybookVersion;
use App\Domains\Agent\Services\VersionService;

/**
 * A playbook as the pipeline uses it: operational settings plus the content
 * of one version. Built from rows, or from an in-memory overlay (preview),
 * in which case versionId is null.
 */
final class PlaybookSpec
{
    /**
     * @param  list<array{id: string, if: string, then: string}>  $rules
     * @param  list<array{id: string, text: string}>  $steps
     * @param  list<string>  $allowedActions
     * @param  list<string>  $requiredEvidence
     */
    public function __construct(
        public readonly ?int $id,
        public readonly ?int $versionId,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $mode,
        public readonly int $rolloutPercent,
        public readonly bool $alwaysHuman,
        public readonly int $priority,
        public readonly string $whenText,
        public readonly array $rules,
        public readonly array $steps,
        public readonly array $allowedActions,
        public readonly array $requiredEvidence,
        public readonly ?string $escalation,
        public readonly bool $overlaid = false,
    ) {}

    public static function fromModels(Playbook $playbook, PlaybookVersion $version): self
    {
        return new self(
            id: $playbook->id,
            versionId: $version->id,
            slug: $playbook->slug,
            name: $playbook->name,
            mode: $playbook->mode,
            rolloutPercent: (int) $playbook->rollout_percent,
            alwaysHuman: (bool) $playbook->always_human,
            priority: (int) $playbook->priority,
            whenText: (string) $version->when_text,
            rules: array_values($version->rules ?? []),
            steps: array_values($version->steps ?? []),
            allowedActions: array_values($version->allowed_actions ?? []),
            requiredEvidence: array_values($version->required_evidence ?? []),
            escalation: $version->escalation,
        );
    }

    /**
     * Apply overlay fields (content and/or operational) on top of this spec.
     *
     * @param  array<string, mixed>  $overlay
     */
    public function withOverlay(array $overlay): self
    {
        return new self(
            id: $this->id,
            versionId: null,
            slug: $this->slug,
            name: (string) ($overlay['name'] ?? $this->name),
            mode: (string) ($overlay['mode'] ?? $this->mode),
            rolloutPercent: (int) ($overlay['rollout_percent'] ?? $this->rolloutPercent),
            alwaysHuman: (bool) ($overlay['always_human'] ?? $this->alwaysHuman),
            priority: (int) ($overlay['priority'] ?? $this->priority),
            whenText: (string) ($overlay['when_text'] ?? $this->whenText),
            rules: isset($overlay['rules']) ? app(VersionService::class)->rules($overlay['rules']) : $this->rules,
            steps: isset($overlay['steps']) ? app(VersionService::class)->items($overlay['steps'], 'step') : $this->steps,
            allowedActions: array_values($overlay['allowed_actions'] ?? $this->allowedActions),
            requiredEvidence: array_values($overlay['required_evidence'] ?? $this->requiredEvidence),
            escalation: array_key_exists('escalation', $overlay) ? $overlay['escalation'] : $this->escalation,
            overlaid: true,
        );
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(string $slug, array $data): self
    {
        return (new self(
            id: null,
            versionId: null,
            slug: $slug,
            name: (string) ($data['name'] ?? $slug),
            mode: Playbook::MODE_DRAFT,
            rolloutPercent: 0,
            alwaysHuman: false,
            priority: 0,
            whenText: '',
            rules: [],
            steps: [],
            allowedActions: [],
            requiredEvidence: [],
            escalation: null,
        ))->withOverlay($data);
    }

    /** @return list<string> */
    public function ruleIds(): array
    {
        return array_values(array_filter(array_map(fn ($rule) => (string) $rule['id'], $this->rules)));
    }

    public function isSelectable(): bool
    {
        return $this->mode !== Playbook::MODE_OFF;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'version_id' => $this->versionId,
            'slug' => $this->slug,
            'name' => $this->name,
            'mode' => $this->mode,
            'rollout_percent' => $this->rolloutPercent,
            'always_human' => $this->alwaysHuman,
            'priority' => $this->priority,
            'when_text' => $this->whenText,
            'rules' => $this->rules,
            'steps' => $this->steps,
            'allowed_actions' => $this->allowedActions,
            'required_evidence' => $this->requiredEvidence,
            'escalation' => $this->escalation,
            'overlaid' => $this->overlaid,
        ];
    }
}
