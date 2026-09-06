<?php

namespace Modules\CommerceAssist\Models;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class CommerceSetting extends Model
{
    protected $table = 'commerce_assist_settings';

    protected $fillable = [
        'workspace_id',
        'shopify_shop_domain',
        'shopify_access_token',
        'shopify_api_version',
        'tracking_stale_days',
        'example_edit_threshold',
        'preorder_tags',
        'draft_only',
    ];

    protected $casts = [
        'preorder_tags' => 'array',
        'draft_only' => 'boolean',
        'tracking_stale_days' => 'integer',
        'example_edit_threshold' => 'integer',
    ];

    protected $hidden = [
        'shopify_access_token',
    ];

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public static function forWorkspace(int $workspaceId): self
    {
        return static::firstOrCreate(
            ['workspace_id' => $workspaceId],
            [
                'shopify_api_version' => (string) config('commerce-assist.shopify_api_version', '2025-01'),
                'tracking_stale_days' => (int) config('commerce-assist.tracking_stale_days', 14),
                'example_edit_threshold' => (int) config('commerce-assist.example_edit_threshold', 25),
                'preorder_tags' => config('commerce-assist.preorder_tags', ['preorder']),
                'draft_only' => (bool) config('commerce-assist.draft_only', true),
            ],
        );
    }

    public function decryptAccessToken(): ?string
    {
        if (blank($this->shopify_access_token)) {
            return null;
        }

        try {
            return Crypt::decryptString($this->shopify_access_token);
        } catch (\Throwable) {
            return null;
        }
    }

    public function hasShopifyCredentials(): bool
    {
        return filled($this->shopify_shop_domain) && filled($this->decryptAccessToken());
    }

    public function tokenIsSet(): bool
    {
        return filled($this->shopify_access_token);
    }

    public function shopDomain(): ?string
    {
        $domain = trim((string) $this->shopify_shop_domain);
        if ($domain === '') {
            return null;
        }

        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = rtrim($domain, '/');

        if (! str_contains($domain, '.')) {
            $domain .= '.myshopify.com';
        }

        return $domain;
    }

    /** @return list<string> */
    public function preorderTagList(): array
    {
        $tags = $this->preorder_tags ?? config('commerce-assist.preorder_tags', []);

        return array_values(array_filter(array_map(
            fn ($tag) => strtolower(trim((string) $tag)),
            is_array($tags) ? $tags : [],
        )));
    }
}
