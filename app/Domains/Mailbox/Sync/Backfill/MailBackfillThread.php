<?php

namespace App\Domains\Mailbox\Sync\Backfill;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * ThreadFilter's decision for one mail thread in one backfill run, plus the
 * per-thread checkpoints of the body and materialise stages.
 *
 * @property int $id
 * @property int $run_id
 * @property string $thread_key
 * @property string|null $gm_thrid
 * @property string $decision
 * @property string $reason
 * @property bool $active
 * @property list<int> $message_ids
 * @property int $message_count
 * @property int $inbound_count
 * @property int $outbound_count
 * @property string|null $customer_email
 * @property string|null $subject
 * @property Carbon|null $first_at
 * @property Carbon|null $last_at
 * @property Carbon|null $bodies_fetched_at
 * @property Carbon|null $materialised_at
 */
class MailBackfillThread extends Model
{
    protected $table = 'mail_backfill_threads';

    protected $guarded = ['id'];

    protected $casts = [
        'active' => 'boolean',
        'message_ids' => 'array',
        'message_count' => 'integer',
        'inbound_count' => 'integer',
        'outbound_count' => 'integer',
        'first_at' => 'datetime',
        'last_at' => 'datetime',
        'bodies_fetched_at' => 'datetime',
        'materialised_at' => 'datetime',
    ];

    /** @return BelongsTo<MailBackfillRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(MailBackfillRun::class, 'run_id');
    }
}
