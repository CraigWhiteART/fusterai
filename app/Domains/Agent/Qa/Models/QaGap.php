<?php

namespace App\Domains\Agent\Qa\Models;

use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Models\Proposal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A group of similar QA gaps (same failure class, same playbook, similar
 * text). docs/agent-qa.md "Gaps".
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $failure_class
 * @property int|null $playbook_id
 * @property string|null $topic
 * @property string $label
 * @property list<string> $terms
 * @property int $review_count
 * @property list<int>|null $conversation_ids
 * @property string $status
 * @property int|null $proposal_id
 * @property int $cost_micro_usd
 * @property string|null $error
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $proposed_at
 * @property Carbon|null $created_at
 * @property-read Playbook|null $playbook
 * @property-read Proposal|null $proposal
 */
class QaGap extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_DRAFTING = 'drafting';

    public const STATUS_PROPOSED = 'proposed';

    public const STATUS_FAILED = 'failed';

    /** Its proposal was applied / rejected / reverted: new gaps start a new group. */
    public const STATUS_CLOSED = 'closed';

    protected $table = 'agent_qa_gaps';

    protected $fillable = [
        'workspace_id', 'failure_class', 'playbook_id', 'topic', 'label', 'terms', 'review_count', 'conversation_ids',
        'status', 'proposal_id', 'cost_micro_usd', 'error', 'last_seen_at', 'proposed_at',
    ];

    protected $casts = [
        'terms' => 'array',
        'conversation_ids' => 'array',
        'review_count' => 'integer',
        'cost_micro_usd' => 'integer',
        'last_seen_at' => 'datetime',
        'proposed_at' => 'datetime',
    ];

    /** @return HasMany<QaReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(QaReview::class, 'gap_id');
    }

    /** @return BelongsTo<Playbook, $this> */
    public function playbook(): BelongsTo
    {
        return $this->belongsTo(Playbook::class);
    }

    /** @return BelongsTo<Proposal, $this> */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }
}
