<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    */
    'default' => env('AI_DEFAULT_PROVIDER', 'anthropic'),

    'providers' => [
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
            'url' => env('ANTHROPIC_BASE_URL'),
        ],
        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_BASE_URL'),
        ],
        // OpenAI-compatible endpoint slot — used at runtime for Groq,
        // Together, Ollama, and any other provider that speaks the OpenAI API format.
        // Credentials and base URL are injected from workspace.settings by
        // AiSettingsService::withWorkspaceCredentials() — ENV vars are fallback only.
        'openai-compatible' => [
            'driver' => 'openai',
            'key' => env('OPENAI_COMPATIBLE_API_KEY', ''),
            'url' => env('OPENAI_COMPATIBLE_BASE_URL', ''),
        ],

        // OpenRouter — routes requests to 200+ models (Claude, GPT-4o, Gemini, Llama…)
        // via a single OpenAI-compatible API. Set OPENROUTER_API_KEY in .env and choose
        // any model slug listed on openrouter.ai/models.
        'openrouter' => [
            'driver' => 'openai',
            'key' => env('OPENROUTER_API_KEY', ''),
            'url' => 'https://openrouter.ai/api/v1',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Feature Flags
    |--------------------------------------------------------------------------
    */
    'features' => [
        'reply_suggestions' => env('AI_REPLY_SUGGESTIONS', true),
        'auto_categorization' => env('AI_AUTO_CATEGORIZATION', true),
        'summarization' => env('AI_SUMMARIZATION', true),
        'rag' => env('AI_RAG', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | RAG Settings
    |--------------------------------------------------------------------------
    */
    'rag' => [
        'chunk_size' => 1000,
        'chunk_overlap' => 200,
        'top_k' => 5,
        'min_score' => 0.7,
        'embedding_dimensions' => 1536,
        // Postgres text-search configuration for keyword retrieval
        // (to_tsvector / plainto_tsquery). 'simple' skips stemming.
        'fts_config' => env('AI_FTS_CONFIG', 'english'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Embeddings
    |--------------------------------------------------------------------------
    |
    | Embeddings are configured per workspace (Settings → AI Config). Anthropic
    | has no embeddings API, so they never follow the chat provider silently.
    | Setting AI_EMBEDDINGS_ENV_FALLBACK=true lets workspaces without their own
    | embeddings settings use OPENAI_API_KEY; the settings page shows when that
    | fallback is the active source. Vectors must be 1536-dimensional to fit
    | the pgvector columns.
    |
    */
    'embeddings' => [
        'env_fallback' => (bool) env('AI_EMBEDDINGS_ENV_FALLBACK', false),
        'env_key' => env('OPENAI_API_KEY'),
        'env_model' => env('AI_EMBEDDINGS_MODEL', 'text-embedding-3-small'),
    ],

];
