<?php

namespace App\Domains\Agent\Manager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $chat_id
 * @property int $workspace_id
 * @property int|null $user_id
 * @property string $role
 * @property string $status
 * @property string|null $content
 * @property int|null $reply_to_id
 * @property list<array<string, mixed>>|null $tool_calls
 * @property list<array<string, mixed>>|null $citations
 * @property list<int>|null $proposal_ids
 * @property list<array<string, mixed>>|null $suggestions
 * @property array<string, mixed>|null $restrictions
 * @property array<string, mixed>|null $limits
 * @property string|null $model
 * @property string|null $error
 * @property int $tokens_in
 * @property int $tokens_out
 * @property int $tokens_cache_read
 * @property int $tokens_cache_write
 * @property int $tokens_reasoning
 * @property int $cost_micro_usd
 * @property int|null $latency_ms
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property-read ManagerChat|null $chat
 */
class ManagerMessage extends Model
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** Not run: the workspace's daily manager budget was spent. */
    public const STATUS_CAPPED = 'capped';

    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_RUNNING];

    protected $table = 'agent_manager_messages';

    protected $fillable = [
        'chat_id', 'workspace_id', 'user_id', 'role', 'status', 'content', 'reply_to_id',
        'tool_calls', 'citations', 'proposal_ids', 'suggestions', 'restrictions', 'limits',
        'model', 'error', 'tokens_in', 'tokens_out', 'tokens_cache_read', 'tokens_cache_write',
        'tokens_reasoning', 'cost_micro_usd', 'latency_ms', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'tool_calls' => 'array',
        'citations' => 'array',
        'proposal_ids' => 'array',
        'suggestions' => 'array',
        'restrictions' => 'array',
        'limits' => 'array',
        'tokens_in' => 'integer',
        'tokens_out' => 'integer',
        'tokens_cache_read' => 'integer',
        'tokens_cache_write' => 'integer',
        'tokens_reasoning' => 'integer',
        'cost_micro_usd' => 'integer',
        'latency_ms' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /** @return BelongsTo<ManagerChat, $this> */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(ManagerChat::class, 'chat_id');
    }

    /** @return BelongsTo<ManagerMessage, $this> */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }
}
