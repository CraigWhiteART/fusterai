<?php

namespace App\Domains\Agent\Qa\Models;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * QA review of one staff reply on a closed conversation. docs/agent-qa.md.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $conversation_id
 * @property int|null $reviewed_thread_id
 * @property int|null $customer_thread_id
 * @property string|null $author_type
 * @property int|null $author_user_id
 * @property int|null $run_id
 * @property int|null $playbook_id
 * @property string|null $playbook_slug
 * @property string $status
 * @property string|null $skip_reason
 * @property string $trigger
 * @property bool $sampled
 * @property bool $forced
 * @property array<string, mixed>|null $triage
 * @property int|null $triage_score
 * @property array<string, int|null>|null $scores
 * @property string|null $overall
 * @property string|null $failure_class
 * @property string|null $summary
 * @property string|null $gap
 * @property string|null $gap_topic
 * @property string|null $suggested_fix
 * @property int|null $gap_id
 * @property int|null $proposal_id
 * @property string|null $evidence_source
 * @property string|null $reviewer_model
 * @property string $reviewer_prompt_version
 * @property int $tokens_in
 * @property int $tokens_out
 * @property int $triage_cost_micro_usd
 * @property int $review_cost_micro_usd
 * @property int $cost_micro_usd
 * @property string|null $human_verdict
 * @property string|null $human_note
 * @property int|null $human_by
 * @property Carbon|null $human_at
 * @property string|null $error
 * @property Carbon|null $replied_at
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Playbook|null $playbook
 * @property-read Conversation|null $conversation
 * @property-read Thread|null $reviewedThread
 * @property-read Thread|null $customerThread
 * @property-read AgentRun|null $run
 */
class QaReview extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_TRIAGED = 'triaged';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_TRIAGED, self::STATUS_REVIEWED, self::STATUS_SKIPPED, self::STATUS_FAILED];

    public const AUTHOR_AI = 'ai';

    public const AUTHOR_HUMAN = 'human';

    public const AUTHOR_AI_EDITED = 'ai_edited';

    public const AUTHOR_TYPES = [self::AUTHOR_AI, self::AUTHOR_HUMAN, self::AUTHOR_AI_EDITED];

    public const FAILURE_CLASSES = ['none', 'missing_instruction', 'missing_knowledge', 'missing_tool', 'needed_human', 'agent_error', 'human_error'];

    /** Classes whose gaps are grouped (proposal / capability requests). */
    public const GAP_CLASSES = ['missing_instruction', 'missing_knowledge', 'missing_tool'];

    public const TRIGGERS = ['closed', 'backfill', 'unhappy_return', 'manual'];

    public const HUMAN_VERDICTS = ['dismissed', 'incorrect'];

    public const SCORE_KEYS = ['resolved', 'policy_consistency', 'factual_grounding', 'tone_match', 'clarity'];

    protected $table = 'agent_qa_reviews';

    protected $fillable = [
        'workspace_id', 'conversation_id', 'reviewed_thread_id', 'customer_thread_id', 'author_type', 'author_user_id',
        'run_id', 'playbook_id', 'playbook_slug', 'status', 'skip_reason', 'trigger', 'sampled', 'forced', 'triage',
        'triage_score', 'scores', 'overall', 'failure_class', 'summary', 'gap', 'gap_topic', 'suggested_fix', 'gap_id',
        'proposal_id', 'evidence_source', 'reviewer_model', 'reviewer_prompt_version', 'tokens_in', 'tokens_out',
        'triage_cost_micro_usd', 'review_cost_micro_usd', 'cost_micro_usd', 'human_verdict', 'human_note', 'human_by',
        'human_at', 'error', 'replied_at', 'reviewed_at',
    ];

    protected $casts = [
        'sampled' => 'boolean',
        'forced' => 'boolean',
        'triage' => 'array',
        'scores' => 'array',
        'triage_score' => 'integer',
        'tokens_in' => 'integer',
        'tokens_out' => 'integer',
        'triage_cost_micro_usd' => 'integer',
        'review_cost_micro_usd' => 'integer',
        'cost_micro_usd' => 'integer',
        'human_at' => 'datetime',
        'replied_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<Thread, $this> */
    public function reviewedThread(): BelongsTo
    {
        return $this->belongsTo(Thread::class, 'reviewed_thread_id');
    }

    /** @return BelongsTo<Thread, $this> */
    public function customerThread(): BelongsTo
    {
        return $this->belongsTo(Thread::class, 'customer_thread_id');
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'run_id');
    }

    /** @return BelongsTo<Playbook, $this> */
    public function playbook(): BelongsTo
    {
        return $this->belongsTo(Playbook::class);
    }

    /** @return BelongsTo<QaGap, $this> */
    public function gapGroup(): BelongsTo
    {
        return $this->belongsTo(QaGap::class, 'gap_id');
    }

    /**
     * Reviews that count in aggregates: not dismissed / marked incorrect by a human.
     *
     * @param  Builder<QaReview>  $query
     * @return Builder<QaReview>
     */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->whereNull('human_verdict');
    }
}
