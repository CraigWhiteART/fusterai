<?php

namespace Modules\CommerceAssist\Models;

use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<int, float>|null $embedding
 */
class ApprovedResponse extends Model
{
    protected $table = 'commerce_assist_approved_responses';

    protected $fillable = [
        'workspace_id',
        'conversation_id',
        'thread_id',
        'generation_id',
        'customer_message',
        'final_reply',
        'intent',
        'subtype',
        'sentiment',
        'country',
        'tags',
        'embedding',
        'created_by',
        'source',
        'indexed_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'indexed_at' => 'datetime',
    ];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<Thread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    /** @return BelongsTo<Generation, $this> */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class, 'generation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
