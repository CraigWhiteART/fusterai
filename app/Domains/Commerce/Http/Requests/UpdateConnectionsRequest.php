<?php

namespace App\Domains\Commerce\Http\Requests;

use App\Domains\Commerce\Services\Tracking\TrackingProviderFactory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /agent/connections. Secrets are write-only: a blank secret keeps the
 * stored one. Only settings the agent pipeline still reads are accepted.
 */
class UpdateConnectionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-settings') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'shopify_shop_domain' => ['nullable', 'string', 'max:255', 'regex:/^(https?:\/\/)?[a-z0-9][a-z0-9.\-]*\/?$/i'],
            'shopify_client_id' => ['nullable', 'string', 'max:255'],
            'shopify_client_secret' => ['nullable', 'string', 'max:255'],
            'shopify_api_version' => ['nullable', 'string', 'max:20', 'regex:/^(\d{4}-\d{2}|unstable)$/'],
            'tracking_provider' => ['nullable', 'string', Rule::in(TrackingProviderFactory::keys())],
            'tracking_api_key' => ['nullable', 'string', 'max:255'],
            'tracking_store_uuid' => ['nullable', 'string', 'max:255'],
            'tracking_stale_days' => ['required', 'integer', 'min:1', 'max:90'],
            'preorder_tags' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'shopify_shop_domain.regex' => 'Enter the shop domain, e.g. your-store.myshopify.com.',
            'shopify_api_version.regex' => 'Use a Shopify API version such as 2026-10.',
        ];
    }
}
