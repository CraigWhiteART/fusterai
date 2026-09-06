<?php

namespace Modules\CommerceAssist\Models;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HistoryScan extends Model
{
    protected $table = 'commerce_assist_history_scans';

    protected $fillable = [
        'workspace_id',
        'started_by',
        'status',
        'total_count',
        'scanned_count',
        'proposed_count',
        'skipped_count',
        'error',
        'finished_at',
    ];

    protected $casts = [
        'finished_at' => 'datetime',
    ];

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /** @return HasMany<HistoryLearning, $this> */
    public function learnings(): HasMany
    {
        return $this->hasMany(HistoryLearning::class, 'scan_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }

    /** @return array<string, mixed> */
    public function toUiArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'total_count' => $this->total_count,
            'scanned_count' => $this->scanned_count,
            'proposed_count' => $this->proposed_count,
            'skipped_count' => $this->skipped_count,
            'error' => $this->error,
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
