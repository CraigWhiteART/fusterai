<?php

use App\Domains\Agent\Mining\Agents\ExtractConversationAgent;
use App\Domains\Agent\Mining\Agents\MergeSituationsAgent;
use App\Domains\Agent\Mining\Agents\SynthesiseSituationAgent;
use App\Domains\Agent\Mining\Agents\VoiceProfileAgent;
use App\Domains\Agent\Mining\MiningRunner;
use App\Domains\Agent\Mining\MiningService;
use App\Domains\Agent\Mining\Models\MiningRun;
use App\Domains\Agent\Services\AgentBootstrapper;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Customer\Models\Customer;
use App\Domains\Mailbox\Models\Mailbox;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;

/*
 * Shared fixtures for tests/Feature/Agent/Mining. Function names are
 * prefixed with `mineT` so they never collide with other Pest helpers.
 */

/** @return array{workspace: Workspace, mailbox: Mailbox} */
function mineTWorkspace(): array
{
    $workspace = Workspace::factory()->create(['settings' => [
        'ai_provider' => 'anthropic',
        'ai_api_key' => Crypt::encryptString('sk-ant-test'),
    ]]);
    $mailbox = Mailbox::factory()->create(['workspace_id' => $workspace->id]);
    app(AgentBootstrapper::class)->ensureForWorkspace($workspace->id);

    return compact('workspace', 'mailbox');
}

/**
 * A closed conversation: customer message, then the human staff reply (and
 * optionally more turns). Returns the conversation.
 *
 * @param  array<string, mixed>  $opts  status, imported, staff_origin, followup (customer text after the reply), customer_name, customer_email, user_id, days_ago
 */
function mineTConversation(array $ctx, string $customerText, string $staffText, array $opts = []): Conversation
{
    static $n = 0;
    $n++;
    $at = Carbon::now()->subDays((int) ($opts['days_ago'] ?? 10))->setTime(9, 0);

    $customer = Customer::factory()->create([
        'workspace_id' => $ctx['workspace']->id,
        'name' => $opts['customer_name'] ?? "Zelda Quokka{$n}",
        'email' => $opts['customer_email'] ?? "zelda{$n}@example.com",
    ]);
    $conversation = Conversation::factory()->create([
        'workspace_id' => $ctx['workspace']->id,
        'mailbox_id' => $ctx['mailbox']->id,
        'customer_id' => $customer->id,
        'subject' => $opts['subject'] ?? 'Question about my order',
        'status' => $opts['status'] ?? 'closed',
    ]);
    $conversation->forceFill(['imported' => $opts['imported'] ?? true, 'last_reply_at' => $at->copy()->addHours(2)])->save();

    $make = function (array $attrs, Carbon $when) use ($conversation) {
        $thread = Thread::factory()->create($attrs + [
            'conversation_id' => $conversation->id,
            'type' => 'message',
            'sent_at' => $when,
        ]);
        $thread->created_at = $when;
        $thread->saveQuietly();

        return $thread;
    };

    $make(['customer_id' => $customer->id, 'user_id' => null, 'body' => '<p>'.e($customerText).'</p>', 'body_plain' => $customerText, 'origin' => 'import',
        'meta' => ['from_name' => $customer->name, 'from_email' => $customer->email]], $at);
    if ($staffText !== '') {
        $make(['customer_id' => null, 'user_id' => $opts['user_id'] ?? null, 'body' => '<p>'.e($staffText).'</p>', 'body_plain' => $staffText,
            'origin' => $opts['staff_origin'] ?? 'import', 'meta' => ['from_name' => $opts['staff_name'] ?? 'Craig', 'from_email' => $opts['staff_email'] ?? 'craig@souldial.test']], $at->copy()->addHour());
    }
    if (isset($opts['followup'])) {
        $make(['customer_id' => $customer->id, 'user_id' => null, 'body' => '<p>'.e($opts['followup']).'</p>', 'body_plain' => $opts['followup'], 'origin' => 'import'], $at->copy()->addHours(2));
    }

    return $conversation->fresh();
}

function mineTResponse(array $data, int $in = 0, int $out = 0, string $model = 'claude-haiku-5-5'): StructuredAgentResponse
{
    return new StructuredAgentResponse(
        invocationId: 'fake-'.uniqid(),
        structured: $data,
        text: (string) json_encode($data),
        usage: new Usage($in, $out),
        meta: new Meta('anthropic', $model),
    );
}

/** Extraction keyed on keywords in the conversation text. */
function mineTExtraction(string $prompt): array
{
    $situation = match (true) {
        str_contains($prompt, 'refund') => 'refund_request',
        str_contains($prompt, 'warranty') => 'warranty_claim',
        str_contains($prompt, 'broken') => 'warranty_issue',
        str_contains($prompt, 'buy in bulk') => 'bulk_purchase',
        str_contains($prompt, 'SEO services') => 'spam',
        default => 'order_status',
    };
    $statements = [];
    if (preg_match('/within (\d+) days/', $prompt, $m) === 1) {
        $statements[] = ['kind' => 'policy', 'topic' => 'refund_window', 'text' => "Refunds are available within {$m[1]} days of delivery.", 'said_at' => ''];
    }

    return [
        'category' => str_contains($prompt, 'SEO services') ? 'vendor' : 'support',
        'situation' => $situation,
        'situation_is_new' => ! in_array($situation, ['refund_request', 'order_status'], true),
        'situation_description' => 'The customer asks about '.str_replace('_', ' ', $situation).'.',
        'customer_asked' => 'Asked about '.str_replace('_', ' ', $situation).'.',
        'decisions' => $situation === 'refund_request' ? ['refund_full', 'explained_policy'] : ['gave_tracking'],
        'decision_summary' => 'Handled it.',
        'statements' => $statements,
        'tone' => ['greeting' => 'Hi [name],', 'sign_off' => 'Cheers, Craig', 'formality' => 'casual', 'humour' => false, 'empathy' => true, 'apology' => false, 'emoji' => false, 'phrases' => ['No worries at all']],
        'outcome' => 'thanked',
        'outcome_note' => 'Customer said thanks.',
        'language' => 'en',
        'exemplary' => true,
    ];
}

function mineTFakeExtraction(int $in = 0, int $out = 0): void
{
    ExtractConversationAgent::fake(fn (string $prompt) => mineTResponse(mineTExtraction($prompt), $in, $out));
}

/** Conversation ids shown to the synthesis model. */
function mineTSampleIds(string $prompt): array
{
    preg_match_all('/\[conversation (\d+)\]/', $prompt, $m);

    return array_map('intval', $m[1]);
}

/** @param  array<string, mixed>  $overrides */
function mineTSynthesis(string $prompt, array $overrides = []): array
{
    $ids = mineTSampleIds($prompt);
    $first = $ids[0] ?? 0;
    $last = $ids[count($ids) - 1] ?? 0;

    return array_merge([
        'name' => 'Learned situation',
        'when_text' => 'The customer asks about this situation.',
        'when_text_changed' => false,
        'rules' => [
            ['op' => 'add', 'rule_id' => null, 'if' => 'the customer asks for a refund', 'then' => 'Explain the refund window and ask for the order number.', 'evidence' => [$first, 999999], 'rationale' => 'Staff always did this.'],
            ['op' => 'add', 'rule_id' => null, 'if' => 'always', 'then' => 'A rule nobody demonstrated.', 'evidence' => [999999], 'rationale' => 'Invented.'],
        ],
        'steps' => [['op' => 'add', 'step_id' => null, 'text' => 'Look up the order first.', 'evidence' => [$first]]],
        'escalation' => null,
        'escalation_evidence' => [],
        'required_evidence' => ['order'],
        'suggested_actions' => [['action' => 'refund', 'when' => 'Order arrived damaged', 'evidence' => [$first]]],
        'knowledge' => [
            ['kind' => 'policy', 'topic' => 'shipping_time', 'title' => 'Shipping time', 'body' => 'Orders ship from China and take 10 to 15 business days.', 'scope' => ['global' => true, 'playbooks' => [], 'products' => [], 'countries' => []], 'evidence' => [$first]],
            ['kind' => 'policy', 'topic' => 'refund_window', 'title' => 'Refund window', 'body' => 'Refunds within 30 days of delivery.', 'scope' => ['global' => false, 'playbooks' => ['refund_request'], 'products' => [], 'countries' => []], 'evidence' => [$first]],
        ],
        'inconsistencies' => $first !== $last ? [[
            'topic' => 'refund_window',
            'summary' => 'The refund window changed.',
            'versions' => [['statement' => 'Refunds within 14 days.', 'evidence' => [$last]], ['statement' => 'Refunds within 30 days.', 'evidence' => [$first]]],
            'recommendation' => 'Use 30 days, the most recent.',
        ]] : [],
        'test_cases' => array_map(fn ($id) => ['conversation_id' => $id, 'kind' => 'representative', 'why' => 'Typical.', 'must_include' => ['order number'], 'must_not_include' => ['guaranteed'], 'expected_outcome' => 'reply'], array_slice($ids, 0, 2)),
        'rationale' => 'Staff handle this consistently.',
    ], $overrides);
}

function mineTFakeSynthesis(int $in = 0, int $out = 0, array $overrides = []): void
{
    SynthesiseSituationAgent::fake(fn (string $prompt) => mineTResponse(mineTSynthesis($prompt, $overrides), $in, $out, 'claude-opus-5-5'));
}

function mineTVoice(array $overrides = []): array
{
    return array_merge([
        'personality' => 'Warm, direct and a little playful.',
        'do_phrases' => [['op' => 'add', 'id' => null, 'text' => 'Use the customer\'s first name.']],
        'dont_phrases' => [['op' => 'add', 'id' => null, 'text' => 'We apologise for any inconvenience']],
        'greetings' => [['op' => 'add', 'id' => null, 'text' => 'Hi [name],']],
        'sign_offs' => [['op' => 'add', 'id' => null, 'text' => 'Cheers, the Soul Dial team']],
        'max_words' => 150,
        'length_guidance' => 'Short paragraphs.',
        'staff_differences' => [['topic' => 'length', 'note' => 'One writes longer replies.']],
        'example_phrases' => [['text' => 'No worries at all', 'staff' => 'Craig']],
        'rationale' => 'From the replies.',
    ], $overrides);
}

function mineTFakeVoice(int $in = 0, int $out = 0, array $overrides = []): void
{
    VoiceProfileAgent::fake(fn () => mineTResponse(mineTVoice($overrides), $in, $out, 'claude-opus-5-5'));
}

function mineTFakeMerge(?Closure $map = null): void
{
    MergeSituationsAgent::fake(function (string $prompt) use ($map) {
        preg_match_all('/^([a-z0-9_.]+) \(\d+ conversations\)/m', $prompt, $m);
        $mappings = array_map(fn ($label) => ['label' => $label, 'target' => $map ? $map($label) : $label], $m[1]);

        return mineTResponse(['mappings' => $mappings, 'new_situations' => [
            ['key' => 'warranty_claim', 'name' => 'Warranty claim', 'description' => 'A product stopped working.'],
        ]]);
    });
}

function mineTFakeAll(): void
{
    mineTFakeExtraction();
    mineTFakeMerge(fn ($label) => in_array($label, ['warranty_claim', 'warranty_issue'], true) ? 'warranty_claim' : $label);
    mineTFakeSynthesis();
    mineTFakeVoice();
}

function mineTStart(array $ctx, array $input = []): MiningRun
{
    return app(MiningService::class)->start($ctx['workspace']->id, $input + ['months' => 12, 'budget_usd' => 50], dispatch: false);
}

/** Drive the run to a stop (finished, paused, failed) the way the job chain would. */
function mineTRun(MiningRun $run, int $maxSteps = 60): MiningRun
{
    for ($i = 0; $i < $maxSteps; $i++) {
        $run->refresh();
        if (app(MiningRunner::class)->runStep($run->id, $run->job_token) === null) {
            break;
        }
    }

    return $run->refresh();
}
