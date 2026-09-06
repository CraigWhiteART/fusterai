<?php

namespace Modules\CommerceAssist\Models;

use App\Domains\Conversation\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveFact extends Model
{
    protected $table = 'commerce_assist_live_facts';

    protected $fillable = [
        'workspace_id',
        'source_conversation_id',
        'created_by',
        'title',
        'body',
        'intent_slugs',
        'product_keywords',
        'expires_at',
        'retired_at',
        'refresh_started_at',
        'refresh_completed_at',
        'refreshed_conversation_ids',
    ];

    protected $casts = [
        'intent_slugs' => 'array',
        'product_keywords' => 'array',
        'refreshed_conversation_ids' => 'array',
        'expires_at' => 'datetime',
        'retired_at' => 'datetime',
        'refresh_started_at' => 'datetime',
        'refresh_completed_at' => 'datetime',
    ];

    /** @return BelongsTo<Conversation, $this> */
    public function sourceConversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'source_conversation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('retired_at')
            ->where(function (Builder $inner) {
                $inner->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function isActive(): bool
    {
        if ($this->retired_at) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    public function toUiArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'intent_slugs' => $this->intent_slugs ?? [],
            'product_keywords' => $this->product_keywords ?? [],
            'expires_at' => $this->expires_at?->toIso8601String(),
            'retired_at' => $this->retired_at?->toIso8601String(),
            'refresh_completed_at' => $this->refresh_completed_at?->toIso8601String(),
            'refreshed_count' => count($this->refreshed_conversation_ids ?? []),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
