<?php

namespace App\Domains\Agent\Mining\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One mining run (see MiningRunner for the stages).
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $user_id
 * @property array{since: string, until: string, max_conversations: int, budget_usd: float, sample_strategy: string} $options
 * @property string $status
 * @property string $stage
 * @property array<string, mixed>|null $counters
 * @property array<string, array<string, int>>|null $usage
 * @property array<string, mixed>|null $results
 * @property int $tokens_in
 * @property int $tokens_out
 * @property int $cost_micro_usd
 * @property int $budget_micro_usd
 * @property string|null $pause_reason
 * @property string|null $message
 * @property string|null $job_token
 * @property int $error_count
 * @property int $consecutive_errors
 * @property string|null $last_error
 * @property Carbon|null $last_error_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $heartbeat_at
 * @property Carbon|null $created_at
 */
class MiningRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** Statuses the partial unique index allows once per workspace. */
    public const ACTIVE_STATUSES = [self::STATUS_QUEUED, self::STATUS_RUNNING, self::STATUS_PAUSED];

    public const STAGE_SELECT = 'select';

    public const STAGE_EXTRACT = 'extract';

    public const STAGE_CLUSTER = 'cluster';

    public const STAGE_SYNTHESISE = 'synthesise';

    public const STAGE_VOICE = 'voice';

    public const STAGE_EMIT = 'emit';

    public const STAGE_DONE = 'done';

    public const STAGES = [self::STAGE_SELECT, self::STAGE_EXTRACT, self::STAGE_CLUSTER, self::STAGE_SYNTHESISE, self::STAGE_VOICE, self::STAGE_EMIT, self::STAGE_DONE];

    /** Stages that make model calls (keys of `usage`). */
    public const LLM_STAGES = [self::STAGE_EXTRACT, self::STAGE_CLUSTER, self::STAGE_SYNTHESISE, self::STAGE_VOICE];

    public const PAUSE_USER = 'user';

    public const PAUSE_BUDGET = 'budget';

    protected $table = 'agent_mining_runs';

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => self::STATUS_QUEUED,
        'stage' => self::STAGE_SELECT,
        'error_count' => 0,
        'consecutive_errors' => 0,
        'tokens_in' => 0,
        'tokens_out' => 0,
        'cost_micro_usd' => 0,
    ];

    protected $casts = [
        'options' => 'array',
        'counters' => 'array',
        'usage' => 'array',
        'results' => 'array',
        'tokens_in' => 'integer',
        'tokens_out' => 'integer',
        'cost_micro_usd' => 'integer',
        'budget_micro_usd' => 'integer',
        'error_count' => 'integer',
        'consecutive_errors' => 'integer',
        'last_error_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'heartbeat_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<MiningConversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(MiningConversation::class, 'run_id');
    }

    /** @return HasMany<MiningSituation, $this> */
    public function situations(): HasMany
    {
        return $this->hasMany(MiningSituation::class, 'run_id');
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    public function since(): Carbon
    {
        return Carbon::parse((string) $this->option('since'));
    }

    public function until(): Carbon
    {
        return Carbon::parse((string) $this->option('until'));
    }

    public function counter(string $key): int
    {
        return (int) ($this->counters[$key] ?? 0);
    }

    /** @param  array<string, int>  $deltas */
    public function bump(array $deltas): void
    {
        $counters = $this->counters ?? [];
        foreach ($deltas as $key => $delta) {
            $counters[$key] = (int) ($counters[$key] ?? 0) + $delta;
        }
        $this->counters = $counters;
    }

    /** Adds to a nested counter map, e.g. dropped.pii_email. */
    public function bumpIn(string $group, string $key, int $delta = 1): void
    {
        $counters = $this->counters ?? [];
        $map = (array) ($counters[$group] ?? []);
        $map[$key] = (int) ($map[$key] ?? 0) + $delta;
        $counters[$group] = $map;
        $this->counters = $counters;
    }

    /** @param  array<string, mixed>  $values */
    public function setCounters(array $values): void
    {
        $this->counters = array_merge($this->counters ?? [], $values);
    }

    public function result(string $key, mixed $default = null): mixed
    {
        return $this->results[$key] ?? $default;
    }

    public function setResult(string $key, mixed $value): void
    {
        $results = $this->results ?? [];
        $results[$key] = $value;
        $this->results = $results;
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function remainingBudgetMicroUsd(): int
    {
        return $this->budget_micro_usd - $this->cost_micro_usd;
    }

    public static function usd(int $microUsd): float
    {
        return round($microUsd / 1_000_000, 4);
    }
}
