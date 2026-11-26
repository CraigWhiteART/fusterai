<?php

use App\Domains\Agent\Pipeline\DeterministicValidator;
use App\Domains\Agent\Pipeline\EvidenceLedger;
use App\Domains\Agent\Pipeline\VoiceSpec;

function agentTLedger(): EvidenceLedger
{
    return EvidenceLedger::fromArray(['facts' => [
        ['source' => 'shopify', 'key' => 'shopify.order_number', 'label' => 'Order number', 'value' => 'SD8421'],
        ['source' => 'shopify', 'key' => 'shopify.order_date', 'label' => 'Order date', 'value' => '2026-11-20'],
        ['source' => 'shopify', 'key' => 'shopify.tracking_number', 'label' => 'Tracking number', 'value' => '4PX3001234567CN'],
        ['source' => 'shopify', 'key' => 'shopify.tracking_url', 'label' => 'Tracking URL', 'value' => 'https://track.4px.com/#/result/0/4PX3001234567CN'],
    ]]);
}

/** @return list<string> */
function agentTCheck(string $draft, array $claims = [], array $knowledge = [], ?VoiceSpec $voice = null): array
{
    $issues = app(DeterministicValidator::class)->check(
        $draft,
        $claims,
        agentTLedger(),
        $knowledge,
        $voice ?? new VoiceSpec(null, 'friendly', dontPhrases: [['id' => 'd1', 'text' => 'I completely understand your frustration']], maxWords: 60),
        ['4px.com', 'soul-dial.com'],
    );

    return array_map(fn ($i) => $i['check'].':'.$i['value'], $issues);
}

test('grounded drafts pass', function () {
    $draft = 'Hi Alex, order SD8421 from 20 November shipped with tracking 4PX3001234567CN: https://track.4px.com/#/result/0/4PX3001234567CN';

    expect(agentTCheck($draft))->toBe([]);
});

test('an invented date is caught', function () {
    expect(agentTCheck('Your order SD8421 will arrive on 3 December.'))->toContain('date:3 December')
        ->and(agentTCheck('It ships on Tuesday.'))->toContain('date:tuesday')
        // 2026-11-20 (the order date in evidence) was a Friday, so that weekday is grounded.
        ->and(agentTCheck('You ordered on Friday.'))->toBe([])
        ->and(agentTCheck('It ships tomorrow.'))->toContain('date:tomorrow')
        ->and(agentTCheck('Ordered on November 20, all good.'))->toBe([]);
});

test('an invented amount is caught', function () {
    expect(agentTCheck('We have refunded A$49.95 to your card.'))->toContain('money:A$49.95')
        ->and(agentTCheck('That costs 30 dollars.'))->toContain('money:30 dollars')
        ->and(agentTCheck('Here is 100% refund back to you.'))->toContain('money:100%');
});

test('an amount from cited knowledge passes', function () {
    $knowledge = ['K7' => ['id' => 'K7', 'kind' => 'policy', 'title' => 'Shipping', 'body' => 'Express shipping costs $15.00.', 'scope' => []]];

    expect(agentTCheck('Express shipping is $15.', [['text' => 'express', 'sources' => ['K7']]], $knowledge))->toBe([])
        // The same knowledge, seen but not cited, does not ground the amount.
        ->and(agentTCheck('Express shipping is $15.', [], $knowledge))->toContain('money:$15');
});

test('an invented or off-allowlist link is caught', function () {
    expect(agentTCheck('Track it at https://track.4px.com/#/result/0/OTHER123'))->toContain('link:https://track.4px.com/#/result/0/OTHER123')
        ->and(agentTCheck('See https://evil.example.com/refund'))->toContain('link_domain:https://evil.example.com/refund')
        ->and(agentTCheck('Visit www.scam-site.io now'))->toContain('link_domain:www.scam-site.io');
});

test('an invented discount code is caught', function () {
    expect(agentTCheck('Use code SAVE20 at checkout.'))->toContain('discount_code:SAVE20')
        ->and(agentTCheck('Use promo code: WELCOME10 next time.'))->toContain('discount_code:WELCOME10');
});

test('invented order and tracking numbers are caught', function () {
    expect(agentTCheck('Your order #SD9999 is on the way.'))->toContain('identifier:SD9999')
        ->and(agentTCheck('Tracking LX123456789CN shows movement.'))->toContain('identifier:LX123456789CN');
});

test('voice dont phrases, length and unknown sources are caught', function () {
    expect(agentTCheck('I completely understand your frustration. It is coming.'))->toContain('voice:I completely understand your frustration')
        ->and(agentTCheck(str_repeat('word ', 200)))->toContain('length:200')
        ->and(agentTCheck('Fine.', [['text' => 'x', 'sources' => ['E99']]]))->toContain('unknown_source:E99')
        ->and(agentTCheck('   '))->toBe(['empty:']);
});
