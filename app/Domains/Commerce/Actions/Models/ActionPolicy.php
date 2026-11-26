<?php

namespace App\Domains\Commerce\Actions\Models;

use App\Domains\Agent\Models\Playbook;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who may run an action, and within which limits. No row = `never`.
 * Edited only through PolicyController (admins); see docs/agent-actions.md.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $action_key
 * @property int|null $playbook_id
 * @property string $mode
 * @property array<string, mixed>|null $limits
 * @property bool $override_workspace
 * @property int|null $updated_by
 */
class ActionPolicy extends Model
{
    public const ALWAYS = 'always';

    public const APPROVAL = 'approval';

    public const NEVER = 'never';

    public const MODES = [self::ALWAYS, self::APPROVAL, self::NEVER];

    /** Higher = more restrictive. */
    public const STRICTNESS = [self::ALWAYS => 0, self::APPROVAL => 1, self::NEVER => 2];

    protected $table = 'agent_action_policies';

    protected $fillable = [
        'workspace_id',
        'action_key',
        'playbook_id',
        'mode',
        'limits',
        'override_workspace',
        'updated_by',
    ];

    protected $casts = [
        'limits' => 'array',
        'override_workspace' => 'boolean',
    ];

    /** @return BelongsTo<Playbook, $this> */
    public function playbook(): BelongsTo
    {
        return $this->belongsTo(Playbook::class);
    }
}
