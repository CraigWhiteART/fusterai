<?php

use App\Domains\Agent\Agents\ComposeReplyAgent;
use App\Domains\Agent\Agents\ConflictJudgeAgent;
use App\Domains\Agent\Agents\SimulationJudgeAgent;
use App\Domains\Agent\Agents\TriageAgent;
use App\Domains\Agent\Agents\ValidatorAgent;
use App\Domains\Agent\Agents\WriterAgent;
use App\Domains\Agent\Models\AgentExample;
use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\FrozenCase;
use App\Domains\Agent\Models\KnowledgeItem;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Services\AgentBootstrapper;
use App\Domains\Agent\Simulation\FrozenCaseFactory;
use App\Domains\Commerce\Models\ShopifySnapshot;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Models\User;

/**
 * Fixtures for the feedback-loop / simulation tests. Prefixed `loopT` so
 * they never collide with other Pest helper files.
 *
 * @return array<string, mixed> agentTCase() keys + playbook (order_status, with liveVersion)
 */
function loopTWorkspace(string $message = 'Hi, where is my order SD8421? It has been a while.'): array
{
    $f = agentTCase($message);
    app(AgentBootstrapper::class)->ensureForWorkspace($f['workspace']->id);
    $f['playbook'] = Playbook::with('liveVersion')->where('workspace_id', $f['workspace']->id)->where('slug', 'order_status')->sole();

    return $f;
}

function loopTKnowledge(int $ws, string $body, array $scope = ['global' => true]): KnowledgeItem
{
    return KnowledgeItem::create([
        'workspace_id' => $ws, 'kind' => 'fact', 'body' => $body, 'status' => 'active',
        'scope' => KnowledgeItem::normalizeScope($scope),
    ]);
}

function loopTExample(int $ws, string $reply, string $playbook = 'order_status', string $customer = 'Where is my order?'): AgentExample
{
    return AgentExample::create([
        'workspace_id' => $ws, 'customer_message' => $customer, 'reply' => $reply,
        'playbook_slug' => $playbook, 'status' => 'active', 'source' => 'manual',
    ]);
}

/** Another frozen case with the same evidence and a slightly different customer message. */
function loopTCloneCase(FrozenCase $case, string $suffix, array $overrides = []): FrozenCase
{
    $transcript = $case->transcript;
    $transcript[count($transcript) - 1]['text'] .= ' '.$suffix;

    return FrozenCase::create(array_merge([
        'workspace_id' => $case->workspace_id, 'conversation_id' => $case->conversation_id, 'cut_thread_id' => $case->cut_thread_id,
        'subject' => $case->subject, 'customer_name' => $case->customer_name, 'customer_email' => $case->customer_email,
        'reply_to' => $case->reply_to ?? [], 'transcript' => $transcript, 'cut_at' => $case->cut_at, 'evidence' => $case->evidence,
        'evidence_quality' => $case->evidence_quality, 'evidence_hash' => $case->evidence_hash, 'evidence_prepared_at' => $case->evidence_prepared_at,
        'human_reply' => $case->human_reply, 'source' => 'manual', 'playbook_slug' => $case->playbook_slug,
        'content_hash' => hash('sha256', $case->content_hash.$suffix),
    ], $overrides));
}

/** Adds a staff reply after the customer thread and freezes the case at the customer message. */
function loopTFrozenCase(array $f, string $humanReply = 'Hi Alex, your order SD8421 ships tomorrow. Cheers', ?User $staff = null): FrozenCase
{
    loopTStaffReply($f['conversation'], $humanReply, $staff);

    return app(FrozenCaseFactory::class)->fromConversation($f['conversation'], $f['thread'], 'manual');
}

function loopTStaffReply(Conversation $conversation, string $text, ?User $staff = null, ?string $origin = null): Thread
{
    $staff ??= User::factory()->create(['workspace_id' => $conversation->workspace_id, 'role' => 'agent', 'name' => 'Sam Staff']);

    return Thread::factory()->create([
        'conversation_id' => $conversation->id,
        'customer_id' => null,
        'user_id' => $staff->id,
        'type' => 'message',
        'body' => '<p>'.e($text).'</p>',
        'body_plain' => $text,
        'origin' => $origin ?? Thread::ORIGIN_FUSTERAI,
        'created_at' => now()->addMinute(),
        'sent_at' => now()->addMinute(),
    ]);
}

/** A recorded Shopify snapshot for the fixture's conversation (what a live run would have stored). */
function loopTSnapshot(array $f, array $payload = [], $fetchedAt = null): ShopifySnapshot
{
    return ShopifySnapshot::create([
        'workspace_id' => $f['workspace']->id,
        'conversation_id' => $f['conversation']->id,
        'customer_email' => 'buyer@example.com',
        'order_number' => 'SD8421',
        'payload' => array_merge([
            'found' => true, 'order_number' => 'SD8421', 'order_email' => 'buyer@example.com', 'shopify_order_id' => 'gid://shopify/Order/99',
            'payment_status' => 'PAID', 'fulfilment_status' => 'UNFULFILLED', 'order_date' => '2026-11-20', 'products' => [],
        ], $payload),
        'fetched_at' => $fetchedAt ?? now(),
    ]);
}

/**
 * Fakes every pipeline model for any number of runs. Replies are used in
 * order (the last one repeats).
 *
 * @param  list<string>  $replies
 */
function loopTFakePipeline(array $replies = ['Hi Alex, thanks for waiting. Your order is being prepared now.'], string $playbook = 'order_status'): void
{
    TriageAgent::fake(fn () => agentTStructured(['language' => 'en', 'sentiment' => 'calm', 'risk_flags' => [], 'playbook' => $playbook, 'confidence' => 0.9, 'reason' => 'Order question.'], 100, 10, 'claude-haiku-5-5'));
    WriterAgent::fake(fn () => 'DONE');
    $queue = $replies;
    ComposeReplyAgent::fake(function () use (&$queue) {
        $reply = count($queue) > 1 ? array_shift($queue) : $queue[0];

        return agentTStructured(['reply' => $reply, 'claims' => [], 'rules_used' => [], 'handover_reason' => null, 'internal_note' => 'ok'], 1000, 200, 'claude-opus-5-5');
    });
    ValidatorAgent::fake(fn () => agentTStructured(['verdict' => 'pass', 'issues' => []], 10, 5, 'claude-sonnet-5-5'));
}

/** Judge: a reply containing $badPhrase is not sendable. */
function loopTFakeJudge(string $badPhrase = 'guaranteed'): void
{
    SimulationJudgeAgent::fake(function (string $prompt) use ($badPhrase) {
        $ai = (string) (preg_match('/kind=ai_output>>>\n(.*?)\n<<<END_UNTRUSTED/s', $prompt, $m) ? $m[1] : '');
        $bad = str_contains(mb_strtolower($ai), $badPhrase);

        return agentTStructured(['outcome_match' => $bad ? 2 : 5, 'policy_consistency' => $bad ? 2 : 5, 'factual_grounding' => 4, 'tone_match' => 4,
            'would_send' => ! $bad, 'reason' => $bad ? 'Promises what the human did not.' : 'Matches the human.'], 50, 20, 'claude-sonnet-5-5');
    });
}

/**
 * Conflict judge: $decide(newText, existingText, existingLabel) returns a relation.
 *
 * @param  callable(string, string, string): string  $decide
 */
function loopTFakeConflictJudge(?callable $decide = null): void
{
    $decide ??= fn () => 'none';
    ConflictJudgeAgent::fake(function (string $prompt) use ($decide) {
        preg_match_all('/pair_id: (\S+)\nNEW \((.*?)\): <<<UNTRUSTED_\w+ kind=proposed>>>\n(.*?)\n<<<END_UNTRUSTED_\w+>>>\nEXISTING \((.*?)\): <<<UNTRUSTED_\w+ kind=existing>>>\n(.*?)\n<<<END_UNTRUSTED_\w+>>>/s', $prompt, $m, PREG_SET_ORDER);

        return agentTStructured(['pairs' => array_map(fn ($p) => [
            'pair_id' => $p[1], 'relation' => $decide($p[3], $p[5], $p[4]), 'explanation' => 'Checked.',
        ], $m)], 0, 0, 'claude-opus-5-5');
    });
}

/** A completed live draft run for the fixture's customer thread (as the pipeline would record it). */
function loopTLiveRun(array $f, string $draft = 'Hi Alex, your order is guaranteed to arrive Friday!', array $knowledgeIds = [], array $exampleIds = []): AgentRun
{
    return AgentRun::create([
        'workspace_id' => $f['workspace']->id,
        'purpose' => 'live',
        'conversation_id' => $f['conversation']->id,
        'customer_thread_id' => $f['thread']->id,
        'idempotency_key' => 'live:'.uniqid('', true),
        'status' => 'completed',
        'config' => ['voice_version_id' => null, 'models' => ['writer' => 'claude-opus-5-5']],
        'config_hash' => str_repeat('a', 64),
        'playbook_id' => $f['playbook']->id,
        'playbook_version_id' => $f['playbook']->live_version_id,
        'draft' => $draft,
        'decision' => 'draft',
        'rules_used' => [$f['playbook']->liveVersion->rules[0]['id']],
        'evidence' => ['facts' => [], 'notes' => [], 'guidance' => [], 'meta' => [],
            'knowledge' => array_map(fn ($id) => ['id' => 'K'.$id, 'kind' => 'fact', 'title' => null, 'body' => KnowledgeItem::find($id)?->body ?? '', 'scope' => ['global' => true]], $knowledgeIds)],
        'trace' => ['examples' => array_map(fn ($id) => 'X'.$id, $exampleIds)],
        'completed_at' => now(),
    ]);
}
