<?php

use App\Models\Workspace;
use App\Services\AiSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('sorting and extraction roles never reason above low; the writer keeps the workspace setting', function () {
    $workspace = Workspace::factory()->create(['settings' => [
        'ai_provider' => 'openrouter', 'ai_model' => 'x/y', 'ai_reasoning' => ['effort' => 'high'],
    ]]);
    $ai = app(AiSettingsService::class);

    expect($ai->resolveCredentials($workspace->id, 'triage')['provider_options']['reasoning']['effort'])->toBe('low')
        ->and($ai->resolveCredentials($workspace->id, 'history_extraction')['reasoning']['effort'])->toBe('low')
        ->and($ai->resolveCredentials($workspace->id, 'writer')['provider_options']['reasoning']['effort'])->toBe('high')
        ->and($ai->resolveCredentials($workspace->id)['reasoning']['effort'])->toBe('high');
});
