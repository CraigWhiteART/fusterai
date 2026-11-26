<?php

namespace App\Domains\Agent\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A staged change set against the instruction artifacts. Created by the
 * feedback loop, the manager chat, QA or history mining; nothing in it is
 * live until a human approves it. See docs/agent-proposals.md.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $origin
 * @property string $status
 * @property string $title
 * @property string|null $rationale
 * @property array<string, mixed>|null $evidence
 * @property string $base_config_hash
 * @property int|null $gate_simulation_id
 * @property string|null $scope
 * @property int|null $conversation_id
 * @property int|null $run_id
 * @property int|null $feedback_event_id
 * @property list<array<string, mixed>>|null $suggestions
 * @property array<string, mixed>|null $conflicts
 * @property Carbon|null $conflicts_checked_at
 * @property array<string, mixed>|null $preview
 * @property array<string, mixed>|null $applied
 * @property list<array<string, mixed>>|null $excluded
 * @property string|null $review_note
 * @property int|null $created_by
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $applied_at
 * @property int|null $reverted_by
 * @property Carbon|null $reverted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, ProposalChange> $changes
 */
class Proposal extends Model
{
    public const ORIGIN_FEEDBACK = 'feedback';

    public const ORIGIN_MANAGER = 'manager';

    public const ORIGIN_QA = 'qa';

    public const ORIGIN_MINING = 'mining';

    public const ORIGINS = [self::ORIGIN_FEEDBACK, self::ORIGIN_MANAGER, self::ORIGIN_QA, self::ORIGIN_MINING];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_TESTING = 'testing';

    public const STATUS_READY = 'ready';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_STALE = 'stale';

    public const STATUS_REVERTED = 'reverted';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_TESTING, self::STATUS_READY, self::STATUS_APPROVED,
        self::STATUS_REJECTED, self::STATUS_APPLIED, self::STATUS_STALE, self::STATUS_REVERTED,
    ];

    /** Statuses a human may still approve / reject / edit. */
    public const OPEN_STATUSES = [self::STATUS_DRAFT, self::STATUS_TESTING, self::STATUS_READY, self::STATUS_STALE];

    protected $table = 'agent_proposals';

    protected $fillable = [
        'workspace_id', 'origin', 'status', 'title', 'rationale', 'evidence', 'base_config_hash',
        'gate_simulation_id', 'scope', 'conversation_id', 'run_id', 'feedback_event_id', 'suggestions',
        'conflicts', 'conflicts_checked_at', 'preview', 'applied', 'excluded', 'review_note', 'created_by',
        'reviewed_by', 'reviewed_at', 'applied_at', 'reverted_by', 'reverted_at',
    ];

    protected $casts = [
        'evidence' => 'array',
        'suggestions' => 'array',
        'conflicts' => 'array',
        'preview' => 'array',
        'applied' => 'array',
        'excluded' => 'array',
        'conflicts_checked_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'applied_at' => 'datetime',
        'reverted_at' => 'datetime',
    ];

    /** @return HasMany<ProposalChange, $this> */
    public function changes(): HasMany
    {
        return $this->hasMany(ProposalChange::class, 'proposal_id')->orderBy('position')->orderBy('id');
    }

    /** @return BelongsTo<Simulation, $this> */
    public function gateSimulation(): BelongsTo
    {
        return $this->belongsTo(Simulation::class, 'gate_simulation_id');
    }

    /** @return BelongsTo<FeedbackEvent, $this> */
    public function feedbackEvent(): BelongsTo
    {
        return $this->belongsTo(FeedbackEvent::class, 'feedback_event_id');
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'run_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** Unresolved conflicts with severity "blocking". */
    public function blockingConflicts(): array
    {
        return array_values(array_filter(
            (array) ($this->conflicts['items'] ?? []),
            fn ($c) => ($c['severity'] ?? '') === 'blocking' && ($c['resolution'] ?? null) === null,
        ));
    }
}
