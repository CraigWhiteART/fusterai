<?php

namespace App\Domains\Commerce\Http\Controllers;

use App\Domains\Commerce\Models\CommerceSetting;
use App\Domains\Commerce\Models\ShopifySnapshot;
use App\Domains\Commerce\Models\TrackingSnapshot;
use App\Domains\Commerce\Services\ShopifyLookupService;
use App\Domains\Commerce\Services\TrackingLookupService;
use App\Domains\Conversation\Models\Conversation;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The order + carrier panel in the conversation side panel.
 *
 * GET returns the stored snapshots only (opening a conversation never calls
 * Shopify or the carrier). The refresh actions call out on demand. Works
 * whether or not the legacy Commerce Assist module is active.
 */
class ConversationCommerceController extends Controller
{
    public function __construct(
        private readonly ShopifyLookupService $shopify,
        private readonly TrackingLookupService $tracking,
    ) {}

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        return response()->json($this->state($conversation));
    }

    public function refreshShopify(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);

        if (CommerceSetting::forWorkspace((int) $conversation->workspace_id)->hasShopifyCredentials()) {
            $this->shopify->lookup($conversation->loadMissing(['customer', 'threads']));
        }

        return response()->json($this->state($conversation));
    }

    /** Force a carrier read, bypassing the freshness window ("it moved this morning"). */
    public function refreshTracking(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);

        $settings = CommerceSetting::forWorkspace((int) $conversation->workspace_id);
        if ($settings->hasShopifyCredentials()) {
            $conversation->loadMissing(['customer', 'threads']);
            $snapshot = $this->latestShopify($conversation) ?? $this->shopify->lookup($conversation);
            $this->tracking->lookup($conversation, $snapshot, force: true);
        }

        return response()->json($this->state($conversation));
    }

    /** @return array<string, mixed> */
    private function state(Conversation $conversation): array
    {
        $settings = CommerceSetting::forWorkspace((int) $conversation->workspace_id);
        $shopifyConfigured = $settings->hasShopifyCredentials();
        $staleDays = (int) ($settings->tracking_stale_days ?: config('commerce.tracking_stale_days', 14));

        if (! $shopifyConfigured) {
            return [
                'configured' => ['shopify' => false, 'tracking' => false],
                'shopify' => null,
                'tracking' => null,
                'stale_days' => $staleDays,
            ];
        }

        $snapshot = $this->latestShopify($conversation);
        $tracking = TrackingSnapshot::where('conversation_id', $conversation->id)->latest('id')->first();

        return [
            'configured' => ['shopify' => true, 'tracking' => $settings->trackingEnabled()],
            'shopify' => $snapshot !== null ? $this->presentShopify($conversation, $snapshot) : null,
            'tracking' => $tracking !== null ? array_merge($tracking->toUiArray(), [
                'stalled' => $tracking->isStalled($staleDays),
            ]) : null,
            'stale_days' => $staleDays,
        ];
    }

    private function latestShopify(Conversation $conversation): ?ShopifySnapshot
    {
        return ShopifySnapshot::where('conversation_id', $conversation->id)->latest('id')->first();
    }

    /** @return array<string, mixed> */
    private function presentShopify(Conversation $conversation, ShopifySnapshot $snapshot): array
    {
        $payload = (array) ($snapshot->payload ?? []);
        $found = (bool) ($payload['found'] ?? false);
        $orderNumber = (string) ($payload['order_number'] ?? '');
        $hasOrder = $found && $orderNumber !== '';

        $customer = $conversation->customer;
        $customerEmail = mb_strtolower(trim((string) (($customer !== null ? $customer->email : null) ?? $snapshot->customer_email ?? '')));
        $orderEmail = mb_strtolower(trim((string) ($payload['order_email'] ?? '')));
        $emailMatches = $hasOrder && $orderEmail !== '' && $customerEmail !== '' ? $orderEmail === $customerEmail : null;

        return [
            'found' => $found,
            'has_order' => $hasOrder,
            'reason' => $payload['reason'] ?? null,
            'matched_by' => $hasOrder ? $this->matchedBy($conversation, $orderNumber, $emailMatches) : null,
            'order_email_matches' => $emailMatches,
            'order_email' => $payload['order_email'] ?? null,
            'customer_name' => $payload['customer_name'] ?? null,
            'order_number' => $payload['order_number'] ?? null,
            'order_date' => $payload['order_date'] ?? null,
            'order_age_days' => $payload['order_age_days'] ?? null,
            'payment_status' => $payload['payment_status'] ?? null,
            'fulfilment_status' => $payload['fulfilment_status'] ?? null,
            'tracking_number' => $payload['tracking_number'] ?? null,
            'tracking_url' => $payload['tracking_url'] ?? null,
            'tracking_company' => $payload['tracking_company'] ?? null,
            'shipping_country' => $payload['shipping_country'] ?? null,
            'cancelled' => (bool) ($payload['cancelled'] ?? false),
            'refunded' => (bool) ($payload['refunded'] ?? false),
            'products' => array_values(array_map(fn ($item) => [
                'title' => $item['title'] ?? null,
                'variant' => $item['variant'] ?? null,
                'quantity' => $item['quantity'] ?? null,
                'preorder' => $item['preorder'] ?? null,
            ], array_filter((array) ($payload['products'] ?? []), 'is_array'))),
            'fetched_at' => $snapshot->fetched_at?->toIso8601String(),
            'error' => $snapshot->error,
        ];
    }

    /** How the lookup found the order: a number quoted in the mail, else the sender's email. */
    private function matchedBy(Conversation $conversation, string $orderNumber, ?bool $emailMatches): ?string
    {
        $name = strtoupper(ltrim(trim($orderNumber), '#'));
        $haystack = trim(($conversation->subject ?? '')."\n".$this->shopify->customerText($conversation->loadMissing('threads')));

        foreach ($this->shopify->extractOrderNumbers($haystack) as $number) {
            if ($name === $number || str_ends_with($name, $number)) {
                return 'order_number';
            }
        }

        return $emailMatches === true ? 'email' : null;
    }
}
