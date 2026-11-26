<?php

namespace App\Domains\Mailbox\Sync;

use App\Domains\Conversation\Models\Attachment;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Conversation\Support\AttachmentStorage;
use App\Domains\Conversation\Support\EmailSubject;
use App\Domains\Conversation\Support\InboundMessageEffects;
use App\Domains\Customer\Models\Customer;
use App\Domains\Mailbox\Models\Mailbox;
use App\Domains\Mailbox\Sync\Enums\MailClassification;
use App\Domains\Mailbox\Sync\Enums\MailDirection;
use App\Domains\Mailbox\Sync\Models\MailMessage;
use App\Domains\Mailbox\Sync\Parsing\MessageClassifier;
use App\Domains\Mailbox\Sync\Parsing\ParsedMessage;
use App\Domains\Mailbox\Sync\Threading\MailboxIdentity;
use App\Domains\Mailbox\Sync\Threading\ThreadDecision;
use App\Domains\Mailbox\Sync\Threading\ThreadResolver;
use App\Enums\ChannelType;
use App\Enums\ConversationPriority;
use App\Enums\ConversationStatus;
use App\Enums\ThreadType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns one mail_messages row into a conversation thread (or links it to the
 * thread it already is). Used by IngestMailMessageJob; Phase 2's backfill
 * calls it with $historical = true.
 *
 * Idempotent: a row that has a thread_id is never processed again. The row
 * is locked FOR UPDATE and re-read inside the transaction that creates the
 * thread and writes thread_id back, so two workers given the same id create
 * one thread (threads.mail_message_id is unique as a backstop). Ingests are
 * also serialised per mailbox (transaction-scoped advisory lock) so the
 * resolver sees the conversations created by messages ingested just before.
 *
 * Direction comes from ThreadResolver:
 *  - inbound: the customer's message; reopens the conversation, clears
 *    snooze, then hooks / automation / AI / notifications / auto-reply;
 *  - outbound with existingThreadId: the Sent copy of a reply fusterai sent:
 *    linked, real Message-ID stored, nothing created;
 *  - other outbound: staff wrote it in Gmail: an agent thread (customer_id
 *    null, user_id = the staff user whose address sent it, else null),
 *    origin=gmail; never reopens, drafts or auto-replies.
 *
 * Historical mode (the backfill) suppresses every side effect (hooks,
 * broadcasts, notifications, automation, AI, auto-reply, activity log, search
 * sync), never stores attachment bytes (their name / type / size go to the
 * thread meta), and creates conversations closed and imported, dated by the
 * message. $keepActive: a thread still waiting on us gets the live status
 * (open / pending) instead, and is not marked imported. $backfillRunId tags
 * the ledger row and any new conversation so the run can be undone.
 *
 * Drafts (Gmail \Draft label) are never ingested.
 */
class MailIngestService
{
    /** Advisory-lock namespace (first int of pg_advisory_xact_lock(int, int)). */
    private const LOCK_NAMESPACE = 734_101;

    public function __construct(
        private readonly MailSyncService $sync,
        private readonly InboundMessageEffects $effects,
        private readonly ThreadResolver $resolver = new ThreadResolver,
        private readonly MessageClassifier $classifier = new MessageClassifier,
    ) {}

    public function ingest(int $mailMessageId, bool $historical = false, ?int $backfillRunId = null, bool $keepActive = false): IngestResult
    {
        // Archived in Gmail = dealt with: kept as closed history so threading
        // and learning still see it, but it is not work and nothing acts on it.
        if (! $historical && $this->isArchived($mailMessageId)) {
            $historical = true;
        }

        if (! $historical) {
            return $this->ingestRow($mailMessageId, false, null, false);
        }

        // History: no activity_log rows and no per-save search sync for
        // thousands of conversations; the backfill indexes them in bulk.
        return activity()->withoutLogs(
            fn () => Conversation::withoutSyncingToSearch(
                fn () => $this->ingestRow($mailMessageId, true, $backfillRunId, $keepActive),
            ),
        );
    }

    private function ingestRow(int $mailMessageId, bool $historical, ?int $backfillRunId, bool $keepActive): IngestResult
    {
        $row = MailMessage::query()->find($mailMessageId);
        if ($row === null) {
            return new IngestResult(IngestResult::MISSING);
        }
        if ($row->thread_id !== null) {
            return new IngestResult(IngestResult::ALREADY_INGESTED);
        }

        $mailbox = Mailbox::query()->find($row->mailbox_id);
        if ($mailbox === null) {
            return new IngestResult(IngestResult::MISSING);
        }

        $identity = MailboxIdentity::forMailbox($mailbox);
        $headers = $row->toParsedMessage();

        // Header-only decisions first: no IMAP round trip for a newsletter.
        $skip = $row->hasLabel('\\Draft') ? IngestResult::SKIPPED_DRAFT : $this->skipReason($headers, $identity);
        if ($skip !== null) {
            $this->markIngested($row, $skip, null, $backfillRunId);

            return new IngestResult($skip);
        }

        // Body and attachments are fetched outside the transaction (IMAP).
        [$message, $attachmentsUnavailable] = $historical ? $this->loadHistoricalMessage($mailbox, $row) : $this->loadMessage($mailbox, $row);

        $result = DB::transaction(function () use ($mailbox, $row, $identity, $message, $attachmentsUnavailable, $historical, $backfillRunId, $keepActive) {
            $this->lockMailbox($mailbox->id);

            /** @var MailMessage|null $locked */
            $locked = MailMessage::query()->lockForUpdate()->find($row->id);
            if ($locked === null) {
                return new IngestResult(IngestResult::MISSING);
            }
            if ($locked->thread_id !== null) {
                return new IngestResult(IngestResult::ALREADY_INGESTED);
            }

            $decision = $this->resolver->resolve($mailbox->id, $identity, $locked->toParsedMessage(), $locked->gm_thrid, $locked->gm_msgid);

            $existing = $decision->existingThreadId !== null
                ? Thread::query()->find($decision->existingThreadId)
                : $this->existingThreadForMessageId($mailbox->id, $locked);

            if ($existing !== null) {
                return $this->link($locked, $existing, $backfillRunId);
            }

            return $this->create($mailbox, $locked, $identity, $decision, $message, $attachmentsUnavailable, $historical, $backfillRunId, $keepActive);
        });

        if (! $historical && $result->createdThread() && $result->conversation !== null && $result->thread !== null) {
            if ($result->thread->customer_id !== null) {
                $this->effects->afterCustomerMessage(
                    $result->conversation,
                    $result->thread,
                    $result->newConversation,
                    allowAutoReply: $headers->header('x-auto-response-suppress') === null,
                    stale: $this->isStale($row),
                );
            } else {
                $this->effects->afterAgentMessage($result->conversation, $result->thread);
            }
        }

        return $result;
    }

    // ── Steps ────────────────────────────────────────────────────────

    private function skipReason(ParsedMessage $message, MailboxIdentity $identity): ?string
    {
        $direction = $this->resolver->direction($message, $identity);
        if ($direction === MailDirection::Internal) {
            return IngestResult::SKIPPED_INTERNAL;
        }

        // Same auto-reply loop protection as before: auto-replies (ours
        // included), bulk mail and machine notifications never become
        // conversations. The ledger row stays for history and mining.
        if ($this->classifier->classify($message, $direction) !== MailClassification::Support) {
            return IngestResult::SKIPPED_AUTOMATED;
        }

        if ($this->resolver->customer($message, $identity, $direction) === null) {
            return IngestResult::SKIPPED_NO_CUSTOMER;
        }

        return null;
    }

    /**
     * The full message, with attachments when it has any. The ledger keeps
     * bodies but not attachment bytes, so a message with attachments or
     * inline images is read again from the server.
     *
     * @return array{0: ParsedMessage, 1: bool} message, attachments unavailable
     */
    private function loadMessage(Mailbox $mailbox, MailMessage $row): array
    {
        $oversize = isset(($row->headers ?? [])['x-fusterai-body-skipped']);
        $needsFetch = ! $oversize && (
            $row->body_fetched_at === null
            || $row->has_attachments === true
            || stripos((string) $row->body_html, 'cid:') !== false
        );

        if (! $needsFetch) {
            return [$row->toParsedMessage(), false];
        }

        try {
            $parsed = $this->sync->hydrateBodies($mailbox, [$row])[$row->id] ?? null;
        } catch (Throwable $e) {
            if ($row->body_fetched_at === null) {
                throw $e; // no body at all yet: let the job retry
            }
            Log::warning('Mail ingest could not re-read attachments; ingesting without them', [
                'mail_message_id' => $row->id,
                'error' => $e->getMessage(),
            ]);

            return [$row->toParsedMessage(), (bool) $row->has_attachments];
        }

        $row->refresh();

        if ($parsed === null) {
            // Moved or expunged since the sync (stale UIDVALIDITY): use what the ledger has.
            return [$row->toParsedMessage(), (bool) $row->has_attachments];
        }

        return [$parsed, false];
    }

    /**
     * History: the body the backfill already stored (fetched text-only if it
     * was not), never the attachments.
     *
     * @return array{0: ParsedMessage, 1: bool}
     */
    private function loadHistoricalMessage(Mailbox $mailbox, MailMessage $row): array
    {
        $oversize = isset(($row->headers ?? [])['x-fusterai-body-skipped']);
        if ($row->body_fetched_at === null && ! $oversize) {
            $this->sync->hydrateBodies($mailbox, [$row], textOnly: true);
            $row->refresh();
        }

        return [$row->toParsedMessage(), false];
    }

    private function lockMailbox(int $mailboxId): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::select('select pg_advisory_xact_lock(?, ?)', [self::LOCK_NAMESPACE, $mailboxId]);
        }
    }

    /**
     * A thread that already carries this Message-ID: the reply fusterai sent
     * (Sent copy without our header), the same message delivered twice, or a
     * message the pre-ledger fetcher (ProcessInboundEmailJob) already turned
     * into a thread before the cutover.
     */
    private function existingThreadForMessageId(int $mailboxId, MailMessage $row): ?Thread
    {
        if ($row->message_id === null || $row->hasSyntheticMessageId()) {
            return null;
        }

        $id = $row->message_id;

        return Thread::query()
            ->whereHas('conversation', fn ($q) => $q->where('mailbox_id', $mailboxId))
            ->where(fn ($q) => $q->where('message_id', $id)
                ->orWhereIn('meta->message_id', [$id, '<'.$id.'>'])
                ->orWhere('meta->outbound_message_id', '<'.$id.'>'))
            ->orderBy('id')
            ->first();
    }

    private function link(MailMessage $row, Thread $thread, ?int $backfillRunId = null): IngestResult
    {
        $updates = [];
        if ($row->message_id !== null && ! $row->hasSyntheticMessageId()) {
            // The real id as it went out (Gmail may rewrite the one we set).
            $updates['message_id'] = $row->message_id;
        }
        if ($thread->mail_message_id === null) {
            $updates['mail_message_id'] = $row->id;
        }
        if ($thread->sent_at === null && $row->sent_at !== null) {
            $updates['sent_at'] = $row->sent_at;
        }
        if ($updates !== []) {
            $thread->forceFill($updates)->save();
        }

        $conversation = Conversation::query()->find($thread->conversation_id);
        if ($conversation !== null && $conversation->gm_thrid === null && $row->gm_thrid !== null) {
            $conversation->forceFill(['gm_thrid' => $row->gm_thrid])->save();
        }

        $this->markIngested($row, IngestResult::LINKED, $thread, $backfillRunId);

        return new IngestResult(IngestResult::LINKED, $conversation, $thread);
    }

    private function create(
        Mailbox $mailbox,
        MailMessage $row,
        MailboxIdentity $identity,
        ThreadDecision $decision,
        ParsedMessage $message,
        bool $attachmentsUnavailable,
        bool $historical,
        ?int $backfillRunId = null,
        bool $keepActive = false,
    ): IngestResult {
        $inbound = $decision->direction === MailDirection::Inbound;
        $at = $this->orderTime($row);
        $email = (string) $decision->customerEmail;

        $conversation = $decision->conversationId !== null
            ? Conversation::query()->find($decision->conversationId)
            : null;
        $isNew = $conversation === null;

        // Inbound: the sender (Reply-To for contact forms). Outbound: only
        // needed when the staff mail starts a new conversation.
        $customer = $inbound || $isNew
            ? Customer::resolveOrCreate($mailbox->workspace_id, $email, (string) ($decision->customerName ?? ''))
            : null;
        if ($historical && $customer !== null && $customer->wasRecentlyCreated) {
            // A customer first seen in history is dated by that mail, so the
            // import does not push thousands of old customers to the top of
            // the (newest first) customer list.
            $customer->forceFill(['created_at' => $at])->saveQuietly();
        }

        // A still-active history thread ($keepActive) is waiting on us: open.
        $liveStatus = $inbound || $historical ? ConversationStatus::Open : ConversationStatus::Pending;
        $imported = $historical && ! $keepActive;

        if ($conversation === null) {
            $conversation = new Conversation([
                'workspace_id' => $mailbox->workspace_id,
                'mailbox_id' => $mailbox->id,
                'customer_id' => $customer?->id,
                'subject' => EmailSubject::normalize($row->subject),
                // An agent-started thread from Gmail already has its reply:
                // it waits on the customer.
                'status' => $imported ? ConversationStatus::Closed : $liveStatus,
                'priority' => ConversationPriority::Normal,
                'channel_type' => ChannelType::Email,
                'last_reply_at' => $at,
                'gm_thrid' => $row->gm_thrid,
                'imported' => $imported,
            ]);
            $conversation->backfill_run_id = $backfillRunId;
            if ($historical) {
                $conversation->created_at = $at;
            }
            $conversation->save();
        }

        $skipped = [];
        [$body, $plain] = $this->bodies($message);

        $thread = new Thread([
            'customer_id' => $inbound ? $customer?->id : null,
            'user_id' => $inbound ? null : $this->staffUserId($mailbox->workspace_id, $row->from_email),
            'type' => ThreadType::Message,
            'body' => $body,
            'body_plain' => $plain,
            'source' => 'email',
            'origin' => $historical ? Thread::ORIGIN_IMPORT : ($inbound ? Thread::ORIGIN_FUSTERAI : Thread::ORIGIN_GMAIL),
            'message_id' => $row->hasSyntheticMessageId() ? null : $row->message_id,
            'sent_at' => $row->sent_at ?? $row->internal_date,
            'mail_message_id' => $row->id,
            'meta' => [],
        ]);
        $thread->created_at = $at;
        $conversation->threads()->save($thread);

        // Attachments; inline images get their cid: references rewritten to
        // the authenticated attachment URL.
        $cidMap = [];
        foreach ($historical ? [] : $message->attachments as $attachment) {
            if ($attachment['size'] > AttachmentStorage::maxBytes()) {
                $skipped[] = ['name' => $attachment['name'], 'size' => $attachment['size']];

                continue;
            }
            $inlineImage = $attachment['inline'] && $attachment['content_id'] !== null
                && in_array($attachment['mime'], Attachment::INLINE_IMAGE_MIMES, true);

            $stored = AttachmentStorage::store($thread, $attachment['name'], $attachment['content'], $attachment['mime'], $inlineImage);
            if ($inlineImage) {
                $cidMap[strtolower((string) $attachment['content_id'])] = $stored->url;
            }
        }
        if ($cidMap !== []) {
            $thread->body = $this->rewriteCids($thread->body, $cidMap);
        }

        $thread->meta = array_filter([
            'message_id' => $row->message_id,
            'in_reply_to' => $row->in_reply_to,
            'from_email' => $row->from_email,
            'from_name' => $row->from_name,
            'to' => $row->to ?? [],
            'cc' => $this->replyAllRecipients($row, $identity, $email),
            'gm_thrid' => $row->gm_thrid,
            'matched_by' => $decision->matchedBy,
            'skipped_attachments' => $skipped,
            'attachments_unavailable' => $attachmentsUnavailable ?: null,
            // History keeps what was attached, not the files.
            'attachments_not_imported' => $historical ? ($row->attachment_meta ?? []) : [],
        ], fn ($v, $k) => $k === 'cc' || ($v !== null && $v !== []), ARRAY_FILTER_USE_BOTH);
        $thread->save();

        $this->updateConversation($conversation, $row, $at, $inbound, $isNew, $historical);
        $this->markIngested($row, IngestResult::CREATED, $thread, $backfillRunId);

        return new IngestResult(IngestResult::CREATED, $conversation, $thread, $isNew);
    }

    private function updateConversation(Conversation $conversation, MailMessage $row, Carbon $at, bool $inbound, bool $isNew, bool $historical): void
    {
        $updates = [];
        if ($conversation->last_reply_at === null || $conversation->last_reply_at->lt($at)) {
            $updates['last_reply_at'] = $at;
        }
        if ($conversation->gm_thrid === null && $row->gm_thrid !== null) {
            $updates['gm_thrid'] = $row->gm_thrid;
        }
        // A customer wrote: back to Open and out of snooze. Historical mail
        // never changes the state of a live conversation.
        if ($inbound && ! $historical && ! $isNew) {
            $updates['status'] = ConversationStatus::Open;
            $updates['snoozed_until'] = null;
            // The customer wrote again on an imported conversation: it is live
            // work now and counts in reports from here on.
            if ($conversation->imported) {
                $updates['imported'] = false;
            }
        }

        if ($updates !== []) {
            $conversation->update($updates);
        }
    }

    private function markIngested(MailMessage $row, string $outcome, ?Thread $thread = null, ?int $backfillRunId = null): void
    {
        $row->forceFill(array_filter([
            'thread_id' => $thread?->id,
            'conversation_id' => $thread?->conversation_id,
            'ingested_at' => now(),
            'ingest_outcome' => $outcome,
            'backfill_run_id' => $backfillRunId,
        ], fn ($v) => $v !== null))->save();
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * When the message was sent: the Date header, unless it disagrees with
     * the server's INTERNALDATE by more than a day (bad sender clock), and
     * never in the future.
     */
    private function orderTime(MailMessage $row): Carbon
    {
        $sent = $row->sent_at;
        $internal = $row->internal_date;
        $at = $sent ?? $internal ?? now();
        if ($sent !== null && $internal !== null && abs($sent->diffInSeconds($internal)) > 86_400) {
            $at = $internal;
        }

        return $at->isFuture() ? now() : $at->copy();
    }

    /**
     * An inbound Gmail message that is no longer in the Inbox (see
     * mailsync.inbox_only). Other servers have no labels: never archived.
     */
    private function isArchived(int $mailMessageId): bool
    {
        if (! config('mailsync.inbox_only', true)) {
            return false;
        }

        $row = MailMessage::query()->find($mailMessageId, ['id', 'gm_msgid', 'direction', 'labels']);

        return $row !== null
            && $row->gm_msgid !== null
            && $row->direction === MailDirection::Inbound
            && ! $row->hasLabel('\\Inbox');
    }

    /** Too old to act on: see mailsync.live_effects_max_age_hours. */
    private function isStale(MailMessage $row): bool
    {
        $hours = (int) config('mailsync.live_effects_max_age_hours', 24);

        return $hours > 0 && $this->orderTime($row)->lt(now()->subHours($hours));
    }

    /** @return array{0: string, 1: string} raw HTML body, cleaned plain text */
    private function bodies(ParsedMessage $message): array
    {
        if (! $message->hasBody && $message->bodyHtml === null && $message->bodyText === null) {
            $notice = 'This message was too large to import. Open it in Gmail to read it.';

            return ['<p><em>'.e($notice).'</em></p>', $notice];
        }

        $html = $message->bodyHtml;
        $text = $message->bodyText;
        $body = $html ?? ($text !== null ? nl2br(e($text)) : '');
        $plain = $message->bodyClean ?? $text ?? trim(html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5));

        return [$body, $plain];
    }

    /** @param array<string, string> $map lower-cased content id => URL */
    private function rewriteCids(string $html, array $map): string
    {
        return (string) preg_replace_callback('/cid:([^"\'\s>)]+)/i', function (array $m) use ($map) {
            $key = strtolower(rawurldecode($m[1]));

            return $map[$key] ?? $m[0];
        }, $html);
    }

    /**
     * Everyone else on the message (To and Cc minus us and the customer), so
     * an agent reply from fusterai goes to the same people (SendReplyJob reads
     * meta.cc of the last customer thread).
     *
     * @return list<array{email: string, name: string}>
     */
    private function replyAllRecipients(MailMessage $row, MailboxIdentity $identity, string $customerEmail): array
    {
        $out = [];
        foreach (array_merge($row->to ?? [], $row->cc ?? []) as $address) {
            $email = strtolower((string) ($address['email'] ?? ''));
            if ($email === '' || $identity->owns($email) || $email === strtolower($customerEmail) || isset($out[$email])) {
                continue;
            }
            $out[$email] = ['email' => $email, 'name' => (string) ($address['name'] ?? '')];
        }

        return array_values($out);
    }

    private function staffUserId(int $workspaceId, ?string $fromEmail): ?int
    {
        if ($fromEmail === null || $fromEmail === '') {
            return null;
        }

        $id = User::query()
            ->where('workspace_id', $workspaceId)
            ->whereRaw('lower(email) = ?', [strtolower($fromEmail)])
            ->value('id');

        return $id !== null ? (int) $id : null;
    }
}
