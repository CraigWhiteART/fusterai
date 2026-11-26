<?php

namespace App\Domains\Mailbox\Sync\Backfill;

use App\Domains\Mailbox\Models\Mailbox;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One import of a mailbox's history (see BackfillRunner for the stages).
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $mailbox_id
 * @property int|null $user_id
 * @property int $months
 * @property Carbon $since
 * @property array{dry_run?: bool, include_unanswered?: bool, active_window_days?: int}|null $options
 * @property string $status
 * @property string $stage
 * @property array<string, mixed>|null $counters
 * @property string|null $job_token
 * @property int $error_count
 * @property int $consecutive_errors
 * @property string|null $last_error
 * @property Carbon|null $last_error_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $heartbeat_at
 * @property Carbon|null $created_at
 * @property-read Mailbox|null $mailbox
 */
class MailBackfillRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_UNDOING = 'undoing';

    public const STATUS_UNDONE = 'undone';

    /** Statuses the partial unique index allows once per mailbox. */
    public const ACTIVE_STATUSES = [self::STATUS_QUEUED, self::STATUS_RUNNING, self::STATUS_PAUSED, self::STATUS_UNDOING];

    public const STAGE_METADATA = 'metadata';

    public const STAGE_FILTER = 'filter';

    public const STAGE_BODIES = 'bodies';

    public const STAGE_MATERIALISE = 'materialise';

    public const STAGE_INDEX = 'index';

    public const STAGE_DONE = 'done';

    public const STAGES = [self::STAGE_METADATA, self::STAGE_FILTER, self::STAGE_BODIES, self::STAGE_MATERIALISE, self::STAGE_INDEX, self::STAGE_DONE];

    protected $table = 'mail_backfill_runs';

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => self::STATUS_QUEUED,
        'stage' => self::STAGE_METADATA,
        'error_count' => 0,
        'consecutive_errors' => 0,
    ];

    protected $casts = [
        'months' => 'integer',
        'since' => 'date',
        'options' => 'array',
        'counters' => 'array',
        'error_count' => 'integer',
        'consecutive_errors' => 'integer',
        'last_error_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'heartbeat_at' => 'datetime',
    ];

    /** @return BelongsTo<Mailbox, $this> */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<MailBackfillThread, $this> */
    public function threads(): HasMany
    {
        return $this->hasMany(MailBackfillThread::class, 'run_id');
    }

    public function isDryRun(): bool
    {
        return (bool) ($this->options['dry_run'] ?? false);
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    public function counter(string $key): int
    {
        return (int) ($this->counters[$key] ?? 0);
    }

    /** @param array<string, int> $deltas */
    public function bump(array $deltas): void
    {
        $counters = $this->counters ?? [];
        foreach ($deltas as $key => $delta) {
            $counters[$key] = (int) ($counters[$key] ?? 0) + $delta;
        }
        $this->counters = $counters;
    }

    /** @param array<string, mixed> $values */
    public function setCounters(array $values): void
    {
        $this->counters = array_merge($this->counters ?? [], $values);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }
}
