<?php

return [
    'shopify_api_version' => env('COMMERCE_ASSIST_SHOPIFY_API_VERSION', '2025-01'),

    // Carrier tracking: none | track123 | 17track | aftership
    'tracking_provider' => env('COMMERCE_ASSIST_TRACKING_PROVIDER', 'none'),
    'tracking_timeout' => 15,
    // Reuse a stored carrier reading for this long before calling out again.
    'tracking_ttl_minutes' => 120,

    'tracking_stale_days' => 14,
    'example_edit_threshold' => 25,
    'example_limit' => 5,
    'kb_limit' => 5,
    'draft_only' => true,
    'preorder_tags' => ['preorder', 'pre-order', 'pre_order'],
    'intents' => require __DIR__.'/intents.php',
];
