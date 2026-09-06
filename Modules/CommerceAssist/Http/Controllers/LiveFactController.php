<?php

namespace Modules\CommerceAssist\Http\Controllers;

use App\Domains\Conversation\Models\Conversation;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CommerceAssist\Models\LiveFact;
use Modules\CommerceAssist\Services\LiveFactService;

class LiveFactController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('manage-settings');

        $facts = LiveFact::query()
            ->where('workspace_id', $request->user()->workspace_id)
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (LiveFact $fact) => $fact->toUiArray());

        return Inertia::render('Settings/CommerceAssistFacts', [
            'facts' => $facts,
        ]);
    }

    public function infer(Request $request, Conversation $conversation, LiveFactService $facts): JsonResponse
    {
        $this->authorize('view', $conversation);

        $inferred = $facts->inferFromConversation($conversation);

        return response()->json($inferred);
    }

    public function preview(Request $request, LiveFactService $facts): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['nullable', 'integer'],
            'intent_slugs' => ['nullable', 'array'],
            'intent_slugs.*' => ['string', 'max:80'],
            'product_keywords' => ['nullable', 'array'],
            'product_keywords.*' => ['string', 'max:120'],
        ]);

        $conversation = isset($validated['conversation_id'])
            ? Conversation::findOrFail($validated['conversation_id'])
            : null;

        if ($conversation) {
            $this->authorize('view', $conversation);
            abort_unless($conversation->workspace_id === $request->user()->workspace_id, 404);
        }

        $drafts = $facts->previewDrafts(
            (int) $request->user()->workspace_id,
            $validated['intent_slugs'] ?? [],
            $validated['product_keywords'] ?? [],
            $conversation?->id,
        );

        return response()->json([
            'drafts' => $drafts->values()->all(),
            'count' => $drafts->count(),
        ]);
    }

    public function store(Request $request, LiveFactService $facts): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'conversation_id' => ['nullable', 'integer'],
            'intent_slugs' => ['nullable', 'array'],
            'intent_slugs.*' => ['string', 'max:80'],
            'product_keywords' => ['nullable', 'array'],
            'product_keywords.*' => ['string', 'max:120'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $conversation = isset($validated['conversation_id'])
            ? Conversation::findOrFail($validated['conversation_id'])
            : null;

        if ($conversation) {
            $this->authorize('update', $conversation);
            abort_unless($conversation->workspace_id === $request->user()->workspace_id, 404);
        } else {
            $this->authorize('manage-settings');
        }

        $fact = $facts->publish(
            workspaceId: (int) $request->user()->workspace_id,
            body: $validated['body'],
            actor: $request->user(),
            intentSlugs: $validated['intent_slugs'] ?? [],
            productKeywords: $validated['product_keywords'] ?? [],
            sourceConversationId: $conversation?->id,
            expiresAt: $validated['expires_at'] ?? null,
        );

        $drafts = $facts->previewDrafts(
            (int) $request->user()->workspace_id,
            $fact->intent_slugs ?? [],
            $fact->product_keywords ?? [],
            $conversation?->id,
        );

        return response()->json([
            'ok' => true,
            'fact' => $fact->toUiArray(),
            'queued' => count($fact->refreshed_conversation_ids ?? []),
            'drafts' => $drafts->values()->all(),
        ]);
    }

    public function retire(Request $request, LiveFact $fact): RedirectResponse|JsonResponse
    {
        abort_unless($fact->workspace_id === $request->user()->workspace_id, 404);
        $this->authorize('manage-settings');

        $fact->update(['retired_at' => now()]);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Fact retired. New drafts will stop using it.');
    }
}
