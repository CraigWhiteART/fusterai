<?php

namespace App\Domains\Agent\Models;

use App\Domains\Conversation\Models\Conversation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A past conversation frozen at a customer message: the transcript up to
 * that message, the evidence as it stood then, and what the human actually
 * replied. Simulations run against this, never against the live
 * conversation.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $conversation_id
 * @property int|null $cut_thread_id
 * @property int|null $human_thread_id
 * @property string|null $subject
 * @property string|null $customer_name
 * @property string|null $customer_email
 * @property list<string>|null $reply_to
 * @property list<array<string, mixed>> $transcript
 * @property Carbon $cut_at
 * @property array<string, mixed>|null $evidence
 * @property string $evidence_quality
 * @property string|null $evidence_hash
 * @property Carbon|null $evidence_prepared_at
 * @property string|null $evidence_error
 * @property string|null $human_reply
 * @property string|null $human_reply_by
 * @property int|null $human_reply_user_id
 * @property Carbon|null $human_reply_at
 * @property string|null $human_reply_origin
 * @property string $source
 * @property string|null $playbook_slug
 * @property array<string, mixed>|null $meta
 * @property string $content_hash
 * @property Carbon|null $created_at
 */
class FrozenCase extends Model
{
    public const QUALITY_RECORDED = 'recorded';

    public const QUALITY_RECONSTRUCTED = 'reconstructed';

    public const QUALITY_NONE = 'none';

    public const QUALITIES = [self::QUALITY_RECORDED, self::QUALITY_RECONSTRUCTED, self::QUALITY_NONE];

    public const SOURCES = ['feedback', 'mining', 'manual', 'qa'];

    protected $table = 'agent_frozen_cases';

    protected $fillable = [
        'workspace_id', 'conversation_id', 'cut_thread_id', 'human_thread_id', 'subject', 'customer_name',
        'customer_email', 'reply_to', 'transcript', 'cut_at', 'evidence', 'evidence_quality', 'evidence_hash',
        'evidence_prepared_at', 'evidence_error', 'human_reply', 'human_reply_by', 'human_reply_user_id',
        'human_reply_at', 'human_reply_origin', 'source', 'playbook_slug', 'meta', 'content_hash',
    ];

    protected $casts = [
        'reply_to' => 'array',
        'transcript' => 'array',
        'evidence' => 'array',
        'meta' => 'array',
        'cut_at' => 'datetime',
        'evidence_prepared_at' => 'datetime',
        'human_reply_at' => 'datetime',
    ];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return HasMany<AgentTestCase, $this> */
    public function testCases(): HasMany
    {
        return $this->hasMany(AgentTestCase::class, 'frozen_case_id');
    }

    /** Cache key part: the case content plus the evidence it currently carries. */
    public function caseKey(): string
    {
        return hash('sha256', $this->content_hash.'|'.($this->evidence_hash ?? 'none').'|'.$this->evidence_quality);
    }

    public function hasRecordedEvidence(): bool
    {
        return $this->evidence_quality === self::QUALITY_RECORDED;
    }

    /** @return array<string, mixed> */
    public function toUiArray(bool $withTranscript = false): array
    {
        $last = $this->transcript === [] ? null : $this->transcript[count($this->transcript) - 1];

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'cut_thread_id' => $this->cut_thread_id,
            'human_thread_id' => $this->human_thread_id,
            'subject' => $this->subject,
            'customer_name' => $this->customer_name,
            'cut_at' => $this->cut_at->toIso8601String(),
            'customer_message' => is_array($last) ? mb_substr((string) ($last['text'] ?? ''), 0, 2000) : null,
            'turns' => count($this->transcript),
            'transcript' => $withTranscript ? $this->transcript : null,
            'evidence_quality' => $this->evidence_quality,
            'evidence_prepared_at' => $this->evidence_prepared_at?->toIso8601String(),
            'evidence_error' => $this->evidence_error,
            'human_reply' => $this->human_reply,
            'human_reply_by' => $this->human_reply_by,
            'human_reply_at' => $this->human_reply_at?->toIso8601String(),
            'source' => $this->source,
            'playbook_slug' => $this->playbook_slug,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
