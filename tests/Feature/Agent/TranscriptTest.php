<?php

require_once __DIR__.'/AgentTestHelpers.php';

use App\Domains\Agent\Pipeline\Transcript;
use App\Domains\Agent\Pipeline\TranscriptBuilder;
use App\Domains\Agent\Pipeline\TranscriptTurn;
use App\Domains\Commerce\Services\ShopifyLookupService;
use App\Domains\Conversation\Models\Thread;
use App\Models\User;
use Carbon\CarbonImmutable;

test('the transcript is in true chronological order and includes staff replies written in Gmail', function () {
    $case = agentTCase('First question about SD1000', subject: 'Order help');
    $case['thread']->forceFill(['sent_at' => '2026-03-01 09:00:00', 'created_at' => '2026-03-05 09:00:00'])->save();
    $staff = User::factory()->create(['workspace_id' => $case['workspace']->id, 'name' => 'Jo Staff']);

    // Created out of order (as a Gmail backfill would), ordered by sent_at.
    Thread::factory()->create([
        'conversation_id' => $case['conversation']->id, 'customer_id' => $case['customer']->id, 'user_id' => null,
        'type' => 'message', 'body' => '<p>Follow-up: actually it is order SD2000</p>', 'body_plain' => 'Follow-up: actually it is order SD2000',
        'sent_at' => '2026-03-03 09:00:00', 'created_at' => '2026-03-04 09:00:00',
    ]);
    Thread::factory()->create([
        'conversation_id' => $case['conversation']->id, 'customer_id' => null, 'user_id' => null, 'origin' => 'gmail',
        'type' => 'message', 'body' => '<div>Replied from Gmail: checking now</div>', 'body_plain' => 'Replied from Gmail: checking now',
        'sent_at' => '2026-03-02 09:00:00', 'created_at' => '2026-03-06 09:00:00',
    ]);
    Thread::factory()->create([
        'conversation_id' => $case['conversation']->id, 'customer_id' => null, 'user_id' => $staff->id,
        'type' => 'note', 'body' => 'internal note', 'body_plain' => 'internal note - never shown to the model',
    ]);

    $transcript = app(TranscriptBuilder::class)->fromConversation($case['conversation']);

    expect(array_map(fn (TranscriptTurn $t) => $t->role.':'.$t->text, $transcript->turns))->toBe([
        'customer:First question about SD1000',
        'staff:Replied from Gmail: checking now',
        'customer:Follow-up: actually it is order SD2000',
    ])
        ->and($transcript->turns[1]->origin)->toBe('gmail')
        ->and($transcript->latestCustomerTurn()?->text)->toContain('SD2000');

    $rendered = $transcript->render(6000, 6, 4000)['text'];
    expect($rendered)->toContain('STAFF (sent from Gmail)')
        ->and($rendered)->not->toContain('internal note')
        ->and(strpos($rendered, 'First question'))->toBeLessThan(strpos($rendered, 'Follow-up'));
});

test('the transcript keeps recent turns verbatim, summarises older ones and drops the oldest past the budget', function () {
    $turns = [];
    for ($i = 1; $i <= 30; $i++) {
        $turns[] = new TranscriptTurn($i % 2 ? 'customer' : 'staff', "Message {$i} ".str_repeat('lorem ipsum ', 60), CarbonImmutable::parse('2026-01-01')->addHours($i));
    }
    $out = (new Transcript($turns))->render(maxTokens: 1500, verbatimTurns: 3, turnMaxChars: 4000);

    expect($out['dropped'])->toBeGreaterThan(0)
        ->and($out['summarised'])->toBeGreaterThan(0)
        ->and($out['text'])->toContain('earlier message(s) omitted')
        ->and($out['text'])->toContain('Message 30 lorem')
        ->and($out['text'])->not->toContain('Message 1 lorem');
    // The newest turns are verbatim (not "[summary]").
    expect(substr_count($out['text'], '[summary]'))->toBe($out['summarised']);
});

test('order numbers are taken from every customer message, newest first (not the oldest message only)', function () {
    $case = agentTCase('Hi, question about order SD1000');
    $case['thread']->forceFill(['created_at' => now()->subDay()])->save();
    Thread::factory()->create([
        'conversation_id' => $case['conversation']->id, 'customer_id' => $case['customer']->id, 'user_id' => null,
        'type' => 'message', 'body' => 'Sorry, the right one is SD2000', 'body_plain' => 'Sorry, the right one is SD2000',
    ]);

    $service = app(ShopifyLookupService::class);
    $text = $service->customerText($case['conversation']->fresh());

    expect($text)->toContain('SD2000')
        ->and($text)->toContain('SD1000')
        ->and($service->extractOrderNumbers($text))->toBe(['SD2000', 'SD1000']);
});
