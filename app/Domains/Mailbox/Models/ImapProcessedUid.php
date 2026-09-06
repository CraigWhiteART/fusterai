<?php

namespace App\Domains\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class ImapProcessedUid extends Model
{
    public $timestamps = false;

    protected $table = 'mailbox_imap_processed_uids';

    protected $fillable = [
        'mailbox_id',
        'uid',
    ];

    protected $casts = [
        'uid' => 'integer',
        'created_at' => 'datetime',
    ];

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    /**
     * Newest unseen UIDs that have not been ingested yet.
     *
     * @param  Collection<int, int|string>  $uids
     * @return Collection<int, int>
     */
    public static function pending(int $mailboxId, Collection $uids, int $limit): Collection
    {
        $ids = $uids
            ->map(fn ($uid) => (int) $uid)
            ->filter(fn (int $uid) => $uid > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $processed = static::query()
            ->where('mailbox_id', $mailboxId)
            ->pluck('uid')
            ->map(fn ($uid) => (int) $uid)
            ->flip();

        return $ids
            ->reject(fn (int $uid) => isset($processed[$uid]))
            ->reverse()
            ->take(max(1, $limit))
            ->values();
    }

    public static function remember(int $mailboxId, int $uid): void
    {
        if ($uid < 1) {
            return;
        }

        static::query()->firstOrCreate([
            'mailbox_id' => $mailboxId,
            'uid' => $uid,
        ]);
    }
}
