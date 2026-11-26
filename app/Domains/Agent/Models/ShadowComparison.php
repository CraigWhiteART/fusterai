<?php

namespace App\Domains\Agent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shadow mode: what the agent would have sent vs what the human actually
 * sent (Phase 8, docs/agent-autosend.md).
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $run_id
 * @property int|null $conversation_id
 * @property int|null $playbook_id
 * @property int|null $human_thread_id
 * @property string $status
 * @property string|null $skip_reason
 * @property bool $would_auto_send
 * @property bool $eligible_if_auto
 * @property string|null $ai_decision
 * @property bool|null $agreed
 * @property float|null $score
 * @property array<string, mixed>|null $scores
 * @property int $cost_micro_usd
 */
class ShadowComparison extends Model
{
    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $table = 'agent_shadow_comparisons';

    protected $fillable = [
        'workspace_id', 'run_id', 'conversation_id', 'playbook_id', 'human_thread_id', 'status', 'skip_reason',
        'would_auto_send', 'eligible_if_auto', 'ai_decision', 'agreed', 'score', 'scores', 'cost_micro_usd',
    ];

    protected $casts = [
        'would_auto_send' => 'boolean',
        'eligible_if_auto' => 'boolean',
        'agreed' => 'boolean',
        'score' => 'float',
        'scores' => 'array',
        'cost_micro_usd' => 'integer',
    ];

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
}
