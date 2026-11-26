<?php

namespace App\Services;

use App\Ai\EmbeddingService;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Laravel\Ai\Ai;
use Laravel\Ai\Enums\Lab;

class AiSettingsService
{
    /**
     * Every role that resolves its own model. The first two are the
     * original per-task overrides; the rest are the agent roles. (The old
     * `reply_suggestions` role went with the legacy drafter: drafts are the
     * agent's `writer` role now.)
     */
    public const TASKS = [
        'auto_categorization',
        'summarization',
        'triage',
        'history_extraction',
        'writer',
        'validator',
        'simulation_judge',
        'manager',
    ];

    /**
     * Model each role uses on Anthropic when the workspace sets no override.
     * The validator and simulation judge deliberately use a different model
     * from the writer so a draft is never checked by the model that wrote it.
     */
    public const ANTHROPIC_ROLE_DEFAULTS = [
        'auto_categorization' => 'claude-haiku-5-5',
        'summarization' => 'claude-haiku-5-5',
        'triage' => 'claude-haiku-5-5',
        'history_extraction' => 'claude-haiku-5-5',
        'writer' => 'claude-opus-5-5',
        'validator' => 'claude-sonnet-5-5',
        'simulation_judge' => 'claude-sonnet-5-5',
        'manager' => 'claude-opus-5-5',
    ];

    /** Roles whose reasoning effort is capped at `low` (see resolveCredentials). */
    public const LIGHT_ROLES = ['auto_categorization', 'summarization', 'triage', 'history_extraction'];

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
     * Prism (the gateway under laravel/ai 0.3) only switches Anthropic
     * structured output from a forced `tool_choice` to native
     * `output_config.format` when this string appears in its global beta
     * config. Forced tool_choice is rejected by claude-opus-5-5 and
     * claude-sonnet-5-5, so it is set for every Anthropic call. It only
     * selects the strategy: the beta header Prism sends comes from the
     * per-request config laravel/ai builds, and output_config.format is GA.
     */
    public const PRISM_NATIVE_STRUCTURED_OUTPUT_FLAG = 'structured-outputs-2025-11-13';

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
        $baseUrl = $settings['ai_base_url'] ?? null;
        $key = $this->decrypt($settings['ai_api_key'] ?? null);

        $reasoning = $this->normalizeReasoning($settings['ai_reasoning'] ?? []);
        // Sorting and extraction roles do not get better with deep reasoning,
        // only slower and dearer: they never run above `low`.
        if ($task !== null && in_array($task, self::LIGHT_ROLES, true)
            && array_search($reasoning['effort'], self::REASONING_EFFORTS, true) > array_search('low', self::REASONING_EFFORTS, true)) {
            $reasoning['effort'] = 'low';
        }
        $providerOptions = $this->buildProviderOptions($provider, $reasoning);

        $lab = $this->labFor($provider);

        return [
            'lab' => $lab,
            'model' => $this->resolveModel($provider, $lab, $settings, $task),
            'key' => $key,
            'provider' => $provider,
            'base_url' => $baseUrl,
            'provider_options' => $providerOptions,
            'reasoning' => $reasoning,
        ];
    }

    /**
     * Pick the model for a role.
     *
     * Order: the role's own override, then (Anthropic only) the role's
     * recommended model, then the workspace default model. A non-Anthropic
     * provider never receives a hard-coded claude-* ID: with nothing
     * configured it gets the provider's own default model.
     *
     * @param  array<string, mixed>  $settings
     */
    public function resolveModel(string $provider, Lab $lab, array $settings, ?string $task): ?string
    {
        $taskModels = $settings['ai_task_models'] ?? [];
        $defaultModel = $this->nullableString($settings['ai_model'] ?? null);

        if ($task !== null && ($override = $this->nullableString($taskModels[$task] ?? null)) !== null) {
            return $override;
        }

        if ($provider === 'anthropic') {
            if ($task !== null && isset(self::ANTHROPIC_ROLE_DEFAULTS[$task])) {
                return self::ANTHROPIC_ROLE_DEFAULTS[$task];
            }

            return $defaultModel;
        }

        if ($defaultModel !== null) {
            return $defaultModel;
        }

        // Without this the agent's #[Model('claude-…')] attribute would be
        // sent to an OpenAI-format endpoint.
        try {
            return Ai::textProvider($lab->value)->defaultTextModel();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Run a callback with workspace AI credentials injected into config, then
     * restore all original config values in a finally block.
     *
     * Octane-safe: config changes are scoped to the closure execution window
     * and always restored — no permanent global config mutation. laravel/ai
     * caches provider instances (with the key they were built with), so the
     * affected instances are forgotten on the way in and on the way out;
     * otherwise a queue worker would keep using the first workspace's key.
     *
     * Usage in AI jobs:
     *   $aiSettings->withWorkspaceCredentials($workspaceId, function (Lab $lab, ?string $model, array $options) {
     *       (new MyAgent)->withProviderOptions($options)->prompt('...', provider: $lab, model: $model);
     *   }, task: 'writer');
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

        $instances = match ($creds['provider']) {
            'openai', 'openai-compatible' => ['openai'],
            'openrouter' => ['openrouter'],
            default => ['anthropic'],
        };

        $originalKey = config($configKey);
        $originalUrl = config('ai.providers.openai.url');
        $originalOpenRouterUrl = config('ai.providers.openrouter.url');
        $originalPrismBeta = config('prism.providers.anthropic.anthropic_beta');

        try {
            if ($creds['key']) {
                config([$configKey => $creds['key']]);
            }
            if ($creds['provider'] === 'openai-compatible' && $creds['base_url']) {
                config(['ai.providers.openai.url' => $creds['base_url']]);
            }
            if ($creds['provider'] === 'anthropic') {
                config(['prism.providers.anthropic.anthropic_beta' => $this->withNativeStructuredOutput($originalPrismBeta)]);
            }

            $this->forgetProviderInstances($instances);

            return $callback($creds['lab'], $creds['model'], $creds['provider_options']);
        } finally {
            // Always restore — prevents config leakage into subsequent requests/jobs
            config([$configKey => $originalKey]);
            config(['ai.providers.openai.url' => $originalUrl]);
            config(['ai.providers.openrouter.url' => $originalOpenRouterUrl]);
            config(['prism.providers.anthropic.anthropic_beta' => $originalPrismBeta]);

            $this->forgetProviderInstances($instances);
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
     * Return sanitised AI config for the frontend (API keys are never exposed).
     */
    public function getForWorkspace(int $workspaceId): array
    {
        $workspace = Workspace::findOrFail($workspaceId);
        $settings = $workspace->settings ?? [];
        $taskModels = $settings['ai_task_models'] ?? [];

        $models = [];
        foreach (self::TASKS as $task) {
            $models[$task] = $this->nullableString($taskModels[$task] ?? null);
        }

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
            'task_models' => $models,
            'role_defaults' => self::ANTHROPIC_ROLE_DEFAULTS,
            'reasoning' => $this->normalizeReasoning($settings['ai_reasoning'] ?? []),
            'rag' => $settings['ai_rag'] ?? [
                'top_k' => 5,
                'min_score' => 0.7,
            ],
            'embeddings' => app(EmbeddingService::class)->describe($workspaceId),
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
        $settings['ai_model'] = $this->nullableString($validated['model'] ?? null);

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

        $taskModels = [];
        foreach (self::TASKS as $task) {
            $taskModels[$task] = $this->nullableString($validated["model_{$task}"] ?? null);
        }
        $settings['ai_task_models'] = $taskModels;

        $settings['ai_reasoning'] = $this->normalizeReasoning([
            'effort' => $validated['reasoning_effort'] ?? 'none',
            'exclude' => (bool) ($validated['reasoning_exclude'] ?? false),
            'max_tokens' => $validated['reasoning_max_tokens'] ?? null,
        ]);

        $settings['ai_rag'] = [
            'top_k' => (int) ($validated['rag_top_k'] ?? 5),
            'min_score' => (float) ($validated['rag_min_score'] ?? 0.7),
        ];

        if (array_key_exists('embeddings_provider', $validated)) {
            $settings['ai_embeddings'] = $this->embeddingsSettings($settings['ai_embeddings'] ?? [], $validated);
        }

        $workspace->settings = $settings;
        $workspace->save();

        Cache::forget("workspace.ai_settings.{$workspaceId}");
    }

    /**
     * Build provider-specific options for reasoning / thinking.
     *
     * Anthropic gets nothing. Prism can only express Anthropic thinking as
     * `{type: "enabled", budget_tokens: N}`, which claude-opus-5-5,
     * claude-sonnet-5-5 and claude-haiku-5-5 reject with a 400; those models
     * think adaptively by default. Effort (`output_config.effort`) is not
     * something Prism 0.99 can send.
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

        return match ($provider) {
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
     * Raw (still encrypted) workspace settings, cached for five minutes.
     *
     * @return array<string, mixed>
     */
    public function settingsFor(int $workspaceId): array
    {
        return Cache::remember(
            "workspace.ai_settings.{$workspaceId}",
            now()->addMinutes(5),
            fn () => Workspace::findOrFail($workspaceId)->settings ?? [],
        );
    }

    public function decrypt(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function labFor(string $provider): Lab
    {
        return match ($provider) {
            'anthropic' => Lab::Anthropic,
            'openrouter' => Lab::OpenRouter,
            default => Lab::OpenAI,
        };
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function embeddingsSettings(array $current, array $validated): array
    {
        $provider = $this->nullableString($validated['embeddings_provider'] ?? null);

        if ($provider === null) {
            return ['provider' => null];
        }

        $embeddings = [
            'provider' => $provider,
            'model' => $this->nullableString($validated['embeddings_model'] ?? null),
            'base_url' => $provider === 'openai-compatible'
                ? $this->nullableString($validated['embeddings_base_url'] ?? null)
                : null,
            'dimensions' => (int) ($validated['embeddings_dimensions'] ?? EmbeddingService::DIMENSIONS),
            'api_key' => $current['api_key'] ?? null,
        ];

        if (! empty($validated['embeddings_api_key'])) {
            $embeddings['api_key'] = Crypt::encryptString($validated['embeddings_api_key']);
        }

        if (! empty($validated['embeddings_clear_key'])) {
            $embeddings['api_key'] = null;
        }

        return $embeddings;
    }

    private function withNativeStructuredOutput(mixed $betas): string
    {
        $list = array_filter(array_map('trim', explode(',', (string) $betas)));

        if (! in_array(self::PRISM_NATIVE_STRUCTURED_OUTPUT_FLAG, $list, true)) {
            $list[] = self::PRISM_NATIVE_STRUCTURED_OUTPUT_FLAG;
        }

        return implode(',', $list);
    }

    /**
     * @param  list<string>  $names
     */
    private function forgetProviderInstances(array $names): void
    {
        try {
            Ai::forgetInstance($names);
        } catch (\Throwable) {
            // Manager not bound (e.g. minimal test bootstraps): nothing cached to forget.
        }
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
