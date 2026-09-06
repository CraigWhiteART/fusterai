<?php

namespace Modules\CommerceAssist\Models;

use App\Domains\Conversation\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReplayRun extends Model
{
    protected $table = 'commerce_assist_replay_runs';

    protected $fillable = [
        'workspace_id',
        'conversation_id',
        'generation_id',
        'original_reply',
        'current_ai_reply',
        'passed',
        'sources',
        'run_by',
    ];

    protected $casts = [
        'passed' => 'boolean',
        'sources' => 'array',
    ];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<Generation, $this> */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function runner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }
}
