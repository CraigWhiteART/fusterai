<?php

namespace App\Http\Requests\Settings;

use App\Services\AiSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAiSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider' => ['required', 'in:anthropic,openai,openai-compatible,openrouter'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'model' => ['nullable', 'string', 'max:150'],
            'base_url' => ['nullable', 'url', 'max:255'],
            'feature_reply_suggestions' => ['boolean'],
            'feature_auto_categorization' => ['boolean'],
            'feature_summarization' => ['boolean'],
            'model_reply_suggestions' => ['nullable', 'string', 'max:150'],
            'model_auto_categorization' => ['nullable', 'string', 'max:150'],
            'model_summarization' => ['nullable', 'string', 'max:150'],
            'reasoning_effort' => ['nullable', 'string', Rule::in(AiSettingsService::REASONING_EFFORTS)],
            'reasoning_exclude' => ['boolean'],
            'reasoning_max_tokens' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'rag_top_k' => ['integer', 'min:1', 'max:10'],
            'rag_min_score' => ['numeric', 'min:0', 'max:1'],
        ];
    }
}
