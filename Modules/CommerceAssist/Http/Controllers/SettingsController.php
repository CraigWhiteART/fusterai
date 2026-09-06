<?php

namespace Modules\CommerceAssist\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CommerceAssist\Http\Requests\UpdateSettingsRequest;
use Modules\CommerceAssist\Models\CommerceSetting;
use Modules\CommerceAssist\Services\IntentCatalog;

class SettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('manage-settings');

        $workspaceId = (int) $request->user()->workspace_id;
        IntentCatalog::ensureForWorkspace($workspaceId);
        $settings = CommerceSetting::forWorkspace($workspaceId);

        return Inertia::render('Settings/CommerceAssist', [
            'settings' => [
                'shopify_shop_domain' => $settings->shopify_shop_domain,
                'shopify_token_set' => $settings->tokenIsSet(),
                'shopify_api_version' => $settings->shopify_api_version,
                'tracking_stale_days' => $settings->tracking_stale_days,
                'example_edit_threshold' => $settings->example_edit_threshold,
                'preorder_tags' => implode(', ', $settings->preorderTagList()),
                'draft_only' => $settings->draft_only,
            ],
        ]);
    }

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        $this->authorize('manage-settings');

        $settings = CommerceSetting::forWorkspace((int) $request->user()->workspace_id);
        $data = $request->validated();

        $tags = array_values(array_filter(array_map(
            fn (string $tag) => strtolower(trim($tag)),
            explode(',', (string) ($data['preorder_tags'] ?? '')),
        )));

        $payload = [
            'shopify_shop_domain' => $data['shopify_shop_domain'] ?? null,
            'shopify_api_version' => $data['shopify_api_version'] ?? '2025-01',
            'tracking_stale_days' => $data['tracking_stale_days'],
            'example_edit_threshold' => $data['example_edit_threshold'],
            'preorder_tags' => $tags,
            'draft_only' => $request->boolean('draft_only'),
        ];

        if (filled($data['shopify_access_token'] ?? null)) {
            $payload['shopify_access_token'] = Crypt::encryptString($data['shopify_access_token']);
        }

        $settings->update($payload);

        return back()->with('success', 'Commerce Assist settings saved.');
    }
}
