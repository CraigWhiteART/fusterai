<?php

namespace App\Domains\Agent\Mining\Models;

use App\Domains\Agent\Models\Playbook;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One situation (cluster) of a run, its coverage and its synthesis.
 *
 * @property int $id
 * @property int $run_id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property int|null $playbook_id
 * @property array<string, int> $labels
 * @property list<int> $extraction_ids
 * @property int $conversation_count
 * @property string $status
 * @property array<string, mixed>|null $synthesis
 * @property int $attempts
 * @property string|null $error
 * @property-read Playbook|null $playbook
 */
class MiningSituation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SYNTHESISED = 'synthesised';

    public const STATUS_FAILED = 'failed';

    /** Fewer conversations than cluster.min_conversations: reported, not synthesised. */
    public const STATUS_TOO_SMALL = 'too_small';

    /** Beyond cluster.max_situations: reported as long tail, not synthesised. */
    public const STATUS_LONG_TAIL = 'long_tail';

    protected $table = 'agent_mining_situations';

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'attempts' => 0,
    ];

    protected $casts = [
        'labels' => 'array',
        'extraction_ids' => 'array',
        'synthesis' => 'array',
        'conversation_count' => 'integer',
        'attempts' => 'integer',
    ];

    /** @return BelongsTo<Playbook, $this> */
    public function playbook(): BelongsTo
    {
        return $this->belongsTo(Playbook::class);
    }
}
