<?php

namespace Modules\CommerceAssist\Http\Controllers;

use App\Domains\Conversation\Models\Thread;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CommerceAssist\Models\Generation;
use Modules\CommerceAssist\Services\ExampleService;

class ExampleController extends Controller
{
    public function fromThread(Request $request, Thread $thread, ExampleService $examples): JsonResponse
    {
        $conversation = $thread->conversation;
        $this->authorize('update', $conversation);

        $example = $examples->fromThread($thread, $request->user());

        return response()->json([
            'ok' => true,
            'id' => $example->id,
        ]);
    }

    public function fromGeneration(Request $request, Generation $generation, ExampleService $examples): JsonResponse
    {
        abort_unless($generation->workspace_id === $request->user()->workspace_id, 404);
        $this->authorize('update', $generation->conversation);

        $example = $examples->fromGeneration($generation, $request->user());

        return response()->json([
            'ok' => true,
            'id' => $example->id,
        ]);
    }
}
