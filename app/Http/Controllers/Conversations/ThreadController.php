<?php

namespace App\Http\Controllers\Conversations;

use App\Domains\Agent\AutoSend\AutoSendService;
use App\Domains\Commerce\Actions\ReplySendGuard;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Enums\ThreadType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conversations\StoreThreadRequest;
use App\Services\ThreadService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ThreadController extends Controller
{
    public function __construct(private ThreadService $service) {}

    public function store(StoreThreadRequest $request, Conversation $conversation): RedirectResponse
    {
        $this->authorize('update', $conversation);

        $validated = $request->validated();
        $type = ThreadType::from($validated['type']);

        // The agent's draft may depend on a Shopify action that has not
        // succeeded yet: refuse unless the person edited it and confirmed.
        if ($type === ThreadType::Message) {
            app(ReplySendGuard::class)->check($conversation, $validated['body'], $request->boolean('send_anyway'), $request->user());
        }

        $this->service->store(
            $conversation,
            $validated['body'],
            $type,
            $request->user(),
            $request->file('attachments') ?? [],
            isset($validated['send_at']) ? new Carbon($validated['send_at']) : null,
        );

        return back();
    }

    public function cancelSchedule(Request $request, Conversation $conversation, Thread $thread): JsonResponse
    {
        $this->authorize('update', $conversation);

        abort_unless(
            $thread->conversation_id === $conversation->id && $thread->send_at !== null,
            404,
        );

        // An AI auto-reply is cancelled through the agent (it becomes a draft again).
        if ($thread->origin === Thread::ORIGIN_AI) {
            $result = app(AutoSendService::class)->cancel($thread, 'cancelled_by_user', (int) $request->user()->id, restoreDraft: true);

            return response()->json(['ok' => $result !== null, 'draft' => $result['draft'] ?? null]);
        }

        $thread->update(['send_at' => null]);

        return response()->json(['ok' => true]);
    }
}
