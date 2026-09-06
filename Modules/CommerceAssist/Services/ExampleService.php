<?php

namespace Modules\CommerceAssist\Services;

use App\Domains\Conversation\Models\Thread;
use App\Enums\ThreadType;
use App\Models\User;
use Modules\CommerceAssist\Jobs\IndexApprovedResponseJob;
use Modules\CommerceAssist\Models\ApprovedResponse;
use Modules\CommerceAssist\Models\Generation;
use Modules\CommerceAssist\Support\PlainText;
use RuntimeException;

class ExampleService
{
    public function fromThread(Thread $thread, User $actor): ApprovedResponse
    {
        if ($thread->type !== ThreadType::Message || ! $thread->user_id) {
            throw new RuntimeException('Only sent agent replies can be saved as examples.');
        }

        $conversation = $thread->conversation()->with('threads')->firstOrFail();
        $customerThread = $conversation->threads
            ->where('customer_id', '!=', null)
            ->filter(fn (Thread $candidate) => $candidate->created_at <= $thread->created_at)
            ->sortByDesc('created_at')
            ->first();

        $generation = Generation::where('conversation_id', $conversation->id)
            ->where('reply_thread_id', $thread->id)
            ->latest()
            ->first();

        return $this->store(
            workspaceId: $conversation->workspace_id,
            customerMessage: PlainText::from($customerThread?->body_plain ?: $customerThread?->body) ?: (string) $conversation->subject,
            finalReply: PlainText::from($thread->body_plain ?: $thread->body),
            actor: $actor,
            source: 'manual',
            conversationId: $conversation->id,
            threadId: $thread->id,
            generation: $generation,
        );
    }

    public function fromGeneration(Generation $generation, User $actor): ApprovedResponse
    {
        $customerMessage = PlainText::from($generation->customerThread?->body_plain ?: $generation->customerThread?->body);
        if ($customerMessage === '') {
            $customerMessage = (string) $generation->conversation?->subject;
        }

        $final = $generation->final_response ?: $generation->ai_draft;

        $example = $this->store(
            workspaceId: $generation->workspace_id,
            customerMessage: $customerMessage,
            finalReply: $final,
            actor: $actor,
            source: $generation->nominated_as_example ? 'from_edit' : 'manual',
            conversationId: $generation->conversation_id,
            threadId: $generation->reply_thread_id,
            generation: $generation,
        );

        $generation->update(['added_as_example' => true]);

        return $example;
    }

    public function store(
        int $workspaceId,
        string $customerMessage,
        string $finalReply,
        User $actor,
        string $source = 'manual',
        ?int $conversationId = null,
        ?int $threadId = null,
        ?Generation $generation = null,
        ?string $intent = null,
        ?string $subtype = null,
        ?string $sentiment = null,
        ?string $country = null,
        array $tags = [],
    ): ApprovedResponse {
        $example = ApprovedResponse::create([
            'workspace_id' => $workspaceId,
            'conversation_id' => $conversationId,
            'thread_id' => $threadId,
            'generation_id' => $generation?->id,
            'customer_message' => $customerMessage,
            'final_reply' => $finalReply,
            'intent' => $intent ?? $generation?->intent,
            'subtype' => $subtype ?? $generation?->subtype,
            'sentiment' => $sentiment ?? $generation?->sentiment,
            'country' => $country,
            'tags' => $tags,
            'created_by' => $actor->id,
            'source' => $source,
        ]);

        IndexApprovedResponseJob::dispatch($example);

        return $example;
    }
}
