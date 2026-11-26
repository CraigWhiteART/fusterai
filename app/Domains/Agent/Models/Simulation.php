<?php

namespace App\Domains\Agent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $kind
 * @property string $status
 * @property int|null $proposal_id
 * @property int|null $playbook_id
 * @property string $candidate_config_hash
 * @property string|null $baseline_config_hash
 * @property array<string, mixed>|null $overlay
 * @property array<string, mixed>|null $selection
 * @property list<array{0: int, 1: int|null}> $cases
 * @property int $cursor
 * @property array<string, int>|null $totals
 * @property int $cost_micro_usd
 * @property int $estimated_cost_micro_usd
 * @property int|null $budget_micro_usd
 * @property string|null $error
 * @property int|null $created_by
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 */
class Simulation extends Model
{
    public const KIND_BATCH = 'batch';

    public const KIND_GATE = 'gate';

    public const KIND_PLAYBOOK_TESTS = 'playbook_tests';

    public const KINDS = [self::KIND_BATCH, self::KIND_GATE, self::KIND_PLAYBOOK_TESTS];

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_BUDGET_EXHAUSTED = 'budget_exhausted';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_PAUSED, self::STATUS_COMPLETED,
        self::STATUS_CANCELLED, self::STATUS_BUDGET_EXHAUSTED, self::STATUS_FAILED,
    ];

    public const FINISHED_STATUSES = [self::STATUS_COMPLETED, self::STATUS_CANCELLED, self::STATUS_BUDGET_EXHAUSTED, self::STATUS_FAILED];

    protected $table = 'agent_simulations';

    protected $fillable = [
        'workspace_id', 'kind', 'status', 'proposal_id', 'playbook_id', 'candidate_config_hash',
        'baseline_config_hash', 'overlay', 'selection', 'cases', 'cursor', 'totals', 'cost_micro_usd',
        'estimated_cost_micro_usd', 'budget_micro_usd', 'error', 'created_by', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'overlay' => 'array',
        'selection' => 'array',
        'cases' => 'array',
        'totals' => 'array',
        'cursor' => 'integer',
        'cost_micro_usd' => 'integer',
        'estimated_cost_micro_usd' => 'integer',
        'budget_micro_usd' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /** @return HasMany<SimulationResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(SimulationResult::class, 'simulation_id');
    }

    /** @return BelongsTo<Proposal, $this> */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class, 'proposal_id');
    }

    /** @return BelongsTo<Playbook, $this> */
    public function playbook(): BelongsTo
    {
        return $this->belongsTo(Playbook::class, 'playbook_id');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, self::FINISHED_STATUSES, true);
    }

    public function comparesBaseline(): bool
    {
        return $this->baseline_config_hash !== null;
    }

    /** @return array<string, int> */
    public static function emptyTotals(int $cases): array
    {
        return ['cases' => $cases, 'done' => 0, 'pass' => 0, 'fail' => 0, 'regressed' => 0, 'improved' => 0, 'changed' => 0, 'error' => 0, 'cached' => 0];
    }

    /** @return array<string, mixed> */
    public function toUiArray(): array
    {
        $totals = $this->totals ?? self::emptyTotals(count($this->cases ?? []));

        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'status' => $this->status,
            'proposal_id' => $this->proposal_id,
            'playbook_id' => $this->playbook_id,
            'candidate_config_hash' => $this->candidate_config_hash,
            'baseline_config_hash' => $this->baseline_config_hash,
            'compares_baseline' => $this->comparesBaseline(),
            'selection' => $this->selection,
            'totals' => $totals,
            'progress' => $totals['cases'] > 0 ? round($totals['done'] / $totals['cases'], 3) : 1.0,
            'cost_micro_usd' => $this->cost_micro_usd,
            'cost_usd' => round($this->cost_micro_usd / 1_000_000, 4),
            'estimated_cost_micro_usd' => $this->estimated_cost_micro_usd,
            'estimated_cost_usd' => round($this->estimated_cost_micro_usd / 1_000_000, 4),
            'budget_micro_usd' => $this->budget_micro_usd,
            'budget_usd' => $this->budget_micro_usd !== null ? round($this->budget_micro_usd / 1_000_000, 4) : null,
            'error' => $this->error,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
