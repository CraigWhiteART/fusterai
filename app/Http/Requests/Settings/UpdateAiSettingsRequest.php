<?php

namespace App\Http\Requests\Settings;

use App\Ai\EmbeddingService;
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
        $rules = [
            'provider' => ['required', 'in:anthropic,openai,openai-compatible,openrouter'],
            'api_key' => ['nullable', 'string', 'max:500'],
            // Roles fall back to claude-* defaults only on Anthropic, so every
            // other provider needs a default model to fall back to.
            'model' => ['nullable', 'required_unless:provider,anthropic', 'string', 'max:150'],
            'base_url' => ['nullable', 'url', 'max:255'],
            'feature_reply_suggestions' => ['boolean'],
            'feature_auto_categorization' => ['boolean'],
            'feature_summarization' => ['boolean'],
            'reasoning_effort' => ['nullable', 'string', Rule::in(AiSettingsService::REASONING_EFFORTS)],
            'reasoning_exclude' => ['boolean'],
            'reasoning_max_tokens' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'rag_top_k' => ['integer', 'min:1', 'max:10'],
            'rag_min_score' => ['numeric', 'min:0', 'max:1'],

            // Embeddings: leave the provider empty for "not configured".
            'embeddings_provider' => ['sometimes', 'nullable', Rule::in(EmbeddingService::PROVIDERS)],
            'embeddings_api_key' => ['nullable', 'string', 'max:500'],
            'embeddings_clear_key' => ['boolean'],
            'embeddings_model' => ['nullable', 'required_if:embeddings_provider,openai-compatible', 'string', 'max:150'],
            'embeddings_base_url' => ['nullable', 'required_if:embeddings_provider,openai-compatible', 'url', 'max:255'],
            // The pgvector columns are vector(1536); other sizes cannot be stored.
            'embeddings_dimensions' => ['nullable', 'integer', Rule::in([EmbeddingService::DIMENSIONS])],
        ];

        foreach (AiSettingsService::TASKS as $task) {
            $rules["model_{$task}"] = ['nullable', 'string', 'max:150'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'model.required_unless' => 'Choose a default model. Roles without their own model use it.',
            'embeddings_dimensions.in' => 'Embeddings must be '.EmbeddingService::DIMENSIONS.'-dimensional to fit the database columns.',
        ];
    }
}
