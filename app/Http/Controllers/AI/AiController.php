<?php

namespace App\Http\Controllers\AI;

use App\Domains\Agent\Models\AgentSetting;
use App\Domains\AI\Jobs\GenerateReplySuggestionJob;
use App\Domains\AI\Jobs\SummarizeConversationJob;
use App\Domains\Conversation\Models\AiSuggestion;
use App\Domains\Conversation\Models\Conversation;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiController extends Controller
{
    public function suggestReply(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);

        // A person asked: a one-off agent draft even when the agent is off or
        // in shadow (GenerateReplySuggestionJob). Kill switch = nothing runs.
        if (AgentSetting::forWorkspace((int) $conversation->workspace_id)->kill_switch) {
            return response()->json(['message' => 'The AI agent kill switch is on, so it cannot draft. Turn it off in Agent settings.'], 409);
        }

        GenerateReplySuggestionJob::dispatch($conversation, manual: true)->onQueue('ai');

        return response()->json(['status' => 'queued']);
    }

    public function summarize(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);

        SummarizeConversationJob::dispatch($conversation)->onQueue('ai');

        return response()->json(['status' => 'queued']);
    }

    public function acceptSuggestion(Request $request, AiSuggestion $suggestion): JsonResponse
    {
        $this->authorize('update', $suggestion->conversation);

        $suggestion->update(['accepted' => true]);

        return response()->json(['ok' => true]);
    }
}
