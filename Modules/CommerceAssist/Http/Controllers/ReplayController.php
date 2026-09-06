<?php

namespace Modules\CommerceAssist\Http\Controllers;

use App\Domains\Conversation\Models\Conversation;
use App\Enums\ThreadType;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CommerceAssist\Models\ReplayRun;
use Modules\CommerceAssist\Services\GenerationPipeline;
use Modules\CommerceAssist\Support\PlainText;

class ReplayController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('manage-settings');

        $workspaceId = (int) $request->user()->workspace_id;

        $conversations = Conversation::query()
            ->where('workspace_id', $workspaceId)
            ->whereHas('threads', fn ($q) => $q->whereNotNull('customer_id'))
            ->whereHas('threads', fn ($q) => $q->whereNotNull('user_id')->where('type', ThreadType::Message->value))
            ->with(['customer:id,name,email', 'threads' => fn ($q) => $q->orderBy('created_at')])
            ->latest()
            ->limit(80)
            ->get()
            ->map(function (Conversation $conversation) {
                $original = $conversation->threads
                    ->filter(fn ($thread) => $thread->user_id && $thread->type === ThreadType::Message)
                    ->last();

                return [
                    'id' => $conversation->id,
                    'subject' => $conversation->subject,
                    'customer' => $conversation->customer?->name ?: $conversation->customer?->email,
                    'status' => $conversation->status instanceof \BackedEnum ? $conversation->status->value : $conversation->status,
                    'original_reply' => PlainText::from($original?->body_plain ?: $original?->body),
                ];
            });

        $runs = ReplayRun::where('workspace_id', $workspaceId)
            ->with(['conversation:id,subject'])
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (ReplayRun $run) => [
                'id' => $run->id,
                'conversation_id' => $run->conversation_id,
                'subject' => $run->conversation?->subject,
                'original_reply' => $run->original_reply,
                'current_ai_reply' => $run->current_ai_reply,
                'passed' => $run->passed,
                'sources' => $run->sources,
                'created_at' => $run->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Settings/CommerceAssistReplay', [
            'conversations' => $conversations,
            'runs' => $runs,
        ]);
    }

    public function run(Request $request, Conversation $conversation, GenerationPipeline $pipeline): RedirectResponse
    {
        $this->authorize('manage-settings');
        abort_unless($conversation->workspace_id === $request->user()->workspace_id, 404);

        $conversation->load(['customer', 'mailbox', 'threads.attachments']);

        $original = $conversation->threads
            ->filter(fn ($thread) => $thread->user_id && $thread->type === ThreadType::Message)
            ->sortBy('created_at')
            ->last();

        $generation = $pipeline->run($conversation, [
            'persist_suggestion' => false,
            'status' => 'replay',
        ]);

        ReplayRun::create([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'generation_id' => $generation->id,
            'original_reply' => PlainText::from($original?->body_plain ?: $original?->body),
            'current_ai_reply' => $generation->ai_draft,
            'sources' => $generation->sources,
            'run_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Replay completed for conversation #'.$conversation->id);
    }

    public function mark(Request $request, ReplayRun $run): RedirectResponse
    {
        $this->authorize('manage-settings');
        abort_unless($run->workspace_id === $request->user()->workspace_id, 404);

        $request->validate([
            'passed' => ['required', 'boolean'],
        ]);

        $run->update(['passed' => $request->boolean('passed')]);

        return back();
    }
}
