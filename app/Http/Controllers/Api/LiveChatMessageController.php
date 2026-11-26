<?php

namespace App\Http\Controllers\Api;

use App\Events\VisitorTyping;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreLiveChatMessageRequest;
use App\Models\Workspace;
use App\Services\LiveChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiveChatMessageController extends Controller
{
    public function __construct(private LiveChatService $service) {}

    public function config(Request $request): JsonResponse
    {
        $validated = $request->validate(['workspace_id' => 'required|integer|exists:workspaces,id']);
        $workspace = Workspace::findOrFail($validated['workspace_id']);
        /** @var array<string, mixed> $chat */
        $chat = ($workspace->settings['live_chat'] ?? []) ?: [];

        return response()->json([
            'greeting' => $chat['greeting'] ?? 'Hi there! How can we help?',
            'color' => $chat['color'] ?? '#7c3aed',
            'position' => $chat['position'] ?? 'bottom-right',
            'launcher_text' => $chat['launcher_text'] ?? 'Chat with us',
        ]);
    }

    /**
     * History for the widget. Returns the chat token too, so a widget session
     * that started before tokens existed can switch to the token channel.
     */
    public function messages(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'workspace_id' => 'required|integer|exists:workspaces,id',
            'conversation_id' => 'required|integer',
            'visitor_id' => 'required_without:chat_token|nullable|string|max:100',
            'chat_token' => 'nullable|string|max:64',
        ]);

        $conversation = $this->service->findForVisitor(
            (int) $validated['conversation_id'],
            (int) $validated['workspace_id'],
            $validated['chat_token'] ?? null,
            $validated['visitor_id'] ?? null,
        );

        if (! $conversation) {
            return response()->json(['threads' => []]);
        }

        return response()->json([
            'threads' => $this->service->messages($conversation),
            'chat_token' => $conversation->ensureChatToken(),
        ]);
    }

    public function store(StoreLiveChatMessageRequest $request): JsonResponse
    {
        return response()->json($this->service->store($request->validated()));
    }

    public function typing(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'workspace_id' => 'required|integer|exists:workspaces,id',
            'conversation_id' => 'required|integer',
            'visitor_id' => 'required_without:chat_token|nullable|string|max:100',
            'chat_token' => 'nullable|string|max:64',
        ]);

        $conversation = $this->service->findForVisitor(
            (int) $validated['conversation_id'],
            (int) $validated['workspace_id'],
            $validated['chat_token'] ?? null,
            $validated['visitor_id'] ?? null,
        );

        if (! $conversation) {
            return response()->json(['ok' => false], 403);
        }

        // Agents only: the private conversation channel, never the public one.
        broadcast(new VisitorTyping($conversation->id))->toOthers();

        return response()->json(['ok' => true]);
    }
}
