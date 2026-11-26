<?php

/*
 * Commerce domain (Shopify + carrier tracking). Read by app/Domains/Commerce.
 * The env names keep their COMMERCE_ASSIST_ prefix so existing installs keep
 * their values.
 */
return [
    'shopify_api_version' => env('COMMERCE_ASSIST_SHOPIFY_API_VERSION', '2026-10'),

    // Carrier tracking: none | track123 | 17track | aftership
    'tracking_provider' => env('COMMERCE_ASSIST_TRACKING_PROVIDER', 'none'),
    'tracking_timeout' => 15,
    // Reuse a stored carrier reading for this long before calling out again.
    'tracking_ttl_minutes' => 120,

    'tracking_stale_days' => 14,
    'example_edit_threshold' => 25,
    'draft_only' => true,
    'preorder_tags' => ['preorder', 'pre-order', 'pre_order'],
];
