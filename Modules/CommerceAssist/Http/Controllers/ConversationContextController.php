<?php

namespace Modules\CommerceAssist\Http\Controllers;

use App\Domains\Conversation\Models\Conversation;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CommerceAssist\Models\Generation;
use Modules\CommerceAssist\Models\ShopifySnapshot;
use Modules\CommerceAssist\Services\ShopifyLookupService;

class ConversationContextController extends Controller
{
    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $generation = Generation::where('conversation_id', $conversation->id)->latest()->first();
        $snapshot = ShopifySnapshot::where('conversation_id', $conversation->id)->latest()->first();

        return response()->json([
            'shopify' => $snapshot?->payload,
            'generation' => $generation?->toUiArray(),
            'nominate' => ($generation && $generation->nominated_as_example && ! $generation->added_as_example)
                ? $generation->toUiArray()
                : null,
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
}
