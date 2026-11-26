<?php

namespace App\Domains\Agent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $simulation_id
 * @property int $frozen_case_id
 * @property int|null $test_case_id
 * @property int|null $agent_run_id
 * @property int|null $baseline_run_id
 * @property int|null $rerun_run_id
 * @property string|null $config_hash
 * @property string|null $case_key
 * @property array<string, mixed>|null $scores
 * @property array<string, mixed>|null $baseline_scores
 * @property bool|null $passed
 * @property bool|null $baseline_passed
 * @property string|null $verdict
 * @property string|null $judge_notes
 * @property bool $cached
 * @property string|null $human_override
 * @property string|null $human_override_note
 * @property int|null $human_override_by
 * @property int $cost_micro_usd
 * @property string|null $error
 * @property-read FrozenCase $frozenCase
 * @property-read AgentTestCase|null $testCase
 * @property-read AgentRun|null $run
 * @property-read AgentRun|null $baselineRun
 */
class SimulationResult extends Model
{
    public const VERDICT_PASS = 'pass';

    public const VERDICT_FAIL = 'fail';

    public const VERDICT_REGRESSED = 'regressed';

    public const VERDICT_IMPROVED = 'improved';

    public const VERDICT_CHANGED = 'changed';

    public const VERDICT_ERROR = 'error';

    public const VERDICTS = [self::VERDICT_PASS, self::VERDICT_FAIL, self::VERDICT_REGRESSED, self::VERDICT_IMPROVED, self::VERDICT_CHANGED, self::VERDICT_ERROR];

    protected $table = 'agent_simulation_results';

    protected $fillable = [
        'simulation_id', 'frozen_case_id', 'test_case_id', 'agent_run_id', 'baseline_run_id', 'rerun_run_id',
        'config_hash', 'case_key', 'scores', 'baseline_scores', 'passed', 'baseline_passed', 'verdict',
        'judge_notes', 'cached', 'human_override', 'human_override_note', 'human_override_by', 'cost_micro_usd', 'error',
    ];

    protected $casts = [
        'scores' => 'array',
        'baseline_scores' => 'array',
        'passed' => 'boolean',
        'baseline_passed' => 'boolean',
        'cached' => 'boolean',
        'cost_micro_usd' => 'integer',
    ];

    /** @return BelongsTo<Simulation, $this> */
    public function simulation(): BelongsTo
    {
        return $this->belongsTo(Simulation::class, 'simulation_id');
    }

    /** @return BelongsTo<FrozenCase, $this> */
    public function frozenCase(): BelongsTo
    {
        return $this->belongsTo(FrozenCase::class, 'frozen_case_id');
    }

    /** @return BelongsTo<AgentTestCase, $this> */
    public function testCase(): BelongsTo
    {
        return $this->belongsTo(AgentTestCase::class, 'test_case_id');
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function baselineRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'baseline_run_id');
    }

    /** The verdict a human set, else the computed one. */
    public function effectiveVerdict(): ?string
    {
        return $this->human_override ?? $this->verdict;
    }
}
