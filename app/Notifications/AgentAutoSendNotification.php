<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells admins that the AI agent stopped auto-sending: a playbook was
 * switched back to draft by a circuit breaker / failed re-test, or all
 * auto-sending was paused (hourly breaker). Sent by
 * App\Domains\Agent\AutoSend\CircuitBreaker.
 */
class AgentAutoSendNotification extends Notification implements ShouldBroadcast, ShouldQueue
{
    use Queueable;

    public const KIND_PLAYBOOK_DEMOTED = 'agent_playbook_demoted';

    public const KIND_PAUSED = 'agent_autosend_paused';

    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
    ) {
        $this->onQueue('notifications');
    }

    public function via(mixed $notifiable): array
    {
        return ['database', 'mail', 'broadcast'];
    }

    public function toBroadcast(mixed $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title)
            ->greeting("Hi {$notifiable->name},")
            ->line($this->body)
            ->line('Nothing is switched back on automatically. Review it, then re-enable it yourself when you are happy.')
            ->action('Review', url($this->url));
    }

    /** @return array<string, string> */
    public function toArray(mixed $notifiable): array
    {
        return [
            'type' => $this->kind,
            'subject' => $this->title,
            'preview' => mb_substr($this->body, 0, 200),
            'url' => $this->url,
        ];
    }
}
