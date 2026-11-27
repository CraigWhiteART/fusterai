<?php

namespace App\Domains\Conversation\Models;

use App\Domains\Customer\Models\Customer;
use App\Domains\Mailbox\Models\Mailbox;
use App\Domains\Mailbox\Sync\Jobs\ArchiveConversationMailJob;
use App\Enums\ChannelType;
use App\Enums\ConversationPriority;
use App\Enums\ConversationStatus;
use App\Models\ConversationRead;
use App\Models\User;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property bool $is_unread
 * @property-read Mailbox|null $mailbox
 * @property-read Customer|null $customer
 * @property-read User|null $assignedUser
 */
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    use LogsActivity;
    use Searchable;
    use SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'priority', 'assigned_user_id', 'subject', 'snoozed_until'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('conversation');
    }

    protected static function booted(): void
    {
        // Closed here (by an agent, the API, a bulk action or an automation)
        // = done in Gmail too. InboxReconciler closes with a raw update, so
        // mail that is already archived never comes back through here.
        static::updated(function (Conversation $conversation): void {
            if ($conversation->wasChanged('status') && $conversation->status === ConversationStatus::Closed && ArchiveConversationMailJob::enabled()) {
                ArchiveConversationMailJob::dispatch($conversation->id)->afterCommit();
            }
        });
    }

    protected static function newFactory(): ConversationFactory
    {
        return ConversationFactory::new();
    }

    protected $fillable = [
        'workspace_id',
        'mailbox_id',
        'customer_id',
        'assigned_user_id',
        'status',
        'priority',
        'subject',
        'channel_type',
        'channel_id',
        'ai_summary',
        'ai_tags',
        'last_reply_at',
        'snoozed_until',
        'starred',
        'gm_thrid',
        'imported',
        'handover_reason',
    ];

    /**
     * The live-chat channel secret: only the visitor who started the chat
     * receives it (see chatChannelName()).
     */
    protected $hidden = ['chat_token'];

    protected $casts = [
        'status' => ConversationStatus::class,
        'priority' => ConversationPriority::class,
        'channel_type' => ChannelType::class,
        'ai_tags' => 'array',
        'last_reply_at' => 'datetime',
        'snoozed_until' => 'datetime',
        'starred' => 'boolean',
        // gm_thrid is numeric(20,0) and stays a decimal string: never cast it to int.
        'imported' => 'boolean',
    ];

    // ── Scout ────────────────────────────────────────────────────

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'subject' => $this->subject,
            'status' => $this->status,
            'priority' => $this->priority,
            'customer' => $this->customer?->name,
            'customer_email' => $this->customer?->email,
            'last_reply_at' => $this->last_reply_at?->timestamp,
        ];
    }

    public function searchableAs(): string
    {
        return 'conversations';
    }

    // ── Live chat ────────────────────────────────────────────────

    /**
     * The visitor's chat token, created on first use. Agent replies and
     * agent-typing events are broadcast on the public `livechat.{token}`
     * channel, so the token (not the sequential id) is what keeps one
     * visitor from listening to another visitor's chat.
     */
    public function ensureChatToken(): string
    {
        if (! filled($this->chat_token)) {
            $this->forceFill(['chat_token' => Str::random(48)])->saveQuietly();
        }

        return (string) $this->chat_token;
    }

    /** Public widget channel name, or null when no token was ever issued. */
    public function chatChannelName(): ?string
    {
        return filled($this->chat_token) ? 'livechat.'.$this->chat_token : null;
    }

    // ── Relations ────────────────────────────────────────────────

    /** @return BelongsTo<Mailbox, $this> */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /** @return HasMany<Thread, $this> */
    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class)->orderBy('created_at');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'conversation_tag');
    }

    public function folders(): BelongsToMany
    {
        return $this->belongsToMany(Folder::class, 'conversation_folder');
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'followers');
    }

    public function aiSuggestions(): HasMany
    {
        return $this->hasMany(AiSuggestion::class);
    }

    public function reads(): HasMany
    {
        return $this->hasMany(ConversationRead::class);
    }

    // ── Scopes ───────────────────────────────────────────────────

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', 'closed');
    }

    public function scopeAssignedTo(Builder $query, int $userId): Builder
    {
        return $query->where('assigned_user_id', $userId);
    }

    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull('assigned_user_id');
    }

    public function scopeForMailbox(Builder $query, int $mailboxId): Builder
    {
        return $query->where('mailbox_id', $mailboxId);
    }

    public function scopeSnoozed(Builder $query): Builder
    {
        return $query->whereNotNull('snoozed_until')->where('snoozed_until', '>', now());
    }

    // ── Helpers ──────────────────────────────────────────────────

    public function isOpen(): bool
    {
        return $this->status === ConversationStatus::Open;
    }

    public function isClosed(): bool
    {
        return $this->status === ConversationStatus::Closed;
    }

    public function isSnoozed(): bool
    {
        return $this->snoozed_until && $this->snoozed_until->isFuture();
    }

    public function latestThread(): ?Thread
    {
        /** @var Thread|null */
        return $this->threads()->latest()->first();
    }

    public function lastCustomerThread(): ?Thread
    {
        /** @var Thread|null */
        return $this->threads()
            ->where('type', 'message')
            ->whereNotNull('customer_id')
            ->latest()
            ->first();
    }
}
