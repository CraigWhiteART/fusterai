<?php

namespace Modules\CommerceAssist\Models;

use App\Domains\Conversation\Models\AiSuggestion;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model
{
    protected $table = 'commerce_assist_generations';

    protected $fillable = [
        'workspace_id',
        'conversation_id',
        'customer_thread_id',
        'reply_thread_id',
        'ai_suggestion_id',
        'shopify_snapshot_id',
        'ai_draft',
        'final_response',
        'percent_changed',
        'sent_unchanged',
        'intent',
        'subtype',
        'sentiment',
        'model',
        'examples_retrieved',
        'kb_retrieved',
        'sources',
        'unsupported_claims',
        'validator_passed',
        'confidence',
        'safe_to_send',
        'requires_human',
        'status',
        'nominated_as_example',
        'added_as_example',
    ];

    protected $casts = [
        'examples_retrieved' => 'array',
        'kb_retrieved' => 'array',
        'sources' => 'array',
        'unsupported_claims' => 'array',
        'validator_passed' => 'boolean',
        'confidence' => 'float',
        'safe_to_send' => 'boolean',
        'requires_human' => 'boolean',
        'sent_unchanged' => 'boolean',
        'nominated_as_example' => 'boolean',
        'added_as_example' => 'boolean',
        'percent_changed' => 'integer',
    ];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<Thread, $this> */
    public function customerThread(): BelongsTo
    {
        return $this->belongsTo(Thread::class, 'customer_thread_id');
    }

    /** @return BelongsTo<Thread, $this> */
    public function replyThread(): BelongsTo
    {
        return $this->belongsTo(Thread::class, 'reply_thread_id');
    }

    /** @return BelongsTo<AiSuggestion, $this> */
    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(AiSuggestion::class, 'ai_suggestion_id');
    }

    /** @return BelongsTo<ShopifySnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ShopifySnapshot::class, 'shopify_snapshot_id');
    }

    public function toUiArray(): array
    {
        return [
            'id' => $this->id,
            'intent' => $this->intent,
            'subtype' => $this->subtype,
            'sentiment' => $this->sentiment,
            'ai_draft' => $this->ai_draft,
            'final_response' => $this->final_response,
            'percent_changed' => $this->percent_changed,
            'sent_unchanged' => $this->sent_unchanged,
            'confidence' => $this->confidence,
            'safe_to_send' => $this->safe_to_send,
            'requires_human' => $this->requires_human,
            'validator_passed' => $this->validator_passed,
            'unsupported_claims' => $this->unsupported_claims ?? [],
            'sources' => $this->sources ?? [],
            'status' => $this->status,
            'nominated_as_example' => $this->nominated_as_example,
            'added_as_example' => $this->added_as_example,
            'model' => $this->model,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
