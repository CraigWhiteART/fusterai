<?php

namespace Modules\CommerceAssist\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shopify_shop_domain' => ['nullable', 'string', 'max:255'],
            'shopify_access_token' => ['nullable', 'string', 'max:255'],
            'shopify_api_version' => ['nullable', 'string', 'max:20'],
            'tracking_stale_days' => ['required', 'integer', 'min:1', 'max:90'],
            'example_edit_threshold' => ['required', 'integer', 'min:1', 'max:100'],
            'preorder_tags' => ['nullable', 'string', 'max:500'],
            'draft_only' => ['boolean'],
        ];
    }
}
