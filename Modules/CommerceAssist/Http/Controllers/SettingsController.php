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
use Modules\CommerceAssist\Services\Tracking\TrackingProviderFactory;

class SettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('manage-settings');

        $workspaceId = (int) $request->user()->workspace_id;
        IntentCatalog::ensureForWorkspace($workspaceId);
        $settings = CommerceSetting::forWorkspace($workspaceId);

        return Inertia::render('Settings/CommerceAssist', [
            'providers' => TrackingProviderFactory::PROVIDERS,
            'settings' => [
                'shopify_shop_domain' => $settings->shopify_shop_domain,
                'shopify_token_set' => $settings->tokenIsSet(),
                'shopify_api_version' => $settings->shopify_api_version,
                'tracking_provider' => $settings->tracking_provider ?: 'none',
                'tracking_key_set' => $settings->trackingKeyIsSet(),
                'tracking_store_uuid' => $settings->tracking_store_uuid,
                'tracking_store_uuid_default' => $settings->trackingStoreUuid(),
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

        // An omitted field keeps the current provider: a partial save must never
        // silently switch carrier tracking off.
        $provider = $data['tracking_provider'] ?? $settings->tracking_provider ?? 'none';

        $payload = [
            'shopify_shop_domain' => $data['shopify_shop_domain'] ?? null,
            'shopify_api_version' => $data['shopify_api_version'] ?? '2025-01',
            'tracking_provider' => $provider,
            'tracking_store_uuid' => $data['tracking_store_uuid'] ?? $settings->tracking_store_uuid,
            'tracking_stale_days' => $data['tracking_stale_days'],
            'example_edit_threshold' => $data['example_edit_threshold'],
            'preorder_tags' => $tags,
            'draft_only' => $request->boolean('draft_only'),
        ];

        if (filled($data['shopify_access_token'] ?? null)) {
            $payload['shopify_access_token'] = Crypt::encryptString($data['shopify_access_token']);
        }

        if (filled($data['tracking_api_key'] ?? null)) {
            $payload['tracking_api_key'] = Crypt::encryptString($data['tracking_api_key']);
        }

        // Switching the provider off clears the stored key rather than leaving a
        // credential for a source we no longer call.
        if ($provider === 'none') {
            $payload['tracking_api_key'] = null;
        }

        $settings->update($payload);

        return back()->with('success', 'Commerce Assist settings saved.');
    }
}
