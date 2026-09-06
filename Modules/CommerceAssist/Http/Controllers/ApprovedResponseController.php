<?php

namespace Modules\CommerceAssist\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CommerceAssist\Http\Requests\StoreExampleRequest;
use Modules\CommerceAssist\Models\ApprovedResponse;
use Modules\CommerceAssist\Services\ExampleService;
use Modules\CommerceAssist\Services\IntentCatalog;

class ApprovedResponseController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('manage-settings');

        $workspaceId = (int) $request->user()->workspace_id;
        IntentCatalog::ensureForWorkspace($workspaceId);

        $examples = ApprovedResponse::where('workspace_id', $workspaceId)
            ->latest()
            ->limit(200)
            ->get([
                'id', 'customer_message', 'final_reply', 'intent', 'subtype',
                'sentiment', 'country', 'source', 'indexed_at', 'created_at',
            ]);

        return Inertia::render('Settings/CommerceAssistExamples', [
            'examples' => $examples,
            'intents' => IntentCatalog::slugs(),
        ]);
    }

    public function store(StoreExampleRequest $request, ExampleService $examples): RedirectResponse
    {
        $this->authorize('manage-settings');

        $examples->store(
            workspaceId: (int) $request->user()->workspace_id,
            customerMessage: $request->validated('customer_message'),
            finalReply: $request->validated('final_reply'),
            actor: $request->user(),
            source: 'manual',
            intent: $request->validated('intent'),
            subtype: $request->validated('subtype'),
            sentiment: $request->validated('sentiment'),
            country: $request->validated('country'),
        );

        return back()->with('success', 'Approved response saved.');
    }

    public function destroy(Request $request, ApprovedResponse $example): RedirectResponse
    {
        $this->authorize('manage-settings');
        abort_unless($example->workspace_id === $request->user()->workspace_id, 404);

        $example->delete();

        return back()->with('success', 'Approved response deleted.');
    }
}
