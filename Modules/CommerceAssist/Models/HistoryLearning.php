<?php

namespace Modules\CommerceAssist\Models;

use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoryLearning extends Model
{
    public const KINDS = ['approved_reply', 'knowledge', 'live_fact', 'skip'];

    public const REVIEW_KINDS = ['approved_reply', 'knowledge', 'live_fact'];

    protected $table = 'commerce_assist_history_learnings';

    protected $fillable = [
        'workspace_id',
        'scan_id',
        'conversation_id',
        'thread_id',
        'kind',
        'status',
        'title',
        'body',
        'customer_message',
        'final_reply',
        'source_customer_message',
        'source_final_reply',
        'subject',
        'intent',
        'subtype',
        'product_keywords',
        'time_sensitive',
        'expires_at',
        'rationale',
        'accepted_type',
        'accepted_id',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'product_keywords' => 'array',
        'time_sensitive' => 'boolean',
        'expires_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    /** @return BelongsTo<HistoryScan, $this> */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(HistoryScan::class, 'scan_id');
    }

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

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return array<string, mixed> */
    public function toUiArray(): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'thread_id' => $this->thread_id,
            'kind' => $this->kind,
            'status' => $this->status,
            'title' => $this->title,
            'body' => $this->body,
            'customer_message' => $this->customer_message,
            'final_reply' => $this->final_reply,
            'source_customer_message' => $this->source_customer_message,
            'source_final_reply' => $this->source_final_reply,
            'subject' => $this->subject,
            'intent' => $this->intent,
            'subtype' => $this->subtype,
            'product_keywords' => $this->product_keywords ?? [],
            'time_sensitive' => $this->time_sensitive,
            'expires_at' => $this->expires_at?->toDateString(),
            'rationale' => $this->rationale,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
