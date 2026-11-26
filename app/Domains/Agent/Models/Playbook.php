<?php

namespace App\Domains\Agent\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Operational settings (mode, rollout, always_human, priority) live here and
 * are not versioned; the instruction content lives in PlaybookVersion.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $slug
 * @property string $name
 * @property string $mode
 * @property int $rollout_percent
 * @property bool $always_human
 * @property int $priority
 * @property int|null $live_version_id
 * @property Carbon|null $archived_at
 * @property Carbon|null $auto_enabled_at
 * @property int|null $auto_enabled_by
 * @property string|null $auto_hold
 * @property int|null $auto_hold_simulation_id
 * @property Carbon|null $demoted_at
 * @property string|null $demotion_reason
 * @property-read PlaybookVersion|null $liveVersion
 */
class Playbook extends Model
{
    public const MODE_OFF = 'off';

    public const MODE_DRAFT = 'draft';

    public const MODE_AUTO = 'auto';

    public const MODES = [self::MODE_OFF, self::MODE_DRAFT, self::MODE_AUTO];

    protected $table = 'agent_playbooks';

    protected $fillable = [
        'workspace_id',
        'slug',
        'name',
        'mode',
        'rollout_percent',
        'always_human',
        'priority',
        'live_version_id',
        'archived_at',
        'auto_enabled_at',
        'auto_enabled_by',
        'auto_hold',
        'auto_hold_simulation_id',
        'demoted_at',
        'demotion_reason',
    ];

    /** Auto-send waits while an approved change to this playbook is re-tested. */
    public const HOLD_RETESTING = 'retesting';

    protected $casts = [
        'always_human' => 'boolean',
        'rollout_percent' => 'integer',
        'priority' => 'integer',
        'archived_at' => 'datetime',
        'auto_enabled_at' => 'datetime',
        'demoted_at' => 'datetime',
    ];

    /** @return BelongsTo<PlaybookVersion, $this> */
    public function liveVersion(): BelongsTo
    {
        return $this->belongsTo(PlaybookVersion::class, 'live_version_id');
    }

    /** @return HasMany<PlaybookVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(PlaybookVersion::class, 'playbook_id')->orderByDesc('version');
    }

    /**
     * Playbooks the triage catalogue may choose: not off, not archived, with
     * a live version.
     *
     * @param  Builder<Playbook>  $query
     * @return Builder<Playbook>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->whereNull('archived_at')
            ->where('mode', '!=', self::MODE_OFF)
            ->whereNotNull('live_version_id');
    }

    /** @return array<string, mixed> */
    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'mode' => $this->mode,
            'rollout_percent' => $this->rollout_percent,
            'always_human' => $this->always_human,
            'priority' => $this->priority,
            'live_version_id' => $this->live_version_id,
            'live_version' => $this->liveVersion?->version,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'auto_hold' => $this->auto_hold,
            'auto_enabled_at' => $this->auto_enabled_at?->toIso8601String(),
            'demoted_at' => $this->demoted_at?->toIso8601String(),
            'demotion_reason' => $this->demotion_reason,
        ];
    }
}
