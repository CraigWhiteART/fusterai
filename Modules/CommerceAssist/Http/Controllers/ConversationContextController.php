<?php

namespace Modules\CommerceAssist\Http\Controllers;

use App\Domains\Conversation\Models\Conversation;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CommerceAssist\Models\Generation;
use Modules\CommerceAssist\Models\ShopifySnapshot;
use Modules\CommerceAssist\Services\LiveFactService;
use Modules\CommerceAssist\Services\ShopifyLookupService;
use Modules\CommerceAssist\Services\TrackingLookupService;

class ConversationContextController extends Controller
{
    public function show(Request $request, Conversation $conversation, LiveFactService $facts, TrackingLookupService $tracking): JsonResponse
    {
        $this->authorize('view', $conversation);

        $generation = Generation::where('conversation_id', $conversation->id)->latest()->first();
        $snapshot = ShopifySnapshot::where('conversation_id', $conversation->id)->latest()->first();
        $applied = $facts->forConversation($conversation, $generation?->intent, $generation?->subtype, $snapshot);
        $inferred = $facts->inferFromConversation($conversation);

        return response()->json([
            'shopify' => $snapshot?->payload,
            'tracking' => $tracking->latestFor($conversation)?->toUiArray(),
            'generation' => $generation?->toUiArray(),
            'nominate' => ($generation && $generation->nominated_as_example && ! $generation->added_as_example)
                ? $generation->toUiArray()
                : null,
            'liveFacts' => $applied->map->toUiArray()->values()->all(),
            'composer' => $inferred,
        ]);
    }

    public function refreshShopify(Request $request, Conversation $conversation, ShopifyLookupService $lookup): JsonResponse
    {
        $this->authorize('update', $conversation);

        $snapshot = $lookup->lookup($conversation);

        return response()->json([
            'shopify' => $snapshot->payload,
        ]);
    }

    /**
     * Force a carrier read, bypassing the freshness window. This is the button an
     * agent hits mid-conversation when the customer says "it moved this morning".
     */
    public function refreshTracking(
        Request $request,
        Conversation $conversation,
        ShopifyLookupService $shopify,
        TrackingLookupService $tracking,
    ): JsonResponse {
        $this->authorize('update', $conversation);

        $snapshot = ShopifySnapshot::where('conversation_id', $conversation->id)->latest()->first()
            ?? $shopify->lookup($conversation);

        return response()->json([
            'tracking' => $tracking->lookup($conversation, $snapshot, force: true)?->toUiArray(),
        ]);
    }
}
