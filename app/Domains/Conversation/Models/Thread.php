<?php

namespace App\Domains\Conversation\Models;

use App\Domains\Customer\Models\Customer;
use App\Domains\Mailbox\Sync\Models\MailMessage;
use App\Enums\ThreadType;
use App\Models\User;
use Database\Factories\ThreadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Thread extends Model
{
    /** @use HasFactory<ThreadFactory> */
    use HasFactory;

    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'body', 'source'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('thread');
    }

    protected static function newFactory(): ThreadFactory
    {
        return ThreadFactory::new();
    }

    protected $fillable = [
        'conversation_id',
        'user_id',
        'customer_id',
        'type',
        'body',
        'body_plain',
        'source',
        'meta',
        'send_at',
        'tracking_token',
        'opened_at',
        'message_id',
        'sent_at',
        'origin',
        'mail_message_id',
    ];

    protected $casts = [
        'type' => ThreadType::class,
        'meta' => 'array',
        'send_at' => 'datetime',
        'opened_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    /** Written in fusterai (agent reply, note, or a customer message that arrived live). */
    public const ORIGIN_FUSTERAI = 'fusterai';

    /** Written by staff directly in Gmail and picked up by the mail sync. */
    public const ORIGIN_GMAIL = 'gmail';

    /** Materialised from mailbox history (historical ingest / backfill). */
    public const ORIGIN_IMPORT = 'import';

    /** Sent by the AI agent without a human. */
    public const ORIGIN_AI = 'ai';

    // ── Relations ────────────────────────────────────────────────

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<MailMessage, $this> */
    public function mailMessage(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class);
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    // ── Helpers ──────────────────────────────────────────────────

    public function isFromCustomer(): bool
    {
        return $this->customer_id !== null;
    }

    public function isNote(): bool
    {
        return $this->type === ThreadType::Note;
    }

    public function isMessage(): bool
    {
        return $this->type === ThreadType::Message;
    }

    public function author(): User|Customer|null
    {
        /** @var User|Customer|null */
        return $this->user ?? $this->customer;
    }

    public function authorName(): string
    {
        $author = $this->author();

        return $author !== null ? ($author->name ?? 'Unknown') : 'Unknown';
    }
}
