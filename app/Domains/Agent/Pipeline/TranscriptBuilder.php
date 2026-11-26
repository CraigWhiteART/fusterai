<?php

namespace App\Domains\Agent\Pipeline;

use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Enums\ThreadType;
use App\Support\PlainText;
use Carbon\CarbonImmutable;

/**
 * Every customer message and staff reply on a conversation (including replies
 * staff wrote in Gmail), ordered by when they were actually sent. Notes and
 * activity rows are internal and never reach the model.
 */
class TranscriptBuilder
{
    public function fromConversation(Conversation $conversation): Transcript
    {
        $threads = $conversation->threads()
            ->with(['attachments', 'customer', 'user', 'mailMessage'])
            ->where('type', ThreadType::Message->value)
            ->get();

        $turns = [];
        foreach ($threads as $thread) {
            /** @var Thread $thread */
            $text = trim((string) $thread->body_plain);
            if ($text === '' || $text !== strip_tags($text)) {
                $text = PlainText::from($thread->body);
            }
            $text = trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            $isCustomer = $thread->customer_id !== null;
            $at = $thread->sent_at ?? $thread->created_at ?? now();

            $turns[] = new TranscriptTurn(
                role: $isCustomer ? TranscriptTurn::ROLE_CUSTOMER : TranscriptTurn::ROLE_STAFF,
                text: $text,
                at: CarbonImmutable::instance($at),
                author: $isCustomer ? $thread->customer->name ?? null : ($thread->user->name ?? ($thread->origin === Thread::ORIGIN_GMAIL ? 'Staff (Gmail)' : 'Staff')),
                origin: $thread->origin,
                threadId: $thread->id,
                hasImages: $thread->attachments->contains(fn ($a) => str_starts_with((string) $a->mime_type, 'image/')),
                autoSubmitted: $this->isAutoSubmitted($thread),
            );
        }

        return new Transcript($turns);
    }

    private function isAutoSubmitted(Thread $thread): bool
    {
        if ($thread->mailMessage?->classification?->value === 'auto') {
            return true;
        }

        $meta = $thread->meta ?? [];

        return (bool) ($meta['auto_submitted'] ?? false);
    }
}
