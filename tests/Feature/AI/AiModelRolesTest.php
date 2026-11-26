<?php

use App\Ai\Agents\CategorizationAgent;
use App\Ai\Agents\SummarizationAgent;
use App\Domains\Agent\Agents\ValidatorAgent;
use App\Domains\Agent\Agents\WriterAgent;
use App\Domains\AI\Jobs\CategorizeConversationJob;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Customer\Models\Customer;
use App\Domains\Mailbox\Models\Mailbox;
use App\Models\Workspace;
use App\Services\AiSettingsService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\ObjectSchema;

/**
 * These tests drive the real laravel/ai → Prism → Anthropic code path with the
 * HTTP layer faked, so they check the exact request body the app sends to
 * the Messages API without a live call.
 */
function anthropicWorkspace(array $settings = []): Workspace
{
    return Workspace::factory()->create(['settings' => array_merge([
        'ai_provider' => 'anthropic',
        'ai_api_key' => Crypt::encryptString('sk-ant-test'),
    ], $settings)]);
}

function anthropicMessage(string $text, string $model = 'claude-haiku-5-5'): array
{
    return [
        'id' => 'msg_test',
        'type' => 'message',
        'role' => 'assistant',
        'model' => $model,
        'content' => [
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
            ['type' => 'text', 'text' => $text],
        ],
        'stop_reason' => 'end_turn',
        'stop_sequence' => null,
        'usage' => ['input_tokens' => 12, 'output_tokens' => 7],
    ];
}

/** @return array<string, mixed> */
function lastAnthropicPayload(): array
{
    $recorded = Http::recorded(fn (HttpRequest $request) => str_contains($request->url(), 'api.anthropic.com'));

    expect($recorded)->not->toBeEmpty();

    return $recorded->last()[0]->data();
}

test('anthropic roles resolve to their own default models', function () {
    $workspace = anthropicWorkspace();
    $service = app(AiSettingsService::class);

    $model = fn (string $task) => $service->resolveCredentials($workspace->id, $task)['model'];

    expect($model('triage'))->toBe('claude-haiku-5-5')
        ->and($model('history_extraction'))->toBe('claude-haiku-5-5')
        ->and($model('writer'))->toBe('claude-opus-5-5')
        ->and($model('validator'))->toBe('claude-sonnet-5-5')
        ->and($model('simulation_judge'))->toBe('claude-sonnet-5-5')
        ->and($model('manager'))->toBe('claude-opus-5-5')
        ->and($model('auto_categorization'))->toBe('claude-haiku-5-5')
        ->and($model('summarization'))->toBe('claude-haiku-5-5')
        ->and($model('validator'))->not->toBe($model('writer'));
});

test('a role override beats the role default', function () {
    $workspace = anthropicWorkspace(['ai_task_models' => ['validator' => 'claude-haiku-5-5']]);

    expect(app(AiSettingsService::class)->resolveCredentials($workspace->id, 'validator')['model'])
        ->toBe('claude-haiku-5-5');
});

test('non-anthropic providers never receive a claude default', function () {
    $workspace = Workspace::factory()->create(['settings' => [
        'ai_provider' => 'openai',
        'ai_model' => 'gpt-test',
        'ai_task_models' => ['writer' => 'gpt-writer'],
    ]]);
    $service = app(AiSettingsService::class);

    expect($service->resolveCredentials($workspace->id, 'writer')['model'])->toBe('gpt-writer')
        ->and($service->resolveCredentials($workspace->id, 'validator')['model'])->toBe('gpt-test')
        ->and($service->resolveCredentials($workspace->id, 'triage')['lab'])->toBe(Lab::OpenAI);

    $bare = Workspace::factory()->create(['settings' => ['ai_provider' => 'openrouter']]);
    $fallback = $service->resolveCredentials($bare->id, 'validator')['model'];

    expect($fallback)->not->toBeNull()
        ->and(str_starts_with((string) $fallback, 'claude-'))->toBeFalse();
});

test('anthropic gets no thinking options; openai-style providers keep reasoning', function () {
    $service = app(AiSettingsService::class);
    $reasoning = ['effort' => 'high', 'exclude' => false, 'max_tokens' => 4000];

    expect($service->buildProviderOptions('anthropic', $reasoning))->toBe([])
        ->and($service->buildProviderOptions('openrouter', $reasoning)['reasoning']['effort'])->toBe('high')
        ->and($service->buildProviderOptions('openai', $reasoning))->toHaveKey('reasoning');
});

test('structured agent request uses native output_config and no sampling, thinking or forced tool_choice', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicMessage(json_encode([
        'priority' => 'high', 'tags' => ['shipping'], 'summary' => 'Late order',
    ])))]);

    $workspace = anthropicWorkspace(['ai_reasoning' => ['effort' => 'high']]);
    $mailbox = Mailbox::factory()->create(['workspace_id' => $workspace->id]);
    $conversation = Conversation::factory()->create([
        'workspace_id' => $workspace->id,
        'mailbox_id' => $mailbox->id,
        'customer_id' => Customer::factory()->create(['workspace_id' => $workspace->id])->id,
    ]);
    Thread::factory()->create(['conversation_id' => $conversation->id, 'body' => 'Where is my order?']);

    (new CategorizeConversationJob($conversation))->handle();

    $payload = lastAnthropicPayload();

    expect($payload['model'])->toBe('claude-haiku-5-5')
        ->and($payload)->not->toHaveKeys(['temperature', 'top_p', 'top_k', 'thinking', 'tool_choice'])
        ->and($payload['output_config']['format']['type'])->toBe('json_schema')
        ->and($payload['output_config']['format']['schema']['additionalProperties'])->toBeFalse()
        ->and($payload['max_tokens'])->toBe(2048)
        ->and($conversation->fresh()->priority->value)->toBe('high');

    // The Prism strategy flag only selects the strategy: it is not sent as a
    // beta header, and it is restored after the call.
    $headers = Http::recorded()->last()[0]->header('anthropic-beta');
    expect(implode(',', $headers))->not->toContain('structured-outputs')
        ->and((string) config('prism.providers.anthropic.anthropic_beta'))->not->toContain('structured-outputs');
});

test('nested structured schemas close additional properties for native structured output', function () {
    $schema = (new ObjectSchema((new ValidatorAgent('Check the draft.'))->schema(new JsonSchemaTypeFactory)))->toArray();

    expect($schema['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['issues']['items']['additionalProperties'])->toBeFalse();
});

test('writer and validator calls go to different models with no temperature', function () {
    Http::fakeSequence('api.anthropic.com/*')
        ->push(anthropicMessage('Hi Sam, your order shipped.', 'claude-opus-5-5'))
        ->push(anthropicMessage(json_encode(['verdict' => 'pass', 'issues' => []]), 'claude-sonnet-5-5'));

    $workspace = anthropicWorkspace();
    $service = app(AiSettingsService::class);

    $service->withWorkspaceCredentials($workspace->id, fn ($lab, $model, $options) => (new WriterAgent('Write a reply.', []))
        ->withProviderOptions($options)
        ->prompt('Customer: where is my order?', provider: $lab, model: $model), task: 'writer');
    $writer = lastAnthropicPayload();

    $service->withWorkspaceCredentials($workspace->id, fn ($lab, $model, $options) => (new ValidatorAgent('Check the draft.'))
        ->withProviderOptions($options)
        ->prompt('PROPOSED RESPONSE: Hi', provider: $lab, model: $model), task: 'validator');
    $validator = lastAnthropicPayload();

    expect($writer['model'])->toBe('claude-opus-5-5')
        ->and($writer)->not->toHaveKeys(['temperature', 'thinking', 'tool_choice', 'output_config'])
        ->and($validator['model'])->toBe('claude-sonnet-5-5')
        ->and($validator)->not->toHaveKeys(['temperature', 'thinking', 'tool_choice']);
});

test('each workspace call uses that workspace key even after a provider instance was cached', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicMessage('ok'))]);

    $first = anthropicWorkspace(['ai_api_key' => Crypt::encryptString('sk-ant-first')]);
    $second = anthropicWorkspace(['ai_api_key' => Crypt::encryptString('sk-ant-second')]);
    $service = app(AiSettingsService::class);

    foreach ([$first, $second] as $workspace) {
        $service->withWorkspaceCredentials($workspace->id, fn ($lab, $model, $options) => (new SummarizationAgent)
            ->withProviderOptions($options)
            ->prompt('Summarize', provider: $lab, model: $model), task: 'summarization');
    }

    $keys = Http::recorded()->map(fn ($pair) => $pair[0]->header('x-api-key')[0] ?? null)->values()->all();

    expect($keys)->toBe(['sk-ant-first', 'sk-ant-second']);
});

test('categorization agent fake still works with role defaults', function () {
    CategorizationAgent::fake([[
        'priority' => 'low', 'tags' => ['general'], 'summary' => 'Hello',
    ]]);

    $workspace = anthropicWorkspace();
    $response = app(AiSettingsService::class)->withWorkspaceCredentials(
        $workspace->id,
        fn ($lab, $model, $options) => (new CategorizationAgent)->withProviderOptions($options)->prompt('x', provider: $lab, model: $model),
        task: 'auto_categorization',
    );

    expect($response->toArray()['priority'])->toBe('low');
});
