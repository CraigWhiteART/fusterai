<?php

use App\Domains\Agent\Mining\MacroDetector;
use App\Domains\Agent\Mining\Pii\PiiScrubber;

function piiT(): PiiScrubber
{
    return new PiiScrubber(['SD']);
}

dataset('identifiers', [
    'email' => ['Write to jane.doe+orders@gmail.com today', 'jane.doe', '[email]'],
    'AU mobile' => ['Call me on 0412 345 678 please', '0412', '[phone]'],
    'AU mobile +61' => ['Call +61 412 345 678', '412 345', '[phone]'],
    'AU landline' => ['Our office is (02) 9876 5432', '9876', '[phone]'],
    'AU 1300' => ['Ring 1300 123 456', '1300 123', '[phone]'],
    'US dashes' => ['My number is 555-123-4567', '555-123', '[phone]'],
    'US brackets' => ['My number is (415) 555-0123', '555-0123', '[phone]'],
    'US +1' => ['Text +1 415 555 0123', '415 555', '[phone]'],
    'UK mobile' => ['Mobile 07911 123456', '07911', '[phone]'],
    'UK +44' => ['Call +44 20 7946 0958', '7946', '[phone]'],
    'order hash' => ['Order #1234 has not arrived', '#1234', '[order]'],
    'order prefix' => ['Where is SD12345?', 'SD12345', '[order]'],
    'order prefix dash' => ['Ref SD-48211 please', 'SD-48211', '[order]'],
    'order word' => ['my order number 778812 is late', '778812', '[order]'],
    '4PX tracking' => ['Tracking 4PX3001234567890CN shows nothing', '4PX300', '[tracking]'],
    'UPU tracking' => ['Tracking LX123456789CN', 'LX123456789CN', '[tracking]'],
    'UPS tracking' => ['UPS 1Z999AA10123456784', '1Z999', '[tracking]'],
    'numeric tracking' => ['AusPost 33012345678901234567', '3301234', '[tracking]'],
    'street address' => ['Send it to 12 Smith Street, Newtown', '12 Smith Street', '[address]'],
    'unit address' => ['Ship to Unit 4, 10 Long Bay Road', 'Long Bay Road', '[address]'],
    'slash address' => ['3/45 King St please', 'King St', '[address]'],
    'po box' => ['PO Box 123 Sydney', 'Box 123', '[address]'],
    'state postcode' => ['Newtown NSW 2042', 'NSW 2042', '[address]'],
    'UK postcode' => ['London SW1A 1AA', 'SW1A 1AA', '[address]'],
]);

test('the scrubber removes identifiers and the post-check passes the result', function (string $text, string $leak, string $placeholder) {
    $scrubbed = piiT()->scrub($text);

    expect($scrubbed)->not->toContain($leak)
        ->and($scrubbed)->toContain($placeholder)
        ->and(piiT()->violations($scrubbed))->toBe([])
        ->and(piiT()->clean($text))->toBe(trim($scrubbed));
})->with('identifiers');

test('the post-check rejects text that still carries an identifier', function (string $text, string $reason) {
    expect(piiT()->violations($text))->toContain($reason);
})->with([
    'email' => ['reach me at a@b.co', PiiScrubber::EMAIL],
    'phone' => ['0412345678', PiiScrubber::PHONE],
    'order' => ['about #123', PiiScrubber::ORDER],
    'order prefix' => ['about SD123', PiiScrubber::ORDER],
    'tracking' => ['code AB12345678', PiiScrubber::TRACKING],
    'address' => ['at 99 Queen Street', PiiScrubber::ADDRESS],
]);

test('customer names from the conversation are scrubbed and checked; staff names and common words are not', function () {
    $names = PiiScrubber::namesFrom('Priya Raghunathan', 'priya.r1988@example.com');

    expect($names)->toContain('Priya Raghunathan', 'Priya', 'Raghunathan');

    $text = 'Hi Priya, thanks. PRIYA RAGHUNATHAN asked. Cheers, Craig';
    $scrubbed = piiT()->scrub($text, $names);
    expect($scrubbed)->toBe('Hi [name], thanks. [name] asked. Cheers, Craig')
        ->and(piiT()->violations($scrubbed, $names))->toBe([])
        ->and(piiT()->violations('Ask Priya for the photo', $names))->toBe([PiiScrubber::NAME]);

    // Surnames that are ordinary words, and lower-case words, survive.
    $names = PiiScrubber::namesFrom('Will Post', 'will@example.com');
    expect(piiT()->scrub('We will post it today via Australia Post.', $names))->toBe('We will post it today via Australia Post.');

    // A single-part name only matches as a name is written.
    expect(piiT()->scrub('The amber light means pairing mode. Amber replied.', ['Amber']))->toBe('The amber light means pairing mode. [name] replied.');
});

test('policy text with dates, amounts and durations is left intact', function () {
    $text = 'Refunds within 30 days of delivery; we refund $49.95 shipping. Since 2026-03-01 shipping to AU takes 7-10 business days. Call before 26/11/2026.';

    expect(piiT()->clean($text))->toBe($text);
});

test('clean() drops text the post-check still rejects and says why', function () {
    $out = piiT()->clean('contact me: someone at example dot com or a@b.co', [], $reason);
    expect($out)->not->toBeNull(); // the email was scrubbed, so it passes

    $out = piiT()->clean('the code was AB 12345678', [], $reason);
    expect($out)->toBeNull()->and($reason)->toBe(PiiScrubber::TRACKING);
});

test('canned replies hash the same across greetings, names, numbers and spacing', function () {
    $a = "Hi Sarah,\n\nThanks for reaching out! Orders ship from our warehouse within 2 business days and usually arrive in 10 to 15 business days. You can track it here.\n\nCheers,\nCraig";
    $b = "Hey Tom!\nThanks for reaching out!  Orders ship from our warehouse within 3 business days and usually arrive in 10 to 15 business days.   You can track it here.\nKind regards, Mel";
    $c = "Hi Sarah,\n\nSorry, we cannot refund a used item after thirty days. You are welcome to send it back for store credit instead if you like.\n\nCheers, Craig";

    expect(MacroDetector::hash($a))->not->toBeNull()
        ->and(MacroDetector::hash($a))->toBe(MacroDetector::hash($b))
        ->and(MacroDetector::hash($a))->not->toBe(MacroDetector::hash($c))
        ->and(MacroDetector::hash('Thanks!'))->toBeNull();
});
