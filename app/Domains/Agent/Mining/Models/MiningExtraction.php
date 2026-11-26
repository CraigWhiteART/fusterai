<?php

namespace App\Domains\Agent\Mining\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one conversation taught us, as structured, scrubbed data. Keyed by
 * (conversation_id, content_hash, extractor_version) and shared across runs.
 *
 * data: {category, situation, situation_is_new, situation_description,
 *        customer_asked, decisions[], decision_summary,
 *        statements[{kind, topic, text, said_at}], tone{...},
 *        outcome, outcome_note, language, exemplary}
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $conversation_id
 * @property string $content_hash
 * @property string $extractor_version
 * @property string $status
 * @property bool $is_support
 * @property string|null $situation
 * @property string|null $language
 * @property string|null $outcome
 * @property array<string, mixed> $data
 * @property string|null $macro_hash
 * @property int|null $macro_of_id
 * @property list<string>|null $staff_keys
 * @property Carbon|null $first_at
 * @property Carbon|null $last_at
 * @property Carbon|null $last_staff_at
 * @property string|null $model
 * @property int $tokens_in
 * @property int $tokens_out
 * @property int $cost_micro_usd
 * @property int|null $run_id
 */
class MiningExtraction extends Model
{
    public const STATUS_OK = 'ok';

    public const STATUS_NOT_SUPPORT = 'not_support';

    public const STATUS_MACRO = 'macro';

    protected $table = 'agent_mining_extractions';

    protected $guarded = ['id'];

    protected $casts = [
        'is_support' => 'boolean',
        'data' => 'array',
        'staff_keys' => 'array',
        'first_at' => 'datetime',
        'last_at' => 'datetime',
        'last_staff_at' => 'datetime',
        'tokens_in' => 'integer',
        'tokens_out' => 'integer',
        'cost_micro_usd' => 'integer',
    ];

    /** @return BelongsTo<MiningExtraction, $this> */
    public function macroOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'macro_of_id');
    }

    /** @return list<string> */
    public function decisions(): array
    {
        return array_values(array_map('strval', (array) ($this->data['decisions'] ?? [])));
    }
}
