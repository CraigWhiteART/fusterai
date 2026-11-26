<?php

namespace App\Domains\Agent\Manager\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int|null $user_id
 * @property string|null $title
 * @property array<string, mixed>|null $restrictions
 * @property int $message_count
 * @property int $tokens_in
 * @property int $tokens_out
 * @property int $cost_micro_usd
 * @property Carbon|null $last_message_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ManagerChat extends Model
{
    protected $table = 'agent_manager_chats';

    protected $fillable = [
        'workspace_id', 'user_id', 'title', 'restrictions', 'message_count',
        'tokens_in', 'tokens_out', 'cost_micro_usd', 'last_message_at',
    ];

    protected $casts = [
        'restrictions' => 'array',
        'message_count' => 'integer',
        'tokens_in' => 'integer',
        'tokens_out' => 'integer',
        'cost_micro_usd' => 'integer',
        'last_message_at' => 'datetime',
    ];

    /** @return HasMany<ManagerMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ManagerMessage::class, 'chat_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
