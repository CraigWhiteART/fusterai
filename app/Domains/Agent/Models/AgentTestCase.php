<?php

namespace App\Domains\Agent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A frozen case plus what a good answer must (not) contain.
 *
 * Expectation: {must_include: string[], must_not_include: string[],
 * expected_outcome: draft|handover|null, expected_handover_reason: string|null,
 * expected_actions: string[], notes: string|null}
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $playbook_id
 * @property int $frozen_case_id
 * @property string $name
 * @property array<string, mixed> $expectation
 * @property bool $pinned
 * @property string $source
 * @property string $status
 * @property int|null $proposal_id
 * @property int|null $feedback_event_id
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property-read FrozenCase $frozenCase
 * @property-read Playbook|null $playbook
 * @property-read SimulationResult|null $lastResult
 */
class AgentTestCase extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_ARCHIVED];

    public const SOURCES = ['feedback', 'mining', 'manual', 'qa'];

    public const OUTCOMES = ['draft', 'handover'];

    protected $table = 'agent_test_cases';

    protected $fillable = [
        'workspace_id', 'playbook_id', 'frozen_case_id', 'name', 'expectation', 'pinned', 'source',
        'status', 'proposal_id', 'feedback_event_id', 'created_by',
    ];

    protected $casts = [
        'expectation' => 'array',
        'pinned' => 'boolean',
    ];

    /** @return BelongsTo<FrozenCase, $this> */
    public function frozenCase(): BelongsTo
    {
        return $this->belongsTo(FrozenCase::class, 'frozen_case_id');
    }

    /** @return BelongsTo<Playbook, $this> */
    public function playbook(): BelongsTo
    {
        return $this->belongsTo(Playbook::class, 'playbook_id');
    }

    /**
     * The most recent simulation result for this test case (any kind:
     * batch, gate, playbook tests).
     *
     * @return HasOne<SimulationResult, $this>
     */
    public function lastResult(): HasOne
    {
        return $this->hasOne(SimulationResult::class, 'test_case_id')->latestOfMany();
    }

    /**
     * @return array{must_include: list<string>, must_not_include: list<string>, expected_outcome: string|null, expected_handover_reason: string|null, expected_actions: list<string>, notes: string|null}
     */
    public static function normalizeExpectation(mixed $expectation): array
    {
        $e = is_array($expectation) ? $expectation : [];
        $list = fn (mixed $v): array => array_values(array_unique(array_filter(
            array_map(fn ($s) => trim((string) $s), is_array($v) ? $v : []),
            fn ($s) => $s !== '',
        )));
        $outcome = $e['expected_outcome'] ?? null;

        return [
            'must_include' => $list($e['must_include'] ?? []),
            'must_not_include' => $list($e['must_not_include'] ?? []),
            'expected_outcome' => in_array($outcome, self::OUTCOMES, true) ? $outcome : null,
            'expected_handover_reason' => filled($e['expected_handover_reason'] ?? null) ? (string) $e['expected_handover_reason'] : null,
            'expected_actions' => $list($e['expected_actions'] ?? []),
            'notes' => filled($e['notes'] ?? null) ? mb_substr((string) $e['notes'], 0, 2000) : null,
        ];
    }

    /** @return array<string, mixed> */
    public function toUiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'playbook_id' => $this->playbook_id,
            'playbook' => $this->playbook ? ['id' => $this->playbook->id, 'slug' => $this->playbook->slug, 'name' => $this->playbook->name] : null,
            'frozen_case_id' => $this->frozen_case_id,
            'frozen_case' => $this->relationLoaded('frozenCase') ? $this->frozenCase->toUiArray() : null,
            'expectation' => self::normalizeExpectation($this->expectation),
            'pinned' => $this->pinned,
            'source' => $this->source,
            'status' => $this->status,
            'proposal_id' => $this->proposal_id,
            'feedback_event_id' => $this->feedback_event_id,
            'last_result' => $this->lastResult !== null ? [
                'verdict' => $this->lastResult->effectiveVerdict(),
                'raw_verdict' => $this->lastResult->verdict,
                'human_override' => $this->lastResult->human_override,
                'simulation_id' => $this->lastResult->simulation_id,
                'result_id' => $this->lastResult->id,
                'at' => $this->lastResult->created_at?->toIso8601String(),
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
