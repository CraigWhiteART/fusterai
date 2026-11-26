<?php

namespace App\Domains\Conversation\Jobs;

use App\Domains\Conversation\Models\Conversation;
use App\Domains\Mailbox\Sync\Threading\ReplyHeaders;
use App\Services\DynamicMailerService;
use App\Services\OfficeHoursService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Message;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendAutoReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly Conversation $conversation,
    ) {}

    public function handle(): void
    {
        $conversation = $this->conversation->load(['mailbox', 'customer']);
        $mailbox = $conversation->mailbox;
        $customer = $conversation->customer;

        if (empty($customer->email ?? null)) {
            return;
        }

        $autoReply = $mailbox->auto_reply_config ?? [];
        $officeHours = $autoReply['office_hours'] ?? [];

        $outsideHours = false;
        if (! empty($officeHours['enabled'])) {
            $schedule = $officeHours['schedule'] ?? [];
            $timezone = $officeHours['timezone'] ?? 'UTC';
            $outsideHours = ! app(OfficeHoursService::class)->isOpen($schedule, $timezone);
        }

        // Out-of-hours reply takes priority over regular auto-reply
        if ($outsideHours) {
            $subject = $officeHours['subject'] ?? 'We\'re currently out of office — '.$conversation->subject;
            $body = $officeHours['message'] ?? "Hi {$customer->name},\n\nThanks for reaching out! We're currently outside our office hours and will get back to you as soon as we're back.\n\nBest regards,\n{$mailbox->name}";
        } elseif (! empty($autoReply['enabled'])) {
            $subject = $autoReply['subject'] ?? 'We received your message — '.$conversation->subject;
            $body = $autoReply['body'] ?? "Hi {$customer->name},\n\nThank you for reaching out. We've received your message and will get back to you as soon as possible.\n\nBest regards,\n{$mailbox->name}";
        } else {
            return;
        }

        $smtp = $mailbox->smtp_config;
        $mailer = $smtp ? app(DynamicMailerService::class)->fromSmtpConfig($smtp) : Mail::mailer();

        $threading = ReplyHeaders::forConversation($conversation);

        $mailer->send([], [], function (Message $msg) use ($conversation, $mailbox, $customer, $subject, $body, $threading) {
            $msg->to($customer->email, $customer->name)
                ->from($mailbox->email, $mailbox->name)
                ->subject($subject)
                ->text($body);

            // conversation-N@fusterai: a customer replying to the auto-reply
            // lands back in this conversation (ThreadResolver legacy match).
            $msg->getHeaders()->addIdHeader('Message-ID', ReplyHeaders::legacyConversationId($conversation->id));
            if ($threading['parent'] !== null) {
                $msg->getHeaders()->addTextHeader('In-Reply-To', $threading['in_reply_to']);
                $msg->getHeaders()->addTextHeader('References', $threading['references']);
            }
            $msg->getHeaders()->addTextHeader('X-FusterAI-Conversation', (string) $conversation->id);
            $msg->getHeaders()->addTextHeader('X-FusterAI-AutoReply', '1');
            $msg->getHeaders()->addTextHeader('Auto-Submitted', 'auto-replied');
            // No List-Unsubscribe: an acknowledgement of a support request is not
            // bulk mail, and Gmail's Unsubscribe button would block the customer.
        });

        // Log so agents can see the auto-reply was sent
        $conversation->threads()->create([
            'type' => 'activity',
            'source' => 'system',
            'body' => 'Auto-reply sent to customer.',
        ]);
    }
}
