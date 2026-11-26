<?php

namespace App\Domains\Agent\Mining\Models;

use App\Domains\Conversation\Models\Conversation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A conversation a run selected, with its extraction checkpoint.
 *
 * @property int $id
 * @property int $run_id
 * @property int $conversation_id
 * @property string $bucket
 * @property Carbon|null $last_at
 * @property string $status
 * @property string|null $outcome
 * @property int|null $extraction_id
 * @property int $attempts
 * @property string|null $error
 * @property-read MiningExtraction|null $extraction
 * @property-read Conversation|null $conversation
 */
class MiningConversation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const OUTCOME_NEW = 'new';

    public const OUTCOME_REUSED = 'reused';

    public const OUTCOME_MACRO = 'macro';

    protected $table = 'agent_mining_conversations';

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'attempts' => 0,
    ];

    protected $casts = [
        'last_at' => 'datetime',
        'attempts' => 'integer',
    ];

    /** @return BelongsTo<MiningExtraction, $this> */
    public function extraction(): BelongsTo
    {
        return $this->belongsTo(MiningExtraction::class, 'extraction_id');
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
