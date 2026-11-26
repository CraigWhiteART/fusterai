<?php

use App\Domains\Mailbox\Sync\Backfill\ThreadFilter;
use App\Domains\Mailbox\Sync\Backfill\ThreadGrouper;

const TF_NOW = 1_780_000_000; // fixed "now"
const TF_DAY = 86_400;

/** A ledger row as ThreadGrouper builds it, $daysAgo before TF_NOW. */
function tfRow(int $id, string $direction, int $daysAgo, array $extra = []): array
{
    return array_merge([
        'id' => $id,
        'gm_thrid' => '9000',
        'message_id' => "m{$id}@x",
        'in_reply_to' => null,
        'references' => [],
        'subject' => 'Where is my order?',
        'customer_email' => $direction === 'internal' ? null : 'emma@example.net',
        'direction' => $direction,
        'classification' => 'support',
        'labels' => [],
        'at' => TF_NOW - $daysAgo * TF_DAY,
        'live' => false,
        'ingested' => false,
    ], $extra);
}

function tfFilter(bool $includeUnanswered = false): ThreadFilter
{
    // Window: the last 540 days; active window: 14 days.
    return new ThreadFilter(TF_NOW - 540 * TF_DAY, TF_NOW, $includeUnanswered, 14);
}

dataset('threads', [
    'customer and us: kept' => [
        fn () => [tfRow(1, 'inbound', 100), tfRow(2, 'outbound', 99)],
        false, 'kept', 'two_way', false,
    ],
    'multi-turn, customer had the last word long ago: kept, closed' => [
        fn () => [tfRow(1, 'inbound', 100), tfRow(2, 'outbound', 99), tfRow(3, 'inbound', 98)],
        false, 'kept', 'two_way', false,
    ],
    'customer wrote last within the active window: kept, active' => [
        fn () => [tfRow(1, 'inbound', 20), tfRow(2, 'outbound', 19), tfRow(3, 'inbound', 2)],
        false, 'kept', 'two_way', true,
    ],
    'we wrote last recently: kept, not active' => [
        fn () => [tfRow(1, 'inbound', 3), tfRow(2, 'outbound', 2)],
        false, 'kept', 'two_way', false,
    ],
    'unanswered, old: dropped' => [
        fn () => [tfRow(1, 'inbound', 100)],
        false, 'dropped', 'unanswered', false,
    ],
    'unanswered, old, option on: kept' => [
        fn () => [tfRow(1, 'inbound', 100)],
        true, 'kept', 'unanswered', false,
    ],
    'unanswered but recent: kept open even with the option off' => [
        fn () => [tfRow(1, 'inbound', 5)],
        false, 'kept', 'unanswered_recent', true,
    ],
    'only our outbound: dropped' => [
        fn () => [tfRow(1, 'outbound', 100)],
        false, 'dropped', 'no_customer_reply', false,
    ],
    'newsletter: dropped as bulk' => [
        fn () => [tfRow(1, 'inbound', 100, ['classification' => 'bulk']), tfRow(2, 'inbound', 90, ['classification' => 'bulk'])],
        false, 'dropped', 'bulk', false,
    ],
    'auto-reply to our outreach: dropped as auto_reply' => [
        fn () => [tfRow(1, 'outbound', 100), tfRow(2, 'inbound', 100, ['classification' => 'auto'])],
        false, 'dropped', 'auto_reply', false,
    ],
    'bounce: dropped as notification' => [
        fn () => [tfRow(1, 'inbound', 100, ['classification' => 'notification', 'customer_email' => 'mailer-daemon@x'])],
        false, 'dropped', 'notification', false,
    ],
    'our own auto-reply does not make it two-way' => [
        fn () => [tfRow(1, 'inbound', 100), tfRow(2, 'outbound', 100, ['classification' => 'auto'])],
        false, 'dropped', 'unanswered', false,
    ],
    'internal only: dropped' => [
        fn () => [tfRow(1, 'internal', 100), tfRow(2, 'internal', 99)],
        false, 'dropped', 'internal', false,
    ],
    'drafts only: dropped' => [
        fn () => [tfRow(1, 'outbound', 100, ['labels' => ['\\Draft']])],
        false, 'dropped', 'draft_or_chat', false,
    ],
    'a draft reply does not count as answered' => [
        fn () => [tfRow(1, 'inbound', 100), tfRow(2, 'outbound', 99, ['labels' => ['\\Draft']])],
        false, 'dropped', 'unanswered', false,
    ],
    'chat: dropped' => [
        fn () => [tfRow(1, 'inbound', 100, ['labels' => ['\\Chat']])],
        false, 'dropped', 'draft_or_chat', false,
    ],
    'older than the window: dropped' => [
        fn () => [tfRow(1, 'inbound', 700), tfRow(2, 'outbound', 699)],
        false, 'dropped', 'out_of_window', false,
    ],
    'started before the window, answered inside it: kept' => [
        fn () => [tfRow(1, 'inbound', 600), tfRow(2, 'outbound', 500)],
        false, 'kept', 'two_way', false,
    ],
    'every row already ingested: skipped' => [
        fn () => [tfRow(1, 'inbound', 100, ['ingested' => true]), tfRow(2, 'outbound', 99, ['ingested' => true])],
        false, 'skipped', 'already_imported', false,
    ],
    'every row owned by live sync: skipped' => [
        fn () => [tfRow(1, 'inbound', 1, ['live' => true]), tfRow(2, 'outbound', 0, ['live' => true])],
        false, 'skipped', 'live', false,
    ],
]);

test('thread filter rules', function (Closure $rows, bool $includeUnanswered, string $decision, string $reason, bool $active) {
    $result = tfFilter($includeUnanswered)->decide($rows());

    expect($result['decision'])->toBe($decision)
        ->and($result['reason'])->toBe($reason)
        ->and($result['active'])->toBe($active);
})->with('threads');

test('only rows the live sync does not own, not yet ingested and not drafts are importable', function () {
    $result = tfFilter()->decide([
        tfRow(1, 'inbound', 30),
        tfRow(2, 'outbound', 29, ['labels' => ['\\Draft']]),
        tfRow(3, 'outbound', 28, ['ingested' => true]),
        tfRow(4, 'inbound', 1, ['live' => true]),
    ]);

    expect($result['decision'])->toBe('kept')
        ->and($result['importable'])->toBe([1])
        ->and($result['inbound'])->toBe(2)
        ->and($result['outbound'])->toBe(1);
});

test('without Gmail thread ids, rows are grouped by the reply graph and by subject per customer', function () {
    $rows = [
        tfRow(1, 'inbound', 10, ['gm_thrid' => null, 'message_id' => 'a@x', 'subject' => 'Broken shade']),
        tfRow(2, 'outbound', 9, ['gm_thrid' => null, 'message_id' => 'b@x', 'in_reply_to' => 'a@x', 'subject' => 'Re: Broken shade']),
        // References the reply only.
        tfRow(3, 'inbound', 8, ['gm_thrid' => null, 'message_id' => 'c@x', 'references' => ['b@x'], 'subject' => 'Re: Broken shade']),
        // No headers, same subject, same customer: merged.
        tfRow(4, 'inbound', 7, ['gm_thrid' => null, 'message_id' => 'd@x', 'subject' => 'RE: broken shade']),
        // Same subject, another customer: separate.
        tfRow(5, 'inbound', 7, ['gm_thrid' => null, 'message_id' => 'e@x', 'subject' => 'Broken shade', 'customer_email' => 'liam@example.org']),
        // Unrelated.
        tfRow(6, 'inbound', 6, ['gm_thrid' => null, 'message_id' => 'f@x', 'subject' => 'Invoice please']),
    ];

    $groups = ThreadGrouper::groupByReferences($rows);

    expect(array_map(fn ($g) => array_column($g, 'id'), $groups))->toBe([
        'r:1' => [1, 2, 3, 4],
        'r:5' => [5],
        'r:6' => [6],
    ]);
});
