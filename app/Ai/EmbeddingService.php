<?php

namespace App\Ai;

use App\Services\AiSettingsService;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Ai;
use Laravel\Ai\Embeddings;
use RuntimeException;

/**
 * Embeddings are configured separately from the chat provider: Anthropic has
 * no embeddings API, and the pgvector columns are fixed at 1536 dimensions.
 *
 * Sources, in order:
 *   - workspace:          settings.ai_embeddings with its own encrypted key
 *   - workspace_main_key: settings.ai_embeddings with no key of its own, and
 *                         the same provider as the main AI settings
 *   - env:                OPENAI_API_KEY, only when AI_EMBEDDINGS_ENV_FALLBACK=true
 *
 * With none of these, embeddings are "not configured": indexing skips and
 * retrieval uses keyword search only.
 */
class EmbeddingService
{
    public const DIMENSIONS = 1536;

    public const PROVIDERS = ['openai', 'openai-compatible'];

    public const DEFAULT_OPENAI_MODEL = 'text-embedding-3-small';

    /** Runtime-only provider slot so workspace keys never touch the shared `openai` config. */
    private const INSTANCE = 'fusterai-embeddings';

    public function __construct(private readonly AiSettingsService $aiSettings) {}

    /**
     * @return array{provider: string, key: string|null, base_url: string|null, model: string, dimensions: int, source: string}|null
     */
    public function resolve(int $workspaceId): ?array
    {
        $settings = $this->aiSettings->settingsFor($workspaceId);
        $embeddings = $settings['ai_embeddings'] ?? [];
        $provider = $embeddings['provider'] ?? null;

        if (is_string($provider) && in_array($provider, self::PROVIDERS, true)) {
            $key = $this->aiSettings->decrypt($embeddings['api_key'] ?? null);
            $source = 'workspace';

            if ($key === null && ($settings['ai_provider'] ?? null) === $provider) {
                $key = $this->aiSettings->decrypt($settings['ai_api_key'] ?? null);
                $source = 'workspace_main_key';
            }

            $baseUrl = $provider === 'openai-compatible' ? ($embeddings['base_url'] ?? null) : null;
            $model = $embeddings['model'] ?? ($provider === 'openai' ? self::DEFAULT_OPENAI_MODEL : null);

            // OpenAI needs a key; an OpenAI-compatible server (e.g. Ollama) may not.
            if (($provider === 'openai' && $key === null)
                || ($provider === 'openai-compatible' && empty($baseUrl))
                || empty($model)) {
                return null;
            }

            return [
                'provider' => $provider,
                'key' => $key,
                'base_url' => $baseUrl,
                'model' => (string) $model,
                'dimensions' => self::DIMENSIONS,
                'source' => $source,
            ];
        }

        if (config('ai.embeddings.env_fallback') && ($envKey = config('ai.embeddings.env_key'))) {
            return [
                'provider' => 'openai',
                'key' => (string) $envKey,
                'base_url' => null,
                'model' => (string) (config('ai.embeddings.env_model') ?: self::DEFAULT_OPENAI_MODEL),
                'dimensions' => self::DIMENSIONS,
                'source' => 'env',
            ];
        }

        return null;
    }

    public function isConfigured(int $workspaceId): bool
    {
        return $this->resolve($workspaceId) !== null;
    }

    /**
     * Settings-page view of the embeddings setup. Never includes a key.
     *
     * @return array<string, mixed>
     */
    public function describe(int $workspaceId): array
    {
        $settings = $this->aiSettings->settingsFor($workspaceId);
        $stored = $settings['ai_embeddings'] ?? [];
        $resolved = $this->resolve($workspaceId);

        return [
            'provider' => $stored['provider'] ?? null,
            'model' => $stored['model'] ?? null,
            'base_url' => $stored['base_url'] ?? null,
            'dimensions' => self::DIMENSIONS,
            'key_set' => ! empty($stored['api_key']),
            'configured' => $resolved !== null,
            'source' => $resolved['source'] ?? null,
            'active_provider' => $resolved['provider'] ?? null,
            'active_model' => $resolved['model'] ?? null,
            'env_fallback_enabled' => (bool) config('ai.embeddings.env_fallback'),
        ];
    }

    /**
     * Embed several inputs. Throws when embeddings are not configured or the
     * provider returns vectors that do not fit the 1536-dimension columns.
     *
     * @param  list<string>  $inputs
     * @return list<list<float>>
     */
    public function embed(int $workspaceId, array $inputs): array
    {
        $config = $this->resolve($workspaceId);

        if ($config === null) {
            throw new EmbeddingsNotConfiguredException('Embeddings are not configured for this workspace.');
        }

        config(['ai.providers.'.self::INSTANCE => array_filter([
            'driver' => 'openai',
            'key' => $config['key'] ?? '',
            'url' => $config['base_url'],
        ], fn ($value) => $value !== null)]);
        Ai::forgetInstance(self::INSTANCE);

        try {
            $response = Embeddings::for($inputs)
                ->dimensions($config['dimensions'])
                ->timeout(30)
                ->generate(self::INSTANCE, $config['model']);
        } finally {
            config(['ai.providers.'.self::INSTANCE => null]);
            Ai::forgetInstance(self::INSTANCE);
        }

        $vectors = array_values($response->embeddings);

        foreach ($vectors as $vector) {
            if (count($vector) !== self::DIMENSIONS) {
                throw new RuntimeException(sprintf(
                    'Embedding model "%s" returned %d dimensions; the database columns need %d.',
                    $config['model'],
                    count($vector),
                    self::DIMENSIONS,
                ));
            }
        }

        return $vectors;
    }

    /**
     * Query-time embedding. Returns null (keyword search only) when embeddings
     * are not configured or the provider call fails.
     *
     * @return list<float>|null
     */
    public function embedQuery(int $workspaceId, string $query): ?array
    {
        $query = trim($query);

        if ($query === '' || ! $this->isConfigured($workspaceId)) {
            return null;
        }

        try {
            return $this->embed($workspaceId, [mb_substr($query, 0, 8000)])[0] ?? null;
        } catch (\Throwable $e) {
            Log::warning('Query embedding failed; using keyword search only', [
                'workspace_id' => $workspaceId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Encode a vector for a pgvector (or JSON fallback) column.
     *
     * @param  list<float>  $vector
     */
    public static function toColumn(array $vector): string
    {
        return json_encode(array_values($vector), JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{ok: bool, message: string, source: string|null, dimensions?: int}
     */
    public function test(int $workspaceId): array
    {
        $config = $this->resolve($workspaceId);

        if ($config === null) {
            return ['ok' => false, 'message' => 'Embeddings are not configured. Save an embeddings provider, model and key first.', 'source' => null];
        }

        try {
            $vector = $this->embed($workspaceId, ['Embeddings connection test'])[0];

            return [
                'ok' => true,
                'message' => sprintf('Embeddings work: %s returned %d dimensions.', $config['model'], count($vector)),
                'source' => $config['source'],
                'dimensions' => count($vector),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Embeddings test failed: '.$e->getMessage(), 'source' => $config['source']];
        }
    }
}
