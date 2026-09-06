<?php

namespace App\Services;

use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Laravel\Ai\Enums\Lab;

class AiSettingsService
{
    public const TASKS = [
        'reply_suggestions',
        'auto_categorization',
        'summarization',
    ];

    public const REASONING_EFFORTS = [
        'none',
        'minimal',
        'low',
        'medium',
        'high',
        'xhigh',
        'max',
    ];

    /**
     * Resolve workspace AI credentials without any side effects.
     *
     * @return array{
     *     lab: Lab,
     *     model: string|null,
     *     key: string|null,
     *     provider: string,
     *     base_url: string|null,
     *     provider_options: array<string, mixed>,
     *     reasoning: array{effort: string, exclude: bool, max_tokens: int|null}
     * }
     */
    public function resolveCredentials(int $workspaceId, ?string $task = null): array
    {
        $settings = $this->settingsFor($workspaceId);

        $provider = $settings['ai_provider'] ?? 'anthropic';
        $defaultModel = $settings['ai_model'] ?? null;
        $taskModels = $settings['ai_task_models'] ?? [];
        $model = $defaultModel;

        if ($task && ! empty($taskModels[$task])) {
            $model = $taskModels[$task];
        }

        $baseUrl = $settings['ai_base_url'] ?? null;
        $key = null;

        if (! empty($settings['ai_api_key'])) {
            try {
                $key = Crypt::decryptString($settings['ai_api_key']);
            } catch (\Exception) {
                $key = null;
            }
        }

        $reasoning = $this->normalizeReasoning($settings['ai_reasoning'] ?? []);
        $providerOptions = $this->buildProviderOptions($provider, $reasoning);

        $lab = match ($provider) {
            'anthropic' => Lab::Anthropic,
            'openrouter' => Lab::OpenRouter,
            default => Lab::OpenAI,
        };

        return [
            'lab' => $lab,
            'model' => $model,
            'key' => $key,
            'provider' => $provider,
            'base_url' => $baseUrl,
            'provider_options' => $providerOptions,
            'reasoning' => $reasoning,
        ];
    }

    /**
     * Run a callback with workspace AI credentials injected into config, then
     * restore all original config values in a finally block.
     *
     * Octane-safe: config changes are scoped to the closure execution window
     * and always restored — no permanent global config mutation.
     *
     * Usage in AI jobs:
     *   $aiSettings->withWorkspaceCredentials($workspaceId, function (Lab $lab, ?string $model, array $options) {
     *       (new MyAgent)->withProviderOptions($options)->prompt('...', provider: $lab, model: $model);
     *   }, task: 'reply_suggestions');
     *
     * @template TReturn
     *
     * @param  callable(Lab, string|null, array<string, mixed>): TReturn|callable(): TReturn  $callback
     * @return TReturn
     */
    public function withWorkspaceCredentials(int $workspaceId, callable $callback, ?string $task = null): mixed
    {
        $creds = $this->resolveCredentials($workspaceId, $task);

        // Determine which config keys will be mutated so we can restore them
        $configKey = match ($creds['provider']) {
            'openai', 'openai-compatible' => 'ai.providers.openai.key',
            'openrouter' => 'ai.providers.openrouter.key',
            default => 'ai.providers.anthropic.key',
        };

        $originalKey = config($configKey);
        $originalUrl = config('ai.providers.openai.url');
        $originalOpenRouterUrl = config('ai.providers.openrouter.url');

        try {
            if ($creds['key']) {
                config([$configKey => $creds['key']]);
            }
            if ($creds['provider'] === 'openai-compatible' && $creds['base_url']) {
                config(['ai.providers.openai.url' => $creds['base_url']]);
            }

            return $callback($creds['lab'], $creds['model'], $creds['provider_options']);
        } finally {
            // Always restore — prevents config leakage into subsequent requests/jobs
            config([$configKey => $originalKey]);
            config(['ai.providers.openai.url' => $originalUrl]);
            config(['ai.providers.openrouter.url' => $originalOpenRouterUrl]);
        }
    }

    /**
     * Check whether a workspace-level AI feature flag is enabled.
     */
    public function isFeatureEnabled(int $workspaceId, string $feature): bool
    {
        $settings = $this->settingsFor($workspaceId);

        return (bool) (($settings['ai_features'] ?? [])[$feature] ?? config("ai.features.{$feature}", true));
    }

    /**
     * Return sanitised AI config for the frontend (API key is never exposed).
     */
    public function getForWorkspace(int $workspaceId): array
    {
        $workspace = Workspace::findOrFail($workspaceId);
        $settings = $workspace->settings ?? [];
        $taskModels = $settings['ai_task_models'] ?? [];

        return [
            'provider' => $settings['ai_provider'] ?? 'anthropic',
            'model' => $settings['ai_model'] ?? null,
            'base_url' => $settings['ai_base_url'] ?? null,
            'key_set' => ! empty($settings['ai_api_key']),
            'features' => $settings['ai_features'] ?? [
                'reply_suggestions' => true,
                'auto_categorization' => true,
                'summarization' => true,
            ],
            'task_models' => [
                'reply_suggestions' => $taskModels['reply_suggestions'] ?? null,
                'auto_categorization' => $taskModels['auto_categorization'] ?? null,
                'summarization' => $taskModels['summarization'] ?? null,
            ],
            'reasoning' => $this->normalizeReasoning($settings['ai_reasoning'] ?? []),
            'rag' => $settings['ai_rag'] ?? [
                'top_k' => 5,
                'min_score' => 0.7,
            ],
        ];
    }

    /**
     * Validate and persist AI settings for a workspace.
     * Passing an empty api_key keeps the existing encrypted key unchanged.
     */
    public function saveForWorkspace(int $workspaceId, array $validated): void
    {
        $workspace = Workspace::findOrFail($workspaceId);
        $settings = $workspace->settings ?? [];

        $settings['ai_provider'] = $validated['provider'];
        $settings['ai_model'] = $validated['model'] ?? null;

        if ($validated['provider'] === 'openai-compatible') {
            $settings['ai_base_url'] = $validated['base_url'] ?? null;
        } else {
            unset($settings['ai_base_url']);
        }

        if (! empty($validated['api_key'])) {
            $settings['ai_api_key'] = Crypt::encryptString($validated['api_key']);
        }

        $settings['ai_features'] = [
            'reply_suggestions' => (bool) ($validated['feature_reply_suggestions'] ?? true),
            'auto_categorization' => (bool) ($validated['feature_auto_categorization'] ?? true),
            'summarization' => (bool) ($validated['feature_summarization'] ?? true),
        ];

        $settings['ai_task_models'] = [
            'reply_suggestions' => $this->nullableString($validated['model_reply_suggestions'] ?? null),
            'auto_categorization' => $this->nullableString($validated['model_auto_categorization'] ?? null),
            'summarization' => $this->nullableString($validated['model_summarization'] ?? null),
        ];

        $settings['ai_reasoning'] = $this->normalizeReasoning([
            'effort' => $validated['reasoning_effort'] ?? 'none',
            'exclude' => (bool) ($validated['reasoning_exclude'] ?? false),
            'max_tokens' => $validated['reasoning_max_tokens'] ?? null,
        ]);

        $settings['ai_rag'] = [
            'top_k' => (int) ($validated['rag_top_k'] ?? 5),
            'min_score' => (float) ($validated['rag_min_score'] ?? 0.7),
        ];

        $workspace->settings = $settings;
        $workspace->save();

        Cache::forget("workspace.ai_settings.{$workspaceId}");
    }

    /**
     * Build provider-specific options for reasoning / thinking.
     *
     * @param  array{effort: string, exclude: bool, max_tokens: int|null}  $reasoning
     * @return array<string, mixed>
     */
    public function buildProviderOptions(string $provider, array $reasoning): array
    {
        $effort = $reasoning['effort'] ?? 'none';

        if ($effort === 'none' || $effort === '') {
            return [];
        }

        $budget = $reasoning['max_tokens'] ?? match ($effort) {
            'minimal' => 512,
            'low' => 1024,
            'medium' => 4096,
            'high' => 8192,
            'xhigh', 'max' => 16384,
            default => 4096,
        };

        return match ($provider) {
            'anthropic' => [
                'thinking' => [
                    'enabled' => true,
                    'budgetTokens' => (int) $budget,
                ],
            ],
            // OpenRouter accepts the OpenAI-style reasoning object and merges
            // arbitrary providerOptions into the chat-completions payload.
            'openrouter', 'openai', 'openai-compatible' => [
                'reasoning' => array_filter([
                    'effort' => $effort,
                    'exclude' => (bool) ($reasoning['exclude'] ?? false),
                    'max_tokens' => $reasoning['max_tokens'] ?? null,
                    'enabled' => true,
                ], fn ($value) => $value !== null),
            ],
            default => [],
        };
    }

    /**
     * @return array{effort: string, exclude: bool, max_tokens: int|null}
     */
    public function normalizeReasoning(array $reasoning): array
    {
        $effort = (string) ($reasoning['effort'] ?? 'none');
        if (! in_array($effort, self::REASONING_EFFORTS, true)) {
            $effort = 'none';
        }

        $maxTokens = $reasoning['max_tokens'] ?? null;
        if ($maxTokens !== null && $maxTokens !== '') {
            $maxTokens = max(1, (int) $maxTokens);
        } else {
            $maxTokens = null;
        }

        return [
            'effort' => $effort,
            'exclude' => (bool) ($reasoning['exclude'] ?? false),
            'max_tokens' => $maxTokens,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsFor(int $workspaceId): array
    {
        return Cache::remember(
            "workspace.ai_settings.{$workspaceId}",
            now()->addMinutes(5),
            fn () => Workspace::findOrFail($workspaceId)->settings ?? [],
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
