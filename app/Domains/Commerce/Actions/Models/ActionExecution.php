<?php

namespace App\Domains\Commerce\Actions\Models;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Conversation\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One requested action: the approval queue entry and its audit record.
 * State machine in docs/agent-actions.md ("Executor").
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $conversation_id
 * @property int|null $run_id
 * @property int|null $playbook_id
 * @property string $purpose
 * @property string $action_key
 * @property array<string, mixed> $params
 * @property array<string, mixed>|null $param_errors
 * @property array<string, mixed>|null $params_edited
 * @property string|null $order_number
 * @property string|null $order_id
 * @property string|null $customer_email
 * @property string|null $amount
 * @property string|null $currency
 * @property string $status
 * @property string|null $route
 * @property list<string>|null $approval_reasons
 * @property list<array<string, mixed>>|null $warnings
 * @property string|null $description
 * @property string|null $reason
 * @property list<array<string, mixed>>|null $cited_evidence
 * @property bool $reply_blocking
 * @property string $idempotency_key
 * @property array<string, mixed>|null $policy_snapshot
 * @property array<string, mixed>|null $precondition_snapshot
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $rejected_by
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_reason
 * @property Carbon|null $expires_at
 * @property Carbon|null $executed_at
 * @property Carbon|null $completed_at
 * @property array<string, mixed>|null $request
 * @property array<string, mixed>|null $response
 * @property string|null $error
 * @property array<string, mixed>|null $verify_result
 * @property array<string, mixed>|null $undo
 * @property int $reconcile_attempts
 * @property bool $dry_run
 * @property bool $rewrite_requested
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Conversation|null $conversation
 * @property-read AgentRun|null $run
 * @property-read User|null $approver
 * @property-read User|null $rejecter
 */
class ActionExecution extends Model
{
    public const PROPOSED = 'proposed';

    public const PENDING_APPROVAL = 'pending_approval';

    public const APPROVED = 'approved';

    public const EXECUTING = 'executing';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const REJECTED = 'rejected';

    public const EXPIRED = 'expired';

    public const UNKNOWN = 'unknown';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::PROPOSED, self::PENDING_APPROVAL, self::APPROVED, self::EXECUTING, self::SUCCEEDED,
        self::FAILED, self::REJECTED, self::EXPIRED, self::UNKNOWN, self::CANCELLED,
    ];

    /** Statuses that count against limits (money that has moved or may move). */
    public const COUNTED = [self::APPROVED, self::EXECUTING, self::SUCCEEDED, self::UNKNOWN];

    public const TERMINAL = [self::SUCCEEDED, self::FAILED, self::REJECTED, self::EXPIRED, self::CANCELLED];

    public const ROUTE_UNATTENDED = 'unattended';

    public const ROUTE_APPROVAL = 'approval';

    protected $table = 'agent_action_executions';

    protected $fillable = [
        'workspace_id', 'conversation_id', 'run_id', 'playbook_id', 'purpose', 'action_key',
        'params', 'param_errors', 'params_edited', 'order_number', 'order_id', 'customer_email',
        'amount', 'currency', 'status', 'route', 'approval_reasons', 'warnings', 'description',
        'reason', 'cited_evidence', 'reply_blocking', 'idempotency_key', 'policy_snapshot',
        'precondition_snapshot', 'approved_by', 'approved_at', 'rejected_by', 'rejected_at',
        'rejection_reason', 'expires_at', 'executed_at', 'completed_at', 'request', 'response',
        'error', 'verify_result', 'undo', 'reconcile_attempts', 'dry_run', 'rewrite_requested',
    ];

    protected $casts = [
        'params' => 'array',
        'param_errors' => 'array',
        'params_edited' => 'array',
        'approval_reasons' => 'array',
        'warnings' => 'array',
        'cited_evidence' => 'array',
        'reply_blocking' => 'boolean',
        'policy_snapshot' => 'array',
        'precondition_snapshot' => 'array',
        'request' => 'array',
        'response' => 'array',
        'verify_result' => 'array',
        'undo' => 'array',
        'reconcile_attempts' => 'integer',
        'dry_run' => 'boolean',
        'rewrite_requested' => 'boolean',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'expires_at' => 'datetime',
        'executed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
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

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    /** Marker written into Shopify notes so a reconcile can find this execution's effect. */
    public function marker(): string
    {
        return 'fusterai-action-'.$this->id;
    }

    public function hasMarker(?string $text): bool
    {
        return $text !== null && preg_match('/'.preg_quote($this->marker(), '/').'(?!\d)/', $text) === 1;
    }

    /** Deterministic per execution; Shopify's @idempotent key for refundCreate. */
    public function shopifyIdempotencyKey(): string
    {
        $h = hash('sha256', 'shopify-idem:'.$this->id.':'.$this->idempotency_key);

        return substr($h, 0, 8).'-'.substr($h, 8, 4).'-'.substr($h, 12, 4).'-'.substr($h, 16, 4).'-'.substr($h, 20, 12);
    }

    /**
     * Conditional status change: succeeds for exactly one caller.
     *
     * @param  list<string>  $from
     * @param  array<string, mixed>  $extra
     */
    public function transition(array $from, string $to, array $extra = []): bool
    {
        $values = [...$extra, 'status' => $to, 'updated_at' => now()];
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = json_encode($value);
            }
        }

        $changed = static::query()->whereKey($this->id)->whereIn('status', $from)->update($values) === 1;
        if ($changed) {
            $this->refresh();
        }

        return $changed;
    }
}
