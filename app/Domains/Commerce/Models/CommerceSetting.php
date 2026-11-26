<?php

namespace App\Domains\Commerce\Models;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string|null $shopify_shop_domain
 * @property string|null $shopify_client_id
 * @property string|null $shopify_client_secret
 * @property string|null $shopify_access_token
 * @property Carbon|null $shopify_access_token_expires_at
 * @property string|null $shopify_granted_scopes
 * @property string|null $shopify_api_version
 * @property string|null $tracking_provider
 * @property string|null $tracking_api_key
 * @property string|null $tracking_store_uuid
 * @property int|null $tracking_stale_days
 * @property int|null $example_edit_threshold
 * @property list<string>|null $preorder_tags
 * @property bool $draft_only
 */
class CommerceSetting extends Model
{
    protected $table = 'commerce_settings';

    protected $fillable = [
        'workspace_id',
        'shopify_shop_domain',
        'shopify_client_id',
        'shopify_client_secret',
        'shopify_access_token',
        'shopify_access_token_expires_at',
        'shopify_granted_scopes',
        'shopify_api_version',
        'tracking_provider',
        'tracking_api_key',
        'tracking_store_uuid',
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
        'shopify_access_token_expires_at' => 'datetime',
    ];

    protected $hidden = [
        'shopify_client_secret',
        'shopify_access_token',
        'tracking_api_key',
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
                'shopify_api_version' => (string) config('commerce.shopify_api_version', '2026-10'),
                'tracking_provider' => (string) config('commerce.tracking_provider', 'none'),
                'tracking_stale_days' => (int) config('commerce.tracking_stale_days', 14),
                'example_edit_threshold' => (int) config('commerce.example_edit_threshold', 25),
                'preorder_tags' => config('commerce.preorder_tags', ['preorder']),
                'draft_only' => (bool) config('commerce.draft_only', true),
            ],
        );
    }

    public function decryptAccessToken(): ?string
    {
        return $this->decryptHidden('shopify_access_token');
    }

    public function decryptClientSecret(): ?string
    {
        return $this->decryptHidden('shopify_client_secret');
    }

    public function hasShopifyCredentials(): bool
    {
        return filled($this->shopify_shop_domain)
            && filled($this->shopify_client_id)
            && filled($this->decryptClientSecret());
    }

    public function secretIsSet(): bool
    {
        return filled($this->shopify_client_secret);
    }

    public function tokenIsSet(): bool
    {
        return filled($this->shopify_access_token);
    }

    private function decryptHidden(string $attribute): ?string
    {
        $value = $this->getAttribute($attribute);
        if (blank($value)) {
            return null;
        }

        try {
            return Crypt::decryptString((string) $value);
        } catch (\Throwable) {
            return null;
        }
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

    public function decryptTrackingApiKey(): ?string
    {
        if (blank($this->tracking_api_key)) {
            return null;
        }

        try {
            return Crypt::decryptString($this->tracking_api_key);
        } catch (\Throwable) {
            return null;
        }
    }

    public function trackingKeyIsSet(): bool
    {
        return filled($this->tracking_api_key);
    }

    public function trackingEnabled(): bool
    {
        return $this->tracking_provider !== null
            && $this->tracking_provider !== ''
            && $this->tracking_provider !== 'none'
            && filled($this->decryptTrackingApiKey());
    }

    /**
     * Track123 keys its Shopify endpoints by the myshopify subdomain. Fall back
     * to the subdomain of the configured shop so most workspaces never have to
     * fill this in.
     */
    public function trackingStoreUuid(): ?string
    {
        $explicit = trim((string) $this->tracking_store_uuid);
        if ($explicit !== '') {
            return $explicit;
        }

        $domain = $this->shopDomain();
        if ($domain === null) {
            return null;
        }

        $subdomain = explode('.', $domain)[0] ?? '';

        return $subdomain !== '' ? $subdomain : null;
    }

    /** @return list<string> */
    public function preorderTagList(): array
    {
        $tags = $this->preorder_tags ?? config('commerce.preorder_tags', []);

        return array_values(array_filter(array_map(
            fn ($tag) => strtolower(trim((string) $tag)),
            is_array($tags) ? $tags : [],
        )));
    }
}
