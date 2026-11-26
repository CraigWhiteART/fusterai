<?php

namespace App\Domains\Mailbox\Sync\Models;

use App\Domains\Mailbox\Models\Mailbox;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Sync cursor for one mailbox folder.
 *
 * @property int $id
 * @property int $mailbox_id
 * @property string $folder
 * @property int|null $uidvalidity
 * @property int|null $uidnext
 * @property int $last_uid
 * @property int|null $live_from_uid
 * @property int|null $backfill_lowest_uid
 * @property int|null $backfill_stop_uid
 * @property Carbon|null $backfill_since
 * @property Carbon|null $backfill_completed_at
 * @property string $status
 * @property int $synced_count
 * @property int $backfilled_count
 * @property int $uidvalidity_resets
 * @property int $consecutive_failures
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $last_backfilled_at
 * @property string|null $last_error
 * @property Carbon|null $last_error_at
 */
class MailSyncState extends Model
{
    public const STATUS_IDLE = 'idle';

    public const STATUS_SYNCING = 'syncing';

    public const STATUS_BACKFILLING = 'backfilling';

    public const STATUS_FAILED = 'failed';

    protected $table = 'mail_sync_states';

    protected $guarded = ['id'];

    protected $attributes = [
        'last_uid' => 0,
        'status' => self::STATUS_IDLE,
        'synced_count' => 0,
        'backfilled_count' => 0,
        'uidvalidity_resets' => 0,
        'consecutive_failures' => 0,
    ];

    protected $casts = [
        'uidvalidity' => 'integer',
        'uidnext' => 'integer',
        'last_uid' => 'integer',
        'live_from_uid' => 'integer',
        'backfill_lowest_uid' => 'integer',
        'backfill_stop_uid' => 'integer',
        'backfill_since' => 'date',
        'backfill_completed_at' => 'datetime',
        'synced_count' => 'integer',
        'backfilled_count' => 'integer',
        'uidvalidity_resets' => 'integer',
        'consecutive_failures' => 'integer',
        'last_synced_at' => 'datetime',
        'last_backfilled_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function isBackfillComplete(): bool
    {
        return $this->backfill_completed_at !== null;
    }
}
