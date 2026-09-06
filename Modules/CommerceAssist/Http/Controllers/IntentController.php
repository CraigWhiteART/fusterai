<?php

namespace Modules\CommerceAssist\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CommerceAssist\Http\Requests\UpdateIntentRequest;
use Modules\CommerceAssist\Models\CommerceIntent;
use Modules\CommerceAssist\Services\IntentCatalog;

class IntentController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('manage-settings');

        $workspaceId = (int) $request->user()->workspace_id;
        IntentCatalog::ensureForWorkspace($workspaceId);

        $intents = CommerceIntent::where('workspace_id', $workspaceId)
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('Settings/CommerceAssistIntents', [
            'intents' => $intents,
        ]);
    }

    public function update(UpdateIntentRequest $request, CommerceIntent $intent): RedirectResponse
    {
        $this->authorize('manage-settings');
        abort_unless($intent->workspace_id === $request->user()->workspace_id, 404);

        $intent->update([
            'rules' => $request->validated('rules'),
            'always_human' => $request->boolean('always_human'),
            'auto_send_allowed' => $request->boolean('auto_send_allowed'),
        ]);

        return back()->with('success', 'Intent rules saved.');
    }
}
