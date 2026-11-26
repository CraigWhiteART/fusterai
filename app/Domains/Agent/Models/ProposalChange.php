<?php

namespace App\Domains\Agent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One target of a proposal and the field-level ops against it.
 *
 * @property int $id
 * @property int $proposal_id
 * @property int $position
 * @property string $target_type
 * @property int|null $target_id
 * @property int|null $base_version_id
 * @property list<array<string, mixed>> $ops
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property list<array<string, mixed>>|null $diff
 */
class ProposalChange extends Model
{
    public const TARGET_VOICE = 'voice';

    public const TARGET_PLAYBOOK = 'playbook';

    public const TARGET_KNOWLEDGE = 'knowledge';

    public const TARGET_EXAMPLE = 'example';

    public const TARGET_TEST_CASE = 'test_case';

    public const TARGETS = [self::TARGET_VOICE, self::TARGET_PLAYBOOK, self::TARGET_KNOWLEDGE, self::TARGET_EXAMPLE, self::TARGET_TEST_CASE];

    protected $table = 'agent_proposal_changes';

    protected $fillable = ['proposal_id', 'position', 'target_type', 'target_id', 'base_version_id', 'ops', 'before', 'after', 'diff'];

    protected $casts = [
        'ops' => 'array',
        'before' => 'array',
        'after' => 'array',
        'diff' => 'array',
        'target_id' => 'integer',
        'base_version_id' => 'integer',
        'position' => 'integer',
    ];

    /** @return BelongsTo<Proposal, $this> */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class, 'proposal_id');
    }
}
