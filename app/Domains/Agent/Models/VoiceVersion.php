<?php

namespace App\Domains\Agent\Models;

use App\Domains\Agent\Models\Concerns\IsImmutableVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $version
 * @property string $personality
 * @property list<array{id: string, text: string}>|null $do_phrases
 * @property list<array{id: string, text: string}>|null $dont_phrases
 * @property list<array{id: string, text: string}>|null $greetings
 * @property list<array{id: string, text: string}>|null $sign_offs
 * @property int|null $max_words
 * @property string|null $length_guidance
 * @property bool $is_starter
 * @property string|null $change_note
 * @property int|null $created_by
 * @property int|null $restored_from_id
 * @property Carbon|null $created_at
 */
class VoiceVersion extends Model
{
    use IsImmutableVersion;

    /** The list fields that carry [{id, text}] items. */
    public const LIST_FIELDS = ['do_phrases', 'dont_phrases', 'greetings', 'sign_offs'];

    protected $table = 'agent_voice_versions';

    protected $fillable = [
        'workspace_id',
        'version',
        'personality',
        'do_phrases',
        'dont_phrases',
        'greetings',
        'sign_offs',
        'max_words',
        'length_guidance',
        'is_starter',
        'change_note',
        'created_by',
        'restored_from_id',
    ];

    protected $casts = [
        'do_phrases' => 'array',
        'dont_phrases' => 'array',
        'greetings' => 'array',
        'sign_offs' => 'array',
        'max_words' => 'integer',
        'is_starter' => 'boolean',
        'version' => 'integer',
    ];

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return list<string> */
    public function dontPhraseTexts(): array
    {
        return array_values(array_filter(array_map(
            fn ($item) => trim((string) $item['text']),
            $this->dont_phrases ?? [],
        )));
    }

    /** @return array<string, mixed> */
    public function toContentArray(): array
    {
        return [
            'personality' => $this->personality,
            'do_phrases' => $this->do_phrases ?? [],
            'dont_phrases' => $this->dont_phrases ?? [],
            'greetings' => $this->greetings ?? [],
            'sign_offs' => $this->sign_offs ?? [],
            'max_words' => $this->max_words,
            'length_guidance' => $this->length_guidance,
        ];
    }

    /** @return array<string, mixed> */
    public function toUiArray(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            ...$this->toContentArray(),
            'is_starter' => $this->is_starter,
            'change_note' => $this->change_note,
            'created_by' => $this->creator?->name,
            'restored_from_id' => $this->restored_from_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
