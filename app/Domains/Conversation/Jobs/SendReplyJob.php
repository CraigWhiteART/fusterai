<?php

namespace App\Domains\Conversation\Jobs;

use App\Domains\Agent\AutoSend\AutoSendGuard;
use App\Domains\Agent\AutoSend\AutoSendService;
use App\Domains\Channel\Drivers\WhatsAppDriver;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Mailbox\Sync\Threading\ReplyHeaders;
use App\Enums\ChannelType;
use App\Events\NewThreadReceived;
use App\Services\DynamicMailerService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Message;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SendReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public int $timeout = 120;

    /** Exponential backoff: 1m, 5m, 15m, 1h, then hourly up to the retryUntil ceiling. */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 3600, 3600, 3600, 3600, 3600, 3600];
    }

    public function __construct(
        public readonly Thread $thread,
        public readonly Conversation $conversation,
        public readonly ?Carbon $scheduledAt = null,
    ) {}

    public function handle(): void
    {
        // Re-fetch thread; if it was deleted bail silently
        $thread = $this->thread->fresh();
        if ($thread === null) {
            return;
        }

        // Scheduled send was cancelled by the agent (send_at cleared before job ran)
        if ($this->scheduledAt !== null && $thread->send_at === null) {
            return;
        }

        // An AI auto-reply is re-checked right before it goes out (kill
        // switch, mode, playbook, newer messages...); a failed check turns
        // it back into a draft instead of sending.
        $autoSend = AutoSendService::isAutoThread($thread);
        if ($autoSend && ! app(AutoSendGuard::class)->allowsSend($thread)) {
            return;
        }

        $thread->loadMissing(['user', 'attachments']);
        $conversation = $this->conversation->load(['mailbox', 'customer', 'threads']);
        $mailbox = $conversation->mailbox;
        $customer = $conversation->customer;

        // Route to correct channel driver
        if ($conversation->channel_type === ChannelType::WhatsApp) {
            app(WhatsAppDriver::class)->send($thread);
            if ($autoSend) {
                app(AutoSendService::class)->markSent($thread);
            }
            broadcast(new NewThreadReceived($thread));

            return;
        }

        if (! $customer?->email) {
            return;
        }

        // No mailbox means this is a portal/non-email conversation — nothing to send
        if (! $mailbox) {
            return;
        }

        // Build per-mailbox mailer config dynamically
        $smtp = $mailbox->smtp_config;

        // In local env with no SMTP config, fall back to the default mailer (log driver)
        if (! $smtp && app()->environment('local')) {
            if ($autoSend) {
                app(AutoSendService::class)->markSent($thread);
            }
            broadcast(new NewThreadReceived($thread));

            return;
        }

        $mailer = $smtp ? app(DynamicMailerService::class)->fromSmtpConfig($smtp) : Mail::mailer('smtp');

        // Auto-replies have no user, so they always carry the mailbox signature.
        $agentSignature = $autoSend ? null : $thread->user?->signature;

        // Collect CC recipients from the most recent inbound customer thread
        $ccRecipients = $this->resolveCcRecipients($conversation);

        // Per-thread Message-ID (bounce tracking, and how the mail sync
        // recognises the Sent copy). Kept on the bare "fusterai" host:
        // ThreadResolver::LEGACY_THREAD and existing customer replies match
        // "thread-N-...@fusterai". Stored without angle brackets; Symfony's
        // IdentificationHeader adds them when serialising.
        $outboundMsgId = 'thread-'.$thread->id.'-'.Str::uuid().'@fusterai';

        // Thread under the customer's last real message (falls back to the
        // legacy <conversation-N@fusterai> without one).
        $threading = ReplyHeaders::forConversation($conversation);

        $trackingToken = Str::random(32);

        // Single update: message_id + meta (outbound_message_id) + tracking_token.
        $thread->update([
            'tracking_token' => $trackingToken,
            'message_id' => $outboundMsgId,
            'meta' => array_merge($thread->meta ?? [], [
                'outbound_message_id' => "<{$outboundMsgId}>",
            ]),
        ]);

        $mailer->send([], [], function (Message $msg) use ($conversation, $mailbox, $customer, $thread, $agentSignature, $ccRecipients, $outboundMsgId, $trackingToken, $threading) {
            $msg->to($customer->email, $customer->name)
                ->from($mailbox->email, $mailbox->name)
                ->subject($this->buildSubject($conversation))
                ->html($this->buildHtmlBody($thread, $mailbox, $agentSignature, $trackingToken))
                ->text(strip_tags($thread->body));

            foreach ($ccRecipients as $cc) {
                $msg->cc($cc['email'], $cc['name'] ?? null);
            }

            // Message-ID requires Symfony's IdentificationHeader (not plain text)
            $msg->getHeaders()->addIdHeader('Message-ID', $outboundMsgId);
            $msg->getHeaders()->addTextHeader('In-Reply-To', $threading['in_reply_to']);
            $msg->getHeaders()->addTextHeader('References', $threading['references']);
            $msg->getHeaders()->addTextHeader('X-FusterAI-Conversation', (string) $conversation->id);
            // Survives a Message-ID rewrite by the SMTP server, so the Sent
            // copy is still linked to this thread instead of duplicated.
            $msg->getHeaders()->addTextHeader('X-FusterAI-Thread', (string) $thread->id);
            // No List-Unsubscribe: this is a 1:1 support reply, not bulk mail.
            // Gmail's Unsubscribe button would block the customer and route
            // their future messages to Spam.

            // Attach files
            foreach ($thread->attachments as $attachment) {
                $disk = Storage::disk(config('filesystems.default'));
                if ($disk->exists($attachment->path)) {
                    $msg->attachData(
                        $disk->get($attachment->path),
                        $attachment->filename,
                        ['mime' => $attachment->mime_type],
                    );
                }
            }
        });

        // Clear send_at now that the scheduled message has been sent
        if ($thread->send_at !== null) {
            $thread->update(['send_at' => null]);
        }

        if ($autoSend) {
            app(AutoSendService::class)->markSent($thread);
        }

        // Broadcast to frontend after send
        broadcast(new NewThreadReceived($thread));
    }

    public function retryUntil(): \DateTime
    {
        return now()->addWeek();
    }

    private function buildSubject(Conversation $conversation): string
    {
        return Str::startsWith($conversation->subject, 'Re:')
            ? $conversation->subject
            : 'Re: '.$conversation->subject;
    }

    private function buildHtmlBody(Thread $thread, $mailbox, ?string $agentSignature = null, ?string $trackingToken = null): string
    {
        $body = $thread->body;

        // Agent signature takes priority; fall back to mailbox signature
        $rawSignature = $agentSignature ?? $mailbox->signature ?? null;
        $signature = $rawSignature
            ? '<br><br>--<br>'.nl2br(e($rawSignature))
            : '';

        $pixel = $trackingToken
            ? '<img src="'.url('/t/'.$trackingToken.'.gif').'" width="1" height="1" style="display:none" alt="">'
            : '';

        return $body.$signature.$pixel;
    }

    /**
     * Pull CC recipients from the last inbound customer thread's meta.
     * This lets agents reply-all to threads that had CC recipients.
     *
     * @return array<int, array{email: string, name: string}>
     */
    private function resolveCcRecipients(Conversation $conversation): array
    {
        $lastCustomerThread = $conversation->threads
            ->whereNotNull('customer_id')
            ->sortByDesc('created_at')
            ->first();

        return $lastCustomerThread?->meta['cc'] ?? [];
    }
}
