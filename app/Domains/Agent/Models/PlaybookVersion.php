<?php

namespace App\Domains\Agent\Models;

use App\Domains\Agent\Models\Concerns\IsImmutableVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $playbook_id
 * @property int $version
 * @property string $when_text
 * @property list<array{id: string, if: string, then: string}>|null $rules
 * @property list<array{id: string, text: string}>|null $steps
 * @property list<string>|null $allowed_actions
 * @property list<string>|null $required_evidence
 * @property string|null $escalation
 * @property string|null $change_note
 * @property int|null $created_by
 * @property int|null $restored_from_id
 * @property Carbon|null $created_at
 * @property-read Playbook $playbook
 */
class PlaybookVersion extends Model
{
    use IsImmutableVersion;

    protected $table = 'agent_playbook_versions';

    protected $fillable = [
        'playbook_id',
        'version',
        'when_text',
        'rules',
        'steps',
        'allowed_actions',
        'required_evidence',
        'escalation',
        'change_note',
        'created_by',
        'restored_from_id',
    ];

    protected $casts = [
        'rules' => 'array',
        'steps' => 'array',
        'allowed_actions' => 'array',
        'required_evidence' => 'array',
        'version' => 'integer',
    ];

    /** @return BelongsTo<Playbook, $this> */
    public function playbook(): BelongsTo
    {
        return $this->belongsTo(Playbook::class, 'playbook_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return list<string> */
    public function ruleIds(): array
    {
        return array_values(array_filter(array_map(fn ($rule) => (string) $rule['id'], $this->rules ?? [])));
    }

    /** @return array<string, mixed> */
    public function toContentArray(): array
    {
        return [
            'when_text' => $this->when_text,
            'rules' => $this->rules ?? [],
            'steps' => $this->steps ?? [],
            'allowed_actions' => $this->allowed_actions ?? [],
            'required_evidence' => $this->required_evidence ?? [],
            'escalation' => $this->escalation,
        ];
    }

    /** @return array<string, mixed> */
    public function toUiArray(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            ...$this->toContentArray(),
            'change_note' => $this->change_note,
            'created_by' => $this->creator?->name,
            'restored_from_id' => $this->restored_from_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
