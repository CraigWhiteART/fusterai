<?php

namespace App\Domains\Agent\Models;

use App\Domains\Conversation\Models\AiSuggestion;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $purpose
 * @property int|null $conversation_id
 * @property int|null $frozen_case_id
 * @property int|null $customer_thread_id
 * @property string $idempotency_key
 * @property string $status
 * @property array<string, mixed> $config
 * @property string $config_hash
 * @property array<string, mixed>|null $triage
 * @property int|null $playbook_id
 * @property int|null $playbook_version_id
 * @property array<string, mixed>|null $evidence
 * @property string|null $draft
 * @property string|null $internal_note
 * @property list<array<string, mixed>>|null $claims
 * @property list<string>|null $rules_used
 * @property list<array<string, mixed>>|null $proposed_actions
 * @property array<string, mixed>|null $validator
 * @property string|null $decision
 * @property string|null $handover_reason
 * @property int $tokens_in
 * @property int $tokens_out
 * @property int $tokens_cache_read
 * @property int $tokens_cache_write
 * @property int $tokens_reasoning
 * @property int $cost_micro_usd
 * @property int|null $latency_ms
 * @property array<string, mixed>|null $trace
 * @property string|null $error
 * @property int|null $ai_suggestion_id
 * @property array<string, mixed>|null $auto_send
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property-read Playbook|null $playbook
 * @property-read PlaybookVersion|null $playbookVersion
 */
class AgentRun extends Model
{
    public const PURPOSE_LIVE = 'live';

    public const PURPOSE_SHADOW = 'shadow';

    public const PURPOSE_PREVIEW = 'preview';

    public const PURPOSE_SIMULATION = 'simulation';

    public const PURPOSE_TEST = 'test';

    public const PURPOSES = [self::PURPOSE_LIVE, self::PURPOSE_SHADOW, self::PURPOSE_PREVIEW, self::PURPOSE_SIMULATION, self::PURPOSE_TEST];

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'agent_runs';

    protected $fillable = [
        'workspace_id',
        'purpose',
        'conversation_id',
        'frozen_case_id',
        'customer_thread_id',
        'idempotency_key',
        'status',
        'config',
        'config_hash',
        'triage',
        'playbook_id',
        'playbook_version_id',
        'evidence',
        'draft',
        'internal_note',
        'claims',
        'rules_used',
        'proposed_actions',
        'validator',
        'decision',
        'handover_reason',
        'tokens_in',
        'tokens_out',
        'tokens_cache_read',
        'tokens_cache_write',
        'tokens_reasoning',
        'cost_micro_usd',
        'latency_ms',
        'trace',
        'error',
        'ai_suggestion_id',
        'auto_send',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'config' => 'array',
        'triage' => 'array',
        'evidence' => 'array',
        'claims' => 'array',
        'rules_used' => 'array',
        'proposed_actions' => 'array',
        'validator' => 'array',
        'trace' => 'array',
        'auto_send' => 'array',
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

    /** @return BelongsTo<Playbook, $this> */
    public function playbook(): BelongsTo
    {
        return $this->belongsTo(Playbook::class);
    }

    /** @return BelongsTo<PlaybookVersion, $this> */
    public function playbookVersion(): BelongsTo
    {
        return $this->belongsTo(PlaybookVersion::class);
    }

    /** @return BelongsTo<AiSuggestion, $this> */
    public function aiSuggestion(): BelongsTo
    {
        return $this->belongsTo(AiSuggestion::class);
    }

    /** @return HasMany<FeedbackEvent, $this> */
    public function feedback(): HasMany
    {
        return $this->hasMany(FeedbackEvent::class, 'run_id');
    }

    public function isLive(): bool
    {
        return $this->purpose === self::PURPOSE_LIVE;
    }
}
