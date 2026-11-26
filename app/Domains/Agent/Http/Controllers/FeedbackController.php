<?php

namespace App\Domains\Agent\Http\Controllers;

use App\Domains\Agent\Feedback\FeedbackService;
use App\Domains\Agent\Http\Presenters\LoopPresenter;
use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\FeedbackEvent;
use App\Domains\Agent\Models\Proposal;
use App\Domains\Conversation\Models\AiSuggestion;
use App\Domains\Conversation\Models\Conversation;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Feedback on a draft (any agent who can view the conversation). Routing
 * runs on the `ai` queue; the UI listens for agent.feedback.routed /
 * agent.preview.ready on the workspace channel and re-fetches
 * GET /agent/feedback/{feedback}. See docs/agent-proposals.md.
 */
class FeedbackController extends Controller
{
    private const SCOPES = ['one_off', 'voice', 'playbook', 'knowledge', 'example'];

    public function store(Request $request, Conversation $conversation, FeedbackService $feedback): JsonResponse
    {
        $this->authorize('view', $conversation);
        $data = $request->validate([
            'run_id' => ['required', 'integer'],
            'text' => ['required', 'string', 'max:4000'],
            'selected_text' => ['nullable', 'string', 'max:2000'],
            'scope_override' => ['nullable', Rule::in(self::SCOPES)],
            'playbook_id' => ['nullable', 'integer'],
            'knowledge_id' => ['nullable', 'integer'],
            'example_id' => ['nullable', 'integer'],
        ]);

        $run = AgentRun::where('conversation_id', $conversation->id)->find($data['run_id']);
        abort_if($run === null, 404, 'No such draft on this conversation.');

        $event = $feedback->submit($conversation, $run, $request->user(), $data['text'], $data['selected_text'] ?? null, $data['scope_override'] ?? null, $this->target($data));

        return response()->json(['feedback' => LoopPresenter::feedback($event->fresh() ?? $event)], 202);
    }

    /**
     * Everything the inbox needs to restore the feedback box after a reload:
     * the conversation's feedback events (each with its routing state and
     * linked proposal) and every proposal that came from this conversation
     * (feedback, QA, manager), latest first. Workspace isolation comes from
     * ConversationPolicy::view.
     */
    public function forConversation(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);
        $data = $request->validate([
            'kind' => ['nullable', Rule::in(FeedbackEvent::KINDS)],
            'run_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $limit = (int) ($data['limit'] ?? 50);

        $events = FeedbackEvent::where('conversation_id', $conversation->id)
            ->when(filled($data['kind'] ?? null), fn ($q) => $q->where('kind', $data['kind']))
            ->when(filled($data['run_id'] ?? null), fn ($q) => $q->where('run_id', (int) $data['run_id']))
            ->latest('id')->limit($limit)->get();
        $proposals = Proposal::with('changes')
            ->where('workspace_id', $conversation->workspace_id)
            ->where(fn ($q) => $q->where('conversation_id', $conversation->id)->orWhereIn('feedback_event_id', $events->pluck('id')))
            ->latest('id')->limit($limit)->get();

        return response()->json([
            'conversation_id' => $conversation->id,
            'feedback' => $events->map(fn (FeedbackEvent $e) => LoopPresenter::feedback($e))->values()->all(),
            'proposals' => $proposals->map(fn (Proposal $p) => LoopPresenter::proposal($p))->values()->all(),
        ]);
    }

    public function show(Request $request, FeedbackEvent $feedback): JsonResponse
    {
        $this->authorizeFeedback($feedback);

        return response()->json(['feedback' => LoopPresenter::feedback($feedback)]);
    }

    /** Human override of the routed scope: re-routes without re-asking. */
    public function scope(Request $request, FeedbackEvent $feedback, FeedbackService $service): JsonResponse
    {
        $this->authorizeFeedback($feedback);
        $data = $request->validate([
            'scope' => ['required', Rule::in(self::SCOPES)],
            'playbook_id' => ['nullable', 'integer'],
            'knowledge_id' => ['nullable', 'integer'],
            'example_id' => ['nullable', 'integer'],
        ]);

        $event = $service->override($feedback, $request->user(), $data['scope'], $this->target($data));

        return response()->json(['feedback' => LoopPresenter::feedback($event)], 202);
    }

    public function answer(Request $request, FeedbackEvent $feedback, FeedbackService $service): JsonResponse
    {
        $this->authorizeFeedback($feedback);
        $data = $request->validate(['answer' => ['required', 'string', 'max:2000']]);

        try {
            $event = $service->answer($feedback, $request->user(), $data['answer']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['feedback' => LoopPresenter::feedback($event)], 202);
    }

    /** The inbox "Dismiss" button: records a `rejected` feedback event on the draft's run. */
    public function dismissSuggestion(Request $request, AiSuggestion $suggestion): JsonResponse
    {
        $conversation = Conversation::find($suggestion->conversation_id);
        abort_if($conversation === null, 404);
        $this->authorize('view', $conversation);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        $suggestion->forceFill(['accepted' => false])->save();
        $runId = $suggestion->agent_run_id;
        $event = FeedbackEvent::create([
            'workspace_id' => $conversation->workspace_id,
            'run_id' => $runId,
            'conversation_id' => $conversation->id,
            'user_id' => $request->user()->id,
            'kind' => FeedbackEvent::KIND_REJECTED,
            'draft_before' => $suggestion->content,
            'text' => $data['reason'] ?? null,
            'meta' => ['reason' => 'dismissed', 'ai_suggestion_id' => $suggestion->id],
        ]);

        return response()->json(['ok' => true, 'feedback_id' => $event->id]);
    }

    private function authorizeFeedback(FeedbackEvent $feedback): void
    {
        abort_unless($feedback->kind === FeedbackEvent::KIND_COMMENT, 404);
        $conversation = $feedback->conversation_id !== null ? Conversation::find($feedback->conversation_id) : null;
        abort_if($conversation === null, 404);
        $this->authorize('view', $conversation);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, int>
     */
    private function target(array $data): array
    {
        return array_filter([
            'playbook_id' => isset($data['playbook_id']) ? (int) $data['playbook_id'] : null,
            'knowledge_id' => isset($data['knowledge_id']) ? (int) $data['knowledge_id'] : null,
            'example_id' => isset($data['example_id']) ? (int) $data['example_id'] : null,
        ], fn ($v) => $v !== null);
    }
}
