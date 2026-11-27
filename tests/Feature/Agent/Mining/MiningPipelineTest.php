<?php

require_once __DIR__.'/MiningTestHelpers.php';

use App\Ai\EmbeddingService;
use App\Domains\Agent\Mining\Agents\ExtractConversationAgent;
use App\Domains\Agent\Mining\Agents\MergeSituationsAgent;
use App\Domains\Agent\Mining\Agents\SynthesiseSituationAgent;
use App\Domains\Agent\Mining\Agents\VoiceProfileAgent;
use App\Domains\Agent\Mining\Jobs\RunMiningStepJob;
use App\Domains\Agent\Mining\MiningPresenter;
use App\Domains\Agent\Mining\MiningRunner;
use App\Domains\Agent\Mining\MiningSelector;
use App\Domains\Agent\Mining\MiningService;
use App\Domains\Agent\Mining\Models\MiningConversation;
use App\Domains\Agent\Mining\Models\MiningExtraction;
use App\Domains\Agent\Mining\Models\MiningRun;
use App\Domains\Agent\Mining\Models\MiningSituation;
use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\FeedbackEvent;
use App\Domains\Agent\Models\FrozenCase;
use App\Domains\Agent\Models\Proposal;
use App\Domains\Agent\Proposals\ConfigFingerprint;
use App\Domains\Agent\Proposals\OpsApplier;
use App\Domains\Agent\Proposals\ProposalApplier;
use App\Domains\Agent\Proposals\ProposalService;
use App\Domains\Conversation\Models\Thread;
use App\Services\AiSettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Prompts\AgentPrompt;

beforeEach(function () {
    Queue::fake();
    $this->ctx = mineTWorkspace();
});

/** A small realistic history: order status, refunds (two policies over time), warranty labels, one vendor pitch. */
function mineTHistory(array $ctx): array
{
    return [
        'status1' => mineTConversation($ctx, 'Hi, where is my parcel? It has been two weeks.', 'Hi there, your parcel is with 4PX and moving. Cheers, Craig', ['days_ago' => 5]),
        'status2' => mineTConversation($ctx, 'Hello, has my order shipped yet?', 'Hi! Yes it shipped yesterday from our warehouse, tracking is on its way. Cheers, Craig', ['days_ago' => 40]),
        'status3' => mineTConversation($ctx, 'Any update on delivery?', 'Hey, it is in Sydney now and should arrive this week. Thanks, Mel', ['days_ago' => 70, 'staff_name' => 'Mel', 'staff_email' => 'mel@souldial.test']),
        'refund1' => mineTConversation($ctx, 'I want a refund please, it is not for me.', 'No worries, refunds are available within 30 days of delivery. Cheers, Craig', ['days_ago' => 8]),
        'refund2' => mineTConversation($ctx, 'Can I get a refund for my dial?', 'Sure, we do refunds within 30 days of delivery, send it back. Thanks, Mel', ['days_ago' => 30, 'staff_name' => 'Mel', 'staff_email' => 'mel@souldial.test']),
        'refund3' => mineTConversation($ctx, 'Refund request for my order', 'Sorry, refunds are only within 14 days of delivery. Cheers, Craig', ['days_ago' => 200]),
        'warranty1' => mineTConversation($ctx, 'My dial stopped working, is it under warranty?', 'Sorry to hear! Send a photo and we will sort a replacement. Cheers, Craig', ['days_ago' => 12]),
        'warranty2' => mineTConversation($ctx, 'The dial is broken after a month.', 'Oh no, please send a short video and we will replace it. Thanks, Mel', ['days_ago' => 25, 'staff_name' => 'Mel', 'staff_email' => 'mel@souldial.test']),
        'warranty3' => mineTConversation($ctx, 'Is the warranty still valid after 6 months?', 'Yes, our warranty is 12 months. Send a photo of the fault. Cheers, Craig', ['days_ago' => 60]),
        'vendor' => mineTConversation($ctx, 'We offer SEO services for your store at a great price.', 'No thanks.', ['days_ago' => 15]),
    ];
}

// ── Stage A: selection ─────────────────────────────────────────────────────

test('selection keeps closed conversations with a customer message and a human reply, inside the window', function () {
    $ctx = $this->ctx;
    $keep = mineTConversation($ctx, 'Where is my order?', 'It shipped yesterday.', ['days_ago' => 20]);
    $live = mineTConversation($ctx, 'Where is my order?', 'It shipped yesterday.', ['days_ago' => 20, 'imported' => false, 'staff_origin' => 'gmail']);
    $open = mineTConversation($ctx, 'Where is my order?', 'It shipped yesterday.', ['status' => 'open']);
    $noReply = mineTConversation($ctx, 'Where is my order?', '');
    $aiOnly = mineTConversation($ctx, 'Where is my order?', 'Your order shipped.', ['staff_origin' => 'ai']);
    $old = mineTConversation($ctx, 'Where is my order?', 'It shipped yesterday.', ['days_ago' => 500]);
    $deleted = mineTConversation($ctx, 'Where is my order?', 'It shipped yesterday.');
    $deleted->delete();

    // Sent as the AI drafted it, unchanged: not a human decision.
    $sentAsIs = mineTConversation($ctx, 'Where is my order?', 'Your order is on the way.');
    $staffThread = Thread::where('conversation_id', $sentAsIs->id)->whereNull('customer_id')->sole();
    FeedbackEvent::create(['workspace_id' => $ctx['workspace']->id, 'conversation_id' => $sentAsIs->id, 'thread_id' => $staffThread->id, 'kind' => 'sent_as_is']);
    // Edited AI draft: the human changed it, so it counts.
    $edited = mineTConversation($ctx, 'Where is my order?', 'Your order is on the way, sorry for the wait!');
    $editedThread = Thread::where('conversation_id', $edited->id)->whereNull('customer_id')->sole();
    FeedbackEvent::create(['workspace_id' => $ctx['workspace']->id, 'conversation_id' => $edited->id, 'thread_id' => $editedThread->id, 'kind' => 'sent_edited', 'percent_changed' => 30]);

    // Another workspace's history is never read.
    $other = mineTWorkspace();
    mineTConversation($other, 'Where is my order?', 'It shipped yesterday.');

    $ids = array_column(app(MiningSelector::class)->select($ctx['workspace']->id, now()->subMonths(12), now(), 100), 'id');

    expect($ids)->toContain($keep->id, $live->id, $edited->id)
        ->and($ids)->not->toContain($open->id, $noReply->id, $aiOnly->id, $old->id, $deleted->id, $sentAsIs->id)
        ->and($ids)->toHaveCount(3);
});

test('over the cap, stratified selection keeps every period and weights recent months', function () {
    $candidates = [];
    $id = 1;
    // 12 months, 100 conversations each, newest first.
    for ($m = 0; $m < 12; $m++) {
        $month = Carbon::parse('2026-11-15')->subMonthsNoOverflow($m);
        for ($i = 0; $i < 100; $i++) {
            $candidates[] = ['id' => $id++, 'last_at' => $month->copy()->subMinutes($i)->toDateTimeString(), 'bucket' => $month->format('Y-m')];
        }
    }

    $picked = app(MiningSelector::class)->stratify($candidates, 240, 7);
    $byMonth = collect($picked)->countBy('bucket');

    expect($picked)->toHaveCount(240)
        ->and($byMonth)->toHaveCount(12)                       // every month represented
        ->and($byMonth['2026-11'])->toBeGreaterThan($byMonth['2025-12']) // newest weighted higher
        ->and($byMonth->min())->toBeGreaterThanOrEqual(6)       // floor: 30% of 240 spread over 12 months
        ->and(collect($picked)->pluck('id')->unique())->toHaveCount(240);

    // Deterministic: the same options pick the same conversations again.
    expect(app(MiningSelector::class)->stratify($candidates, 240, 7))->toBe($picked);
});

// ── Full run ───────────────────────────────────────────────────────────────

test('a full run extracts, clusters, synthesises, profiles the voice and emits proposals with evidence', function () {
    Notification::fake();
    Mail::fake();
    Http::fake();
    mineTFakeAll();
    $conversations = mineTHistory($this->ctx);
    $all = collect($conversations)->map->id->values()->all();

    $run = mineTRun(mineTStart($this->ctx));

    expect($run->status)->toBe(MiningRun::STATUS_COMPLETED)
        ->and($run->stage)->toBe(MiningRun::STAGE_DONE)
        ->and($run->counter('selected'))->toBe(10)
        ->and($run->counter('extract_new'))->toBe(10)
        ->and($run->counters['dropped']['not_support_vendor'])->toBe(1);

    // Clustering: refund_request is an existing playbook, warranty_claim + warranty_issue merged into a new situation.
    $situations = MiningSituation::where('run_id', $run->id)->get()->keyBy('key');
    expect($situations->keys()->all())->toContain('refund_request', 'order_status', 'warranty_claim')
        ->and($situations['refund_request']->playbook_id)->not->toBeNull()
        ->and($situations['warranty_claim']->playbook_id)->toBeNull()
        ->and($situations['warranty_claim']->conversation_count)->toBe(3)
        ->and($situations['warranty_claim']->labels)->toHaveKeys(['warranty_claim', 'warranty_issue'])
        ->and($situations['refund_request']->status)->toBe(MiningSituation::STATUS_SYNTHESISED);

    // Proposals: one per synthesised situation, voice, and the summary of inconsistencies.
    $proposals = Proposal::where('workspace_id', $this->ctx['workspace']->id)->where('origin', 'mining')->with('changes')->get();
    $keys = $proposals->map(fn ($p) => $p->evidence['mining']['key'])->sort()->values()->all();
    expect($keys)->toBe(['situation:order_status', 'situation:refund_request', 'situation:warranty_claim', 'summary', 'voice'])
        ->and($proposals->pluck('status')->unique()->all())->toBe(['draft']);

    $refund = $proposals->first(fn ($p) => $p->evidence['mining']['key'] === 'situation:refund_request');
    $playbookChange = $refund->changes->firstWhere('target_type', 'playbook');
    $ops = collect($playbookChange->ops);
    // The rule with real evidence is kept (invented id 999999 removed); the rule nobody demonstrated is dropped.
    expect($ops->where('op', 'add_rule')->pluck('then')->all())->toBe(['Explain the refund window and ask for the order number.'])
        ->and($refund->evidence['rules'][0]['conversation_ids'])->not->toContain(999999)
        ->and(array_diff($refund->evidence['conversation_ids'], $all))->toBe([])
        ->and($run->counters['dropped']['rule_no_evidence'])->toBeGreaterThanOrEqual(1);

    // Suggested actions are advice only, never enabled.
    expect(collect($refund->suggestions)->pluck('op.action')->all())->toContain('refund')
        ->and($ops->pluck('op'))->not->toContain('set_allowed_actions');

    // Test cases are frozen real conversations, created through ops on approval.
    $testOps = $refund->changes->where('target_type', 'test_case');
    expect($testOps)->not->toBeEmpty();
    $frozen = FrozenCase::find($testOps->first()->ops[0]['frozen_case_id']);
    expect($frozen->source)->toBe('mining')
        ->and($frozen->human_reply)->not->toBeNull()
        ->and($testOps->first()->ops[0]['expectation']['expected_actions'])->toContain('refund');

    // A new situation proposes a playbook in draft (no actions), with its scoped knowledge.
    $warranty = $proposals->first(fn ($p) => $p->evidence['mining']['key'] === 'situation:warranty_claim');
    expect($warranty->changes->first()->ops[0]['op'])->toBe('create_playbook');

    // The refund window disagreement is in the summary, with the most recent version first.
    $summary = $proposals->first(fn ($p) => $p->evidence['mining']['key'] === 'summary');
    expect($summary->evidence['inconsistencies'][0]['topic'])->toBe('refund_window')
        ->and($summary->evidence['inconsistencies'][0]['most_recent'])->toBe('Refunds within 30 days.')
        ->and($summary->changes->first()->ops[0]['body'])->toBe('Refunds within 30 days of delivery.')
        // The point is linked to the change that answers it, so the review screen can show them together.
        ->and($summary->evidence['inconsistencies'][0]['knowledge'])->toEqual([
            'title' => $summary->changes->first()->ops[0]['title'],
            'body' => 'Refunds within 30 days of delivery.',
        ])
        // ...and not twice: the situation proposal leaves the disputed item out.
        ->and($refund->changes->where('target_type', 'knowledge')->map(fn ($c) => $c->ops[0]['title'])->all())->not->toContain('Refund window');

    // Voice: field-level ops on the live voice, examples scrubbed.
    $voice = $proposals->first(fn ($p) => $p->evidence['mining']['key'] === 'voice');
    expect(collect($voice->changes->first()->ops)->pluck('op')->all())->toContain('add_do', 'add_dont', 'add_greeting', 'add_sign_off', 'set_field')
        ->and($voice->evidence['quotes'])->toBe(['No worries at all']);

    // Usage recorded per stage and in total.
    expect($run->usage)->toHaveKeys(['extract', 'cluster', 'synthesise', 'voice'])
        ->and($run->usage['extract']['calls'])->toBe(10);

    // Nothing went live, nothing was sent.
    expect(AgentRun::count())->toBe(0);
    Notification::assertNothingSent();
    Mail::assertNothingSent();
    Http::assertNothingSent();
});

test('customer text never reaches a system prompt; it is scrubbed and sent as untrusted data', function () {
    mineTFakeAll();
    mineTConversation($this->ctx, 'ZEBRA-SENTINEL-7781 please refund me, call 0412 345 678', 'No worries, refunds are available within 30 days of delivery. Cheers, Craig', ['customer_name' => 'Oberon Quill', 'customer_email' => 'oberon@example.com']);
    mineTConversation($this->ctx, 'ZEBRA-SENTINEL-7781 I need a refund', 'Sure, refunds within 30 days of delivery. Thanks, Mel');
    mineTConversation($this->ctx, 'ZEBRA-SENTINEL-7781 refund?', 'Yes, refunds within 30 days of delivery.');
    mineTConversation($this->ctx, 'My dial is broken, warranty?', 'Send a photo please.');

    $run = mineTRun(mineTStart($this->ctx));
    expect($run->status)->toBe(MiningRun::STATUS_COMPLETED);

    $clean = fn (AgentPrompt $p) => ! str_contains($p->agent->instructions(), 'ZEBRA-SENTINEL') && ! str_contains($p->agent->instructions(), 'Oberon');

    ExtractConversationAgent::assertPrompted(fn (AgentPrompt $p) => $clean($p)
        && str_contains($p->prompt, 'ZEBRA-SENTINEL-7781')
        && preg_match('/<<<UNTRUSTED_[0-9a-f]+ kind=conversation>>>/', $p->prompt) === 1
        && str_contains($p->prompt, '[phone]')
        && ! str_contains($p->prompt, '0412 345 678')
        && ! str_contains($p->prompt, 'oberon@example.com'));
    ExtractConversationAgent::assertNotPrompted(fn (AgentPrompt $p) => ! $clean($p));
    SynthesiseSituationAgent::assertPrompted(fn (AgentPrompt $p) => $clean($p) && str_contains($p->prompt, 'kind=history'));
    SynthesiseSituationAgent::assertNotPrompted(fn (AgentPrompt $p) => ! $clean($p));
    VoiceProfileAgent::assertPrompted(fn (AgentPrompt $p) => $clean($p) && str_contains($p->prompt, 'kind=replies'));
    VoiceProfileAgent::assertNotPrompted(fn (AgentPrompt $p) => ! $clean($p));
    MergeSituationsAgent::assertNotPrompted(fn (AgentPrompt $p) => ! $clean($p));
});

test('identifiers are dropped from every stored artifact and the drops are counted', function () {
    mineTFakeExtraction();
    mineTFakeMerge();
    mineTFakeVoice(overrides: [
        'example_phrases' => [['text' => 'Hi Oberon, no worries', 'staff' => 'Craig'], ['text' => 'Happy gaming!', 'staff' => 'Craig']],
        'do_phrases' => [['op' => 'add', 'id' => null, 'text' => 'Email oberon@example.com back']],
    ]);
    SynthesiseSituationAgent::fake(function (string $prompt) {
        $id = mineTSampleIds($prompt)[0];

        return mineTResponse(mineTSynthesis($prompt, [
            'rules' => [
                ['op' => 'add', 'rule_id' => null, 'if' => 'always', 'then' => 'Tell Oberon his refund is coming.', 'evidence' => [$id], 'rationale' => 'x'],
                ['op' => 'add', 'rule_id' => null, 'if' => 'always', 'then' => 'Quote the tracking AB12345678 code.', 'evidence' => [$id], 'rationale' => 'x'],
                ['op' => 'add', 'rule_id' => null, 'if' => 'always', 'then' => 'Email jane@example.com for refunds over $100.', 'evidence' => [$id], 'rationale' => 'x'],
                ['op' => 'add', 'rule_id' => null, 'if' => 'the customer wants a refund', 'then' => 'Offer a refund within 30 days.', 'evidence' => [$id], 'rationale' => 'x'],
            ],
            'knowledge' => [
                ['kind' => 'fact', 'topic' => 'office', 'title' => 'Office', 'body' => 'Returns go to 12 Smith Street, Newtown, call 0412 345 678.', 'scope' => ['global' => true, 'playbooks' => [], 'products' => [], 'countries' => []], 'evidence' => [$id]],
            ],
        ]), 0, 0, 'claude-opus-5-5');
    });
    foreach (range(1, 3) as $i) {
        mineTConversation($this->ctx, "I want a refund for order SD1234{$i}", 'Refunds within 30 days of delivery.', ['customer_name' => 'Oberon Quill', 'customer_email' => "oberon{$i}@example.com"]);
    }

    $run = mineTRun(mineTStart($this->ctx));
    expect($run->status)->toBe(MiningRun::STATUS_COMPLETED);

    $stored = json_encode([
        Proposal::with('changes')->get()->toArray(),
        MiningSituation::pluck('synthesis')->all(),
        MiningExtraction::pluck('data')->all(),
        $run->results,
    ]);
    expect($stored)->not->toContain('Oberon')
        ->and($stored)->not->toContain('@example.com')
        ->and($stored)->not->toContain('0412 345 678')
        ->and($stored)->not->toContain('AB12345678')
        ->and($stored)->not->toContain('SD1234')
        ->and($stored)->not->toContain('Smith Street')
        ->and($stored)->toContain('Offer a refund within 30 days.')
        ->and($stored)->toContain('Happy gaming!');

    $dropped = $run->counters['dropped'];
    expect($dropped['pii_customer_name'] ?? 0)->toBeGreaterThanOrEqual(1)
        ->and(($dropped['pii_tracking_number'] ?? 0))->toBeGreaterThanOrEqual(1);
});

// ── Macros, idempotency, re-runs ───────────────────────────────────────────

test('near-identical canned replies are counted, not re-extracted', function () {
    mineTFakeAll();
    $macro = "Thanks for reaching out! Orders ship from our warehouse within 2 business days and usually arrive in 10 to 15 business days. You can track it with the link in your shipping email.\n\nCheers,\nCraig";
    mineTConversation($this->ctx, 'Where is my order?', "Hi Ann,\n\n".$macro, ['days_ago' => 3]);
    mineTConversation($this->ctx, 'When will it arrive?', "Hi Bo,\n\n".str_replace('2 business', '3 business', $macro), ['days_ago' => 4]);
    mineTConversation($this->ctx, 'Shipping time?', "Hey Cy!\n".$macro, ['days_ago' => 5]);

    $run = mineTRun(mineTStart($this->ctx));

    expect($run->counter('extract_new'))->toBe(1)
        ->and($run->counter('extract_macro_copies'))->toBe(2)
        ->and(MiningExtraction::where('status', 'macro')->count())->toBe(2)
        ->and(MiningSituation::where('run_id', $run->id)->where('key', 'order_status')->value('conversation_count'))->toBe(3);
    ExtractConversationAgent::assertPrompted(fn () => true);
    expect($run->usage['extract']['calls'])->toBe(1);
});

test('extractions are keyed by content hash and extractor version; a re-run costs nothing and duplicates nothing', function () {
    mineTFakeAll();
    $history = mineTHistory($this->ctx);

    $first = mineTRun(mineTStart($this->ctx));
    $proposalCount = Proposal::count();
    $extractions = MiningExtraction::count();

    // Re-run with the same data: every extraction reused, every model result cached.
    $second = mineTRun(mineTStart($this->ctx));
    expect($second->status)->toBe(MiningRun::STATUS_COMPLETED)
        ->and($second->counter('extract_reused'))->toBe(10)
        ->and($second->counter('extract_new'))->toBe(0)
        ->and($second->cost_micro_usd)->toBe(0)
        ->and(collect($second->usage)->sum('calls'))->toBe(0)
        ->and(MiningExtraction::count())->toBe($extractions)
        ->and(Proposal::count())->toBe($proposalCount)
        ->and(collect($second->results['proposals'])->pluck('action')->unique()->values()->all())->toBe(['unchanged']);

    // A changed conversation is extracted again; nothing else is.
    Thread::factory()->create(['conversation_id' => $history['status1']->id, 'customer_id' => $history['status1']->customer_id, 'type' => 'message', 'body_plain' => 'Got it, thanks!', 'sent_at' => now()->subDays(4)]);
    $third = mineTRun(mineTStart($this->ctx));
    expect($third->counter('extract_new'))->toBe(1)->and($third->counter('extract_reused'))->toBe(9);

    // A new extractor version re-extracts everything.
    config(['agent_mining.extractor_version' => 'extract-v2']);
    $fourth = mineTRun(mineTStart($this->ctx));
    expect($fourth->counter('extract_new'))->toBe(10)
        ->and(MiningExtraction::where('extractor_version', 'extract-v2')->count())->toBe(10);
});

test('a re-run updates unreviewed proposals and never touches reviewed ones', function () {
    mineTFakeAll();
    mineTHistory($this->ctx);
    mineTRun(mineTStart($this->ctx));

    $byKey = fn () => Proposal::where('origin', 'mining')->orderBy('id')->get()->keyBy(fn ($p) => $p->evidence['mining']['key']);
    $rejected = $byKey()['situation:order_status'];
    app(ProposalService::class)->reject($rejected, adminUser($this->ctx['workspace'])->id, 'Not now');
    $rejectedBefore = $rejected->fresh()->toArray();
    $openBefore = $byKey()['situation:refund_request'];

    // Different synthesis output for the same input data: bump the version so the cache misses.
    config(['agent_mining.synthesis_version' => 'synth-v2']);
    mineTFakeSynthesis(overrides: ['rationale' => 'Second opinion.', 'steps' => []]);
    $run = mineTRun(mineTStart($this->ctx));

    $after = $byKey();
    expect($run->results['proposals']['situation:refund_request']['action'])->toBe('updated')
        ->and($after['situation:refund_request']->id)->toBe($openBefore->id)
        ->and($after['situation:refund_request']->rationale)->toContain('Second opinion.')
        // The rejected proposal is untouched; its replacement is a new proposal.
        ->and($rejected->fresh()->toArray())->toBe($rejectedBefore)
        ->and($run->results['proposals']['situation:order_status']['action'])->toBe('created')
        ->and($after['situation:order_status']->id)->not->toBe($rejected->id)
        ->and(Proposal::where('origin', 'mining')->whereIn('status', Proposal::OPEN_STATUSES)->get()->groupBy(fn ($p) => $p->evidence['mining']['key'])->every(fn ($g) => $g->count() === 1))->toBeTrue();
});

// ── Clustering ─────────────────────────────────────────────────────────────

test('clustering without embeddings maps labels onto playbooks, merges near-duplicates and reports coverage', function () {
    mineTFakeExtraction();
    mineTFakeSynthesis();
    mineTFakeVoice();
    mineTFakeMerge(fn ($label) => match ($label) {
        'warranty_issue', 'warranty_claim' => 'warranty_claim',
        'bulk_purchase' => 'wholesale',
        default => $label,
    });
    foreach (range(1, 3) as $i) {
        mineTConversation($this->ctx, 'My dial stopped, warranty?', 'Send a photo.');
        mineTConversation($this->ctx, 'It is broken already', 'Send a video.');
        mineTConversation($this->ctx, 'Can I buy in bulk for my shop?', 'Let me check with the team.');
    }
    mineTConversation($this->ctx, 'Where is my parcel?', 'It shipped.');

    $run = mineTRun(mineTStart($this->ctx));
    $situations = MiningSituation::where('run_id', $run->id)->get()->keyBy('key');

    expect($run->results['clustering']['method'])->toBe('model')
        ->and($situations['warranty_claim']->conversation_count)->toBe(6)
        ->and($situations['wholesale']->playbook_id)->not->toBeNull()              // mapped onto the existing playbook
        ->and($situations['wholesale']->labels)->toBe(['bulk_purchase' => 3])
        ->and($situations['order_status']->status)->toBe(MiningSituation::STATUS_TOO_SMALL); // 1 < min_conversations
    MergeSituationsAgent::assertPrompted(fn (AgentPrompt $p) => str_contains($p->prompt, 'bulk_purchase (3 conversations)')
        && ! str_contains($p->agent->instructions(), 'bulk_purchase'));

    $show = app(MiningPresenter::class)->detail($run);
    expect(collect($show['coverage'])->firstWhere('key', 'warranty_claim'))
        ->toMatchArray(['conversation_count' => 6, 'is_new' => true, 'status' => 'synthesised']);
});

test('clustering with embeddings configured merges by similarity and makes no merge call', function () {
    mineTFakeExtraction();
    mineTFakeSynthesis();
    mineTFakeVoice();
    mineTFakeMerge();
    app()->instance(EmbeddingService::class, new class(app(AiSettingsService::class)) extends EmbeddingService
    {
        public function isConfigured(int $workspaceId): bool
        {
            return true;
        }

        public function embed(int $workspaceId, array $inputs): array
        {
            return array_map(function (string $text) {
                $axis = match (true) {
                    str_contains($text, 'warranty') => 0,
                    str_contains($text, 'bulk'), str_contains($text, 'wholesale') => 1,
                    default => 2,
                };
                $v = [0.0, 0.0, 0.0];
                $v[$axis] = 1.0;

                return $v;
            }, $inputs);
        }
    });
    foreach (range(1, 3) as $i) {
        mineTConversation($this->ctx, 'My dial stopped, warranty?', 'Send a photo.');
        mineTConversation($this->ctx, 'It is broken already', 'Send a video.');
        mineTConversation($this->ctx, 'Can I buy in bulk for my shop?', 'Let me check with the team.');
    }

    $run = mineTRun(mineTStart($this->ctx));
    $situations = MiningSituation::where('run_id', $run->id)->get()->keyBy('key');
    $warranty = $situations->first(fn ($s) => isset($s->labels['warranty_claim']));

    expect($run->results['clustering']['method'])->toBe('embeddings')
        ->and($situations['wholesale']->labels)->toBe(['bulk_purchase' => 3])   // nearest playbook
        ->and($warranty->labels)->toHaveKeys(['warranty_claim', 'warranty_issue']) // near-duplicate labels merged
        ->and($warranty->conversation_count)->toBe(6);
    MergeSituationsAgent::assertNeverPrompted();
});

test('clustering still works when the model merge fails: loose label groups stand', function () {
    mineTFakeExtraction();
    mineTFakeSynthesis();
    mineTFakeVoice();
    MergeSituationsAgent::fake(fn () => throw new RuntimeException('overloaded'));
    foreach (range(1, 3) as $i) {
        mineTConversation($this->ctx, 'My dial stopped, warranty?', 'Send a photo.');
    }

    $run = mineTRun(mineTStart($this->ctx));

    expect($run->status)->toBe(MiningRun::STATUS_COMPLETED)
        ->and($run->results['clustering']['method'])->toBe('loose')
        ->and(MiningSituation::where('run_id', $run->id)->where('key', 'warranty_claim')->value('conversation_count'))->toBe(3);
});

// ── Failures, resume, budget ───────────────────────────────────────────────

test('a failing extraction backs off and the run resumes from its checkpoint', function () {
    mineTFakeMerge();
    mineTFakeSynthesis();
    mineTFakeVoice();
    $calls = 0;
    ExtractConversationAgent::fake(function (string $prompt) use (&$calls) {
        if (++$calls === 2) {
            throw new RuntimeException('529 overloaded');
        }

        return mineTResponse(mineTExtraction($prompt));
    });
    mineTHistory($this->ctx);

    $run = mineTStart($this->ctx);
    $delay = app(MiningRunner::class)->runStep($run->id, $run->job_token);
    $run->refresh();

    expect($delay)->toBe(30)
        ->and($run->stage)->toBe(MiningRun::STAGE_EXTRACT)
        ->and($run->last_error)->toContain('529 overloaded')
        ->and(MiningConversation::where('run_id', $run->id)->where('status', 'done')->count())->toBe(1)
        ->and(MiningConversation::where('run_id', $run->id)->where('attempts', 1)->count())->toBe(1);

    $run = mineTRun($run);
    expect($run->status)->toBe(MiningRun::STATUS_COMPLETED)
        ->and($run->counter('extract_new'))->toBe(10)
        ->and($calls)->toBe(11);
});

test('a conversation that keeps failing is skipped after max attempts', function () {
    mineTFakeMerge();
    mineTFakeSynthesis();
    mineTFakeVoice();
    ExtractConversationAgent::fake(function (string $prompt) {
        if (str_contains($prompt, 'POISON')) {
            throw new RuntimeException('bad output');
        }

        return mineTResponse(mineTExtraction($prompt));
    });
    mineTConversation($this->ctx, 'POISON message', 'ok');
    mineTConversation($this->ctx, 'Where is it?', 'Shipped.');

    $run = mineTRun(mineTStart($this->ctx));

    expect($run->status)->toBe(MiningRun::STATUS_COMPLETED)
        ->and($run->counter('extract_failed'))->toBe(1)
        ->and(MiningConversation::where('run_id', $run->id)->where('status', 'failed')->value('attempts'))->toBe(3);
});

test('synthesis and voice failures resume from their stage; a failed run resumes from its checkpoint', function () {
    config(['agent_mining.queue.max_consecutive_errors' => 1]);
    mineTFakeExtraction();
    mineTFakeMerge();
    $synth = 0;
    SynthesiseSituationAgent::fake(function (string $prompt) use (&$synth) {
        if (++$synth === 1) {
            throw new RuntimeException('synthesis timeout');
        }

        return mineTResponse(mineTSynthesis($prompt), 0, 0, 'claude-opus-5-5');
    });
    $voice = 0;
    VoiceProfileAgent::fake(function () use (&$voice) {
        if (++$voice === 1) {
            throw new RuntimeException('voice timeout');
        }

        return mineTResponse(mineTVoice(), 0, 0, 'claude-opus-5-5');
    });
    mineTHistory($this->ctx);

    $run = mineTRun(mineTStart($this->ctx));
    expect($run->status)->toBe(MiningRun::STATUS_FAILED)
        ->and($run->stage)->toBe(MiningRun::STAGE_SYNTHESISE)
        ->and($run->last_error)->toContain('synthesis timeout');
    $extractCalls = $run->usage['extract']['calls'];

    expect(app(MiningService::class)->resume($run, dispatch: false))->toBeTrue();
    $run = mineTRun($run);
    expect($run->status)->toBe(MiningRun::STATUS_FAILED)
        ->and($run->stage)->toBe(MiningRun::STAGE_VOICE)
        ->and($run->last_error)->toContain('voice timeout');

    app(MiningService::class)->resume($run, dispatch: false);
    $run = mineTRun($run);
    expect($run->status)->toBe(MiningRun::STATUS_COMPLETED)
        ->and($run->usage['extract']['calls'])->toBe($extractCalls) // nothing extracted twice
        ->and(MiningSituation::where('run_id', $run->id)->where('key', 'order_status')->value('attempts'))->toBe(1)
        // order_status + refund_request (the warranty labels stay apart and too small here), voice, summary.
        ->and(collect($run->results['proposals'])->pluck('action')->all())->toBe(['created', 'created', 'created', 'created'])
        ->and(Proposal::where('origin', 'mining')->count())->toBe(4);
});

test('a failure while emitting backs off and resumes without duplicating proposals', function () {
    mineTFakeAll();
    mineTHistory($this->ctx);
    $flaky = new class(app(OpsApplier::class), app(ConfigFingerprint::class), app(ProposalApplier::class)) extends ProposalService
    {
        public int $calls = 0;

        public function createProposal(int $workspaceId, string $origin, string $title, ?string $rationale, array $changes, array $evidence = [], ?int $createdBy = null, array $attributes = []): Proposal
        {
            if (++$this->calls === 2) {
                throw new RuntimeException('database hiccup');
            }

            return parent::createProposal($workspaceId, $origin, $title, $rationale, $changes, $evidence, $createdBy, $attributes);
        }
    };
    app()->instance(ProposalService::class, $flaky);

    $run = mineTRun(mineTStart($this->ctx));

    expect($run->status)->toBe(MiningRun::STATUS_COMPLETED)
        ->and($run->error_count)->toBe(1)
        ->and($run->last_error)->toContain('[emit]')
        ->and($run->results['proposals'])->toHaveCount(5)
        ->and(Proposal::where('origin', 'mining')->count())->toBe(5);
});

test('the budget cap pauses the run with a clear message, and resume with a higher cap carries on', function () {
    // Each extraction: 100k in / 10k out on Haiku = $0.01 + $0.005 = $0.015.
    mineTFakeExtraction(100_000, 10_000);
    mineTFakeMerge();
    mineTFakeSynthesis();
    mineTFakeVoice();
    mineTHistory($this->ctx);

    $run = mineTRun(mineTStart($this->ctx, ['budget_usd' => 0.05]));

    expect($run->status)->toBe(MiningRun::STATUS_PAUSED)
        ->and($run->pause_reason)->toBe(MiningRun::PAUSE_BUDGET)
        ->and($run->message)->toContain('Budget cap of $0.05 reached during extract')
        ->and($run->cost_micro_usd)->toBeLessThanOrEqual(50_000)
        ->and($run->usage['extract']['cost_micro_usd'])->toBe($run->cost_micro_usd)
        ->and($run->counter('extract_new'))->toBe(3);

    // Resuming without raising the cap pauses again straight away.
    app(MiningService::class)->resume($run, dispatch: false);
    $run = mineTRun($run);
    expect($run->status)->toBe(MiningRun::STATUS_PAUSED)->and($run->counter('extract_new'))->toBe(3);

    app(MiningService::class)->resume($run, 5.0, dispatch: false);
    $run = mineTRun($run);
    expect($run->status)->toBe(MiningRun::STATUS_COMPLETED)
        ->and($run->budget_micro_usd)->toBe(5_000_000)
        ->and($run->options['budget_usd'])->toEqual(5.0)
        ->and($run->cost_micro_usd)->toBe(150_000);
});

test('the dry-run estimate counts conversations, calls, tokens and cost by stage', function () {
    mineTConversation($this->ctx, str_repeat('a', 400), str_repeat('b', 800));
    mineTConversation($this->ctx, str_repeat('c', 1200), str_repeat('d', 400));
    $reused = mineTConversation($this->ctx, 'Where?', 'There.');
    MiningExtraction::create([
        'workspace_id' => $this->ctx['workspace']->id, 'conversation_id' => $reused->id, 'content_hash' => 'x',
        'extractor_version' => config('agent_mining.extractor_version'), 'status' => 'ok', 'data' => [],
    ]);

    $e = app(MiningService::class)->estimate($this->ctx['workspace']->id, ['months' => 12, 'budget_usd' => 10]);

    $system = $e['assumptions']['extract_system_tokens'];
    // Two to extract; transcripts of the selection average (1200 + 1600 + 12) / 3 chars, 4 chars a token.
    $extractIn = (int) round(2 * ($system + (2812 / 3) / 4 + 150));
    expect($e['selected'])->toBe(3)
        ->and($e['already_extracted'])->toBe(1)
        ->and($e['to_extract'])->toBe(2)
        ->and($e['stages']['extract'])->toMatchArray(['calls' => 2, 'tokens_in' => $extractIn, 'tokens_out' => 2400, 'model' => 'claude-haiku-5-5'])
        ->and($e['stages']['extract']['cost_micro_usd'])->toBe((int) round($extractIn * 0.10 + 2400 * 0.50))
        ->and($e['stages']['cluster']['calls'])->toBe(1)
        ->and($e['stages']['synthesise']['calls'])->toBe(1)
        ->and($e['stages']['synthesise']['model'])->toBe('claude-opus-5-5')
        ->and($e['stages']['voice']['calls'])->toBe(1)
        ->and($e['total']['cost_micro_usd'])->toBe(array_sum(array_column($e['stages'], 'cost_micro_usd')))
        ->and($e['within_budget'])->toBeTrue();

    ExtractConversationAgent::assertNeverPrompted();
    expect(MiningRun::count())->toBe(0);
});

test('a superseded job chain and a paused or cancelled run do no work', function () {
    mineTFakeAll();
    mineTHistory($this->ctx);
    $run = mineTStart($this->ctx);
    $oldToken = $run->job_token;

    app(MiningService::class)->pause($run);
    expect(app(MiningRunner::class)->runStep($run->id, $oldToken))->toBeNull();
    app(MiningService::class)->resume($run, dispatch: false);
    expect(app(MiningRunner::class)->runStep($run->id, $oldToken))->toBeNull(); // superseded
    app(MiningService::class)->cancel($run->fresh());
    expect(app(MiningRunner::class)->runStep($run->id, $run->fresh()->job_token))->toBeNull();
    ExtractConversationAgent::assertNeverPrompted();

    // The watchdog re-queues a running run that stopped checkpointing.
    $stalled = mineTStart(mineTWorkspace());
    $stalled->forceFill(['status' => 'running', 'heartbeat_at' => now()->subHour()])->save();
    expect(app(MiningService::class)->recoverStalled())->toBe(1);
    Queue::assertPushed(RunMiningStepJob::class, fn ($job) => $job->runId === $stalled->id && $job->token === $stalled->fresh()->job_token);
});

test('agent:mine prints the estimate and runs in-process', function () {
    mineTFakeAll();
    mineTHistory($this->ctx);

    $this->artisan('agent:mine', ['workspace' => $this->ctx['workspace']->id, '--estimate' => true, '--months' => 12])
        ->expectsOutputToContain('Estimate: 10 eligible, 10 selected')
        ->assertSuccessful();
    expect(MiningRun::count())->toBe(0);

    $this->artisan('agent:mine', ['workspace' => $this->ctx['workspace']->id, '--sync' => true, '--months' => 12, '--budget' => 5])
        ->expectsOutputToContain('stage: done')
        ->assertSuccessful();
    expect(MiningRun::sole()->status)->toBe('completed');
});
