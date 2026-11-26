<?php

use App\Ai\EmbeddingService;
use App\Domains\AI\Jobs\IndexKbDocumentJob;
use App\Domains\AI\Models\KnowledgeBase;
use App\Models\Workspace;
use App\Services\AiSettingsService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;

beforeEach(function () {
    $this->workspace = Workspace::factory()->create(['settings' => [
        'ai_provider' => 'anthropic',
        'ai_api_key' => Crypt::encryptString('sk-ant-test'),
    ]]);
    $this->admin = adminUser($this->workspace);
    config(['ai.embeddings.env_fallback' => false, 'ai.embeddings.env_key' => 'sk-env-openai']);
});

test('embeddings are not configured by default, even with an OPENAI_API_KEY in the environment', function () {
    $service = app(EmbeddingService::class);

    expect($service->isConfigured($this->workspace->id))->toBeFalse()
        ->and($service->describe($this->workspace->id))->toMatchArray([
            'configured' => false,
            'source' => null,
            'dimensions' => 1536,
        ]);
});

test('the env key is used only as an explicit, reported fallback', function () {
    config(['ai.embeddings.env_fallback' => true]);

    expect(app(EmbeddingService::class)->describe($this->workspace->id))->toMatchArray([
        'configured' => true,
        'source' => 'env',
        'active_provider' => 'openai',
        'active_model' => 'text-embedding-3-small',
    ]);
});

test('an embeddings provider with no key of its own reuses the main key of the same provider', function () {
    $this->workspace->update(['settings' => [
        'ai_provider' => 'openai',
        'ai_api_key' => Crypt::encryptString('sk-main-openai'),
        'ai_embeddings' => ['provider' => 'openai'],
    ]]);

    $resolved = app(EmbeddingService::class)->resolve($this->workspace->id);

    expect($resolved['source'])->toBe('workspace_main_key')
        ->and($resolved['key'])->toBe('sk-main-openai');
});

test('admin can save embeddings settings; the key is encrypted and never returned', function () {
    $this->actingAs($this->admin)
        ->patch('/settings/ai', [
            'provider' => 'anthropic',
            'model_writer' => 'claude-opus-5-5',
            'model_validator' => 'claude-sonnet-5-5',
            'embeddings_provider' => 'openai',
            'embeddings_api_key' => 'sk-embed-secret',
            'embeddings_model' => 'text-embedding-3-large',
            'embeddings_dimensions' => 1536,
            'rag_top_k' => 5,
            'rag_min_score' => 0.7,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $settings = $this->workspace->fresh()->settings;

    expect($settings['ai_task_models']['writer'])->toBe('claude-opus-5-5')
        ->and($settings['ai_task_models']['validator'])->toBe('claude-sonnet-5-5')
        ->and($settings['ai_embeddings']['provider'])->toBe('openai')
        ->and($settings['ai_embeddings']['api_key'])->not->toBe('sk-embed-secret')
        ->and(Crypt::decryptString($settings['ai_embeddings']['api_key']))->toBe('sk-embed-secret');

    $config = app(AiSettingsService::class)->getForWorkspace($this->workspace->id);

    expect($config['embeddings'])->toMatchArray(['configured' => true, 'source' => 'workspace', 'key_set' => true])
        ->and(json_encode($config))->not->toContain('sk-embed-secret');

    $this->actingAs($this->admin)
        ->get('/settings/ai')
        ->assertInertia(fn ($page) => $page
            ->component('Settings/AIConfig')
            ->where('aiConfig.embeddings.source', 'workspace')
            ->where('aiConfig.role_defaults.validator', 'claude-sonnet-5-5'));
});

test('embeddings dimensions other than 1536 are rejected', function () {
    $this->actingAs($this->admin)
        ->patch('/settings/ai', [
            'provider' => 'anthropic',
            'embeddings_provider' => 'openai',
            'embeddings_dimensions' => 768,
        ])
        ->assertSessionHasErrors('embeddings_dimensions');
});

test('a non-anthropic provider needs a default model', function () {
    $this->actingAs($this->admin)
        ->patch('/settings/ai', ['provider' => 'openai'])
        ->assertSessionHasErrors('model');
});

test('test-embeddings reports the not-configured state', function () {
    $this->actingAs($this->admin)
        ->postJson('/settings/ai/test-embeddings')
        ->assertOk()
        ->assertJson(['ok' => false, 'source' => null]);
});

test('test-embeddings succeeds with a configured provider', function () {
    Embeddings::fake();
    $this->workspace->update(['settings' => [
        'ai_provider' => 'anthropic',
        'ai_embeddings' => ['provider' => 'openai', 'api_key' => Crypt::encryptString('sk-embed')],
    ]]);

    $this->actingAs($this->admin)
        ->postJson('/settings/ai/test-embeddings')
        ->assertOk()
        ->assertJson(['ok' => true, 'source' => 'workspace', 'dimensions' => 1536]);
});

test('embedding requests use the workspace embeddings key, model and dimensions over HTTP', function () {
    Http::fake(['api.openai.com/*' => Http::response([
        'object' => 'list',
        'model' => 'text-embedding-3-small',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => array_fill(0, 1536, 0.01)]],
        'usage' => ['prompt_tokens' => 3, 'total_tokens' => 3],
    ])]);
    $this->workspace->update(['settings' => [
        'ai_provider' => 'anthropic',
        'ai_embeddings' => ['provider' => 'openai', 'api_key' => Crypt::encryptString('sk-embed-workspace')],
    ]]);

    $vectors = app(EmbeddingService::class)->embed($this->workspace->id, ['hello']);

    expect($vectors[0])->toHaveCount(1536);

    $request = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'api.openai.com'))->last()[0];

    expect($request->header('Authorization')[0])->toBe('Bearer sk-embed-workspace')
        ->and($request->data())->toMatchArray(['model' => 'text-embedding-3-small', 'dimensions' => 1536]);
});

test('vectors of the wrong size are rejected before they reach the database', function () {
    Embeddings::fake(fn () => [array_fill(0, 768, 0.1)]);
    $this->workspace->update(['settings' => [
        'ai_embeddings' => ['provider' => 'openai', 'api_key' => Crypt::encryptString('sk-embed')],
    ]]);

    expect(fn () => app(EmbeddingService::class)->embed($this->workspace->id, ['x']))
        ->toThrow(RuntimeException::class, '768 dimensions');
});

test('indexing jobs skip cleanly when embeddings are not configured', function () {
    Embeddings::fake();

    $kb = KnowledgeBase::create(['workspace_id' => $this->workspace->id, 'name' => 'Docs', 'active' => true]);
    $doc = $kb->documents()->create(['title' => 'Returns', 'content' => 'Returns within 30 days.']);
    (new IndexKbDocumentJob($doc))->handle();

    $fresh = $doc->fresh();

    expect($fresh->embedding)->toBeNull()
        ->and($fresh->indexed_at)->not->toBeNull()
        ->and($fresh->meta['embedding_skipped'] ?? null)->toBe('Embeddings are not configured');

    Embeddings::assertNothingGenerated();
});

test('indexing jobs store vectors when embeddings are configured', function () {
    Embeddings::fake();
    $this->workspace->update(['settings' => [
        'ai_embeddings' => ['provider' => 'openai', 'api_key' => Crypt::encryptString('sk-embed')],
    ]]);

    $kb = KnowledgeBase::create(['workspace_id' => $this->workspace->id, 'name' => 'Docs', 'active' => true]);
    $doc = $kb->documents()->create(['title' => 'Returns', 'content' => 'Returns within 30 days.']);
    (new IndexKbDocumentJob($doc))->handle();

    expect(DB::table('kb_documents')->where('id', $doc->id)->whereNotNull('embedding')->exists())->toBeTrue()
        ->and($doc->fresh()->meta)->not->toHaveKey('embedding_skipped');
});
