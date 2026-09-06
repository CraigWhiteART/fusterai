<?php

namespace Modules\CommerceAssist\Services;

use App\Domains\Conversation\Models\Thread;
use App\Enums\ThreadType;
use Modules\CommerceAssist\Models\CommerceSetting;
use Modules\CommerceAssist\Models\Generation;
use Modules\CommerceAssist\Support\PlainText;

class EditLearningService
{
    public function captureSentReply(Thread $thread): ?Generation
    {
        if ($thread->type !== ThreadType::Message || ! $thread->user_id) {
            return null;
        }

        $generation = Generation::where('conversation_id', $thread->conversation_id)
            ->whereNull('reply_thread_id')
            ->whereIn('status', ['draft', 'flagged'])
            ->latest()
            ->first();

        if (! $generation) {
            return null;
        }

        $final = PlainText::from($thread->body_plain ?: $thread->body);
        $percentChanged = PlainText::percentChanged($generation->ai_draft, $final);
        $settings = CommerceSetting::forWorkspace($generation->workspace_id);
        $threshold = $settings->example_edit_threshold ?: 25;

        $generation->update([
            'reply_thread_id' => $thread->id,
            'final_response' => $final,
            'percent_changed' => $percentChanged,
            'sent_unchanged' => $percentChanged === 0,
            'nominated_as_example' => $percentChanged >= $threshold,
            'status' => 'sent',
        ]);

        return $generation->fresh();
    }
}
