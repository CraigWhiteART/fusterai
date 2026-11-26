<?php

require_once __DIR__.'/AgentTestHelpers.php';

use App\Domains\Agent\Models\KnowledgeItem;
use App\Domains\Agent\Pipeline\AgentPipeline;
use App\Domains\Agent\Pipeline\PipelineInput;
use App\Domains\Agent\Services\AgentBootstrapper;
use App\Domains\Conversation\Models\AiSuggestion;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/*
 * Drives the real laravel/ai -> Prism -> Anthropic path with only the HTTP
 * layer faked: proves the writer's bounded tool loop (search_knowledge then
 * submit_reply) works through Prism, and shows exactly what is sent.
 */

function agentTAnthropic(array $content, string $stopReason, string $model, int $in = 10, int $out = 5): array
{
    return [
        'id' => 'msg_'.uniqid(),
        'type' => 'message',
        'role' => 'assistant',
        'model' => $model,
        'content' => $content,
        'stop_reason' => $stopReason,
        'stop_sequence' => null,
        'usage' => ['input_tokens' => $in, 'output_tokens' => $out],
    ];
}

test('the writer tool loop runs through Prism: search_knowledge, then submit_reply', function () {
    $case = agentTCase('Hi, how long does shipping to New Zealand take? Order SD8421.');
    app(AgentBootstrapper::class)->ensureForWorkspace($case['workspace']->id);
    $item = KnowledgeItem::create([
        'workspace_id' => $case['workspace']->id, 'kind' => 'policy', 'status' => 'active',
        'title' => 'Delivery times', 'body' => 'Orders to New Zealand arrive in 7 to 12 business days.',
        'scope' => ['playbooks' => [], 'products' => [], 'countries' => [], 'global' => true],
    ]);
    $kid = 'K'.$item->id;

    Http::fake(function (HttpRequest $request) use ($kid) {
        if (! str_contains($request->url(), 'api.anthropic.com')) {
            return Http::response([], 404);
        }
        $data = $request->data();
        $model = $data['model'];

        if ($model === 'claude-haiku-5-5') {
            return Http::response(agentTAnthropic([['type' => 'text', 'text' => json_encode([
                'language' => 'en', 'sentiment' => 'calm', 'risk_flags' => [], 'playbook' => 'order_status', 'confidence' => 0.9, 'reason' => 'Shipping time question',
            ])]], 'end_turn', $model, 100, 20));
        }
        if ($model === 'claude-sonnet-5-5') {
            return Http::response(agentTAnthropic([['type' => 'text', 'text' => json_encode(['verdict' => 'pass', 'issues' => []])]], 'end_turn', $model, 300, 10));
        }

        $results = collect($data['messages'])->flatMap(fn ($m) => is_array($m['content']) ? $m['content'] : [])
            ->where('type', 'tool_result')->count();

        return Http::response(match ($results) {
            0 => agentTAnthropic([
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'search_knowledge', 'input' => ['query' => 'New Zealand delivery time']],
            ], 'tool_use', $model, 1000, 50),
            1 => agentTAnthropic([
                ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'submit_reply', 'input' => [
                    'reply' => "Hi Alex,\n\nOrders to New Zealand arrive in 7 to 12 business days.\n\nCheers,",
                    'claims' => [['text' => 'NZ delivery takes 7 to 12 business days', 'sources' => [$kid]]],
                    'rules_used' => [],
                    'handover_reason' => null,
                    'internal_note' => 'Answered from the delivery times policy.',
                ]],
            ], 'tool_use', $model, 1200, 120),
            default => agentTAnthropic([['type' => 'text', 'text' => 'DONE']], 'end_turn', $model, 1300, 2),
        });
    });

    $run = app(AgentPipeline::class)->run(PipelineInput::fromConversation($case['conversation']->fresh()))->run;

    expect($run->decision)->toBe('draft')
        ->and($run->draft)->toContain('7 to 12 business days')
        ->and($run->claims[0]['sources'])->toBe([$kid])
        ->and(collect($run->trace['writer']['tool_log'])->pluck('tool')->all())->toBe(['search_knowledge', 'submit_reply'])
        ->and($run->tokens_in)->toBe(100 + 1000 + 1200 + 1300 + 300)
        ->and($run->cost_micro_usd)->toBeGreaterThan(0)
        ->and(AiSuggestion::where('agent_run_id', $run->id)->exists())->toBeTrue();

    $writerCalls = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'anthropic') && $r->data()['model'] === 'claude-opus-5-5')
        ->map(fn ($pair) => $pair[0]->data())->values();

    expect($writerCalls)->toHaveCount(3);
    $first = $writerCalls[0];
    $toolNames = collect($first['tools'])->pluck('name')->all();
    $system = collect($first['system'])->pluck('text')->implode("\n");

    expect($toolNames)->toBe(['search_knowledge', 'lookup_order', 'lookup_tracking', 'propose_action', 'handover', 'submit_reply'])
        ->and($first)->not->toHaveKeys(['output_config', 'temperature', 'thinking'])
        ->and($first['tool_choice'] ?? ['type' => 'auto'])->toBe(['type' => 'auto'])
        ->and($system)->not->toContain('SD8421')
        ->and($system)->not->toContain('how long does shipping');

    // The tool result went back as an untrusted block in a tool_result message.
    $second = $writerCalls[1];
    $toolResult = collect($second['messages'])->flatMap(fn ($m) => is_array($m['content']) ? $m['content'] : [])->firstWhere('type', 'tool_result');
    $nonce = $run->trace['nonce'];
    expect((string) json_encode($toolResult))->toContain("UNTRUSTED_{$nonce} kind=tool_result_search_knowledge")
        ->and((string) json_encode($toolResult))->toContain('7 to 12 business days');

    // Known limitation (see report): Prism only replays thinking blocks when
    // thinking is explicitly enabled, so the adaptive-thinking block from step
    // one is not sent back with the tool_use turn.
    $assistant = collect($second['messages'])->firstWhere('role', 'assistant');
    expect(collect($assistant['content'])->pluck('type')->all())->not->toContain('thinking');
});
