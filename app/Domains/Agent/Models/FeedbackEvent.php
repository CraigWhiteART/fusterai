<?php

namespace App\Domains\Agent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int|null $run_id
 * @property int|null $conversation_id
 * @property int|null $thread_id
 * @property int|null $user_id
 * @property string $kind
 * @property string|null $draft_before
 * @property string|null $final_text
 * @property int|null $percent_changed
 * @property string|null $text
 * @property array<string, mixed>|null $meta
 */
class FeedbackEvent extends Model
{
    public const KIND_SENT_AS_IS = 'sent_as_is';

    public const KIND_SENT_EDITED = 'sent_edited';

    public const KIND_GMAIL_REPLY = 'gmail_reply';

    public const KIND_REJECTED = 'rejected';

    public const KIND_COMMENT = 'comment';

    /** A human flagged an auto-sent reply as wrong (Phase 8; feeds the circuit breaker). */
    public const KIND_AUTO_SEND_WRONG = 'auto_send_wrong';

    public const KINDS = [self::KIND_SENT_AS_IS, self::KIND_SENT_EDITED, self::KIND_GMAIL_REPLY, self::KIND_REJECTED, self::KIND_COMMENT, self::KIND_AUTO_SEND_WRONG];

    protected $table = 'agent_feedback_events';

    protected $fillable = [
        'workspace_id',
        'run_id',
        'conversation_id',
        'thread_id',
        'user_id',
        'kind',
        'draft_before',
        'final_text',
        'percent_changed',
        'text',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'percent_changed' => 'integer',
    ];

    /** @return BelongsTo<AgentRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'run_id');
    }
}
