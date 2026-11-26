<?php

namespace App\Domains\Mailbox\Sync\Models;

use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Mailbox\Models\Mailbox;
use App\Domains\Mailbox\Sync\Enums\MailClassification;
use App\Domains\Mailbox\Sync\Enums\MailDirection;
use App\Domains\Mailbox\Sync\Parsing\ParsedMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per message in the mailbox (see the create_mail_messages migration).
 *
 * gm_msgid / gm_thrid are numeric(20,0) and come back as decimal strings;
 * never cast them to int.
 *
 * @property int $id
 * @property int $mailbox_id
 * @property string|null $gm_msgid
 * @property string|null $gm_thrid
 * @property string $folder
 * @property int $uidvalidity
 * @property int $uid
 * @property string|null $message_id
 * @property string|null $in_reply_to
 * @property list<string>|null $references
 * @property string|null $from_email
 * @property string|null $from_name
 * @property list<array{email: string, name: string}>|null $reply_to
 * @property list<array{email: string, name: string}>|null $to
 * @property list<array{email: string, name: string}>|null $cc
 * @property string|null $subject
 * @property Carbon|null $sent_at
 * @property Carbon|null $internal_date
 * @property MailDirection $direction
 * @property string|null $customer_email
 * @property list<string>|null $labels
 * @property int|null $size
 * @property bool|null $has_attachments
 * @property list<array{name: string, mime: string, size: int, inline: bool}>|null $attachment_meta
 * @property int|null $backfill_run_id
 * @property string|null $body_html
 * @property string|null $body_text
 * @property string|null $body_clean
 * @property Carbon|null $body_fetched_at
 * @property MailClassification|null $classification
 * @property int|null $thread_id
 * @property int|null $conversation_id
 * @property array<string, string>|null $headers
 * @property Carbon|null $ingested_at
 * @property string|null $ingest_outcome
 */
class MailMessage extends Model
{
    protected $table = 'mail_messages';

    protected $guarded = ['id'];

    protected $casts = [
        'uidvalidity' => 'integer',
        'uid' => 'integer',
        'references' => 'array',
        'reply_to' => 'array',
        'to' => 'array',
        'cc' => 'array',
        'labels' => 'array',
        'headers' => 'array',
        'sent_at' => 'datetime',
        'internal_date' => 'datetime',
        'body_fetched_at' => 'datetime',
        'ingested_at' => 'datetime',
        'size' => 'integer',
        'has_attachments' => 'boolean',
        'attachment_meta' => 'array',
        'backfill_run_id' => 'integer',
        'direction' => MailDirection::class,
        'classification' => MailClassification::class,
    ];

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    /** @param Builder<MailMessage> $query */
    public function scopeForMailbox(Builder $query, int $mailboxId): void
    {
        $query->where('mailbox_id', $mailboxId);
    }

    /** Rows whose body has not been downloaded yet. */
    public function scopeWithoutBody(Builder $query): void
    {
        $query->whereNull('body_fetched_at');
    }

    /**
     * The header view of this row, as MessageParser would have produced it,
     * so ThreadResolver / MessageClassifier can run on the ledger alone.
     */
    public function toParsedMessage(): ParsedMessage
    {
        $from = $this->from_email !== null ? ['email' => $this->from_email, 'name' => (string) ($this->from_name ?? '')] : null;

        return new ParsedMessage(
            messageId: $this->message_id,
            messageIdSynthetic: $this->hasSyntheticMessageId(),
            inReplyTo: $this->in_reply_to,
            references: array_values($this->references ?? []),
            from: $from,
            replyTo: array_values($this->reply_to ?? []),
            to: array_values($this->to ?? []),
            cc: array_values($this->cc ?? []),
            subject: $this->subject,
            sentAt: $this->sent_at?->toDateTimeImmutable(),
            headers: $this->headers ?? [],
            bodyHtml: $this->body_html,
            bodyText: $this->body_text,
            bodyClean: $this->body_clean,
            hasBody: $this->body_fetched_at !== null,
        );
    }

    /** MessageParser invents an id when the header is missing; never thread on it. */
    public function hasSyntheticMessageId(): bool
    {
        return $this->message_id !== null && str_ends_with($this->message_id, '@missing-message-id.invalid');
    }

    public function hasLabel(string $label): bool
    {
        foreach ($this->labels ?? [] as $l) {
            if (strcasecmp($l, $label) === 0) {
                return true;
            }
        }

        return false;
    }
}
