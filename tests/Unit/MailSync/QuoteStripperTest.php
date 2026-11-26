<?php

use App\Domains\Mailbox\Sync\Parsing\QuoteStripper;

/** What the removed ProcessInboundEmailJob::stripQuotedReply() did. */
function legacyStripQuotedReply(string $html): string
{
    $html = preg_replace('/<blockquote[^>]*>.*?<\/blockquote>/is', '', $html) ?? $html;
    foreach (['On .* wrote:', '-----Original Message-----', 'From:.*Sent:.*To:.*Subject:'] as $sep) {
        $html = preg_replace('/('.$sep.').*$/is', '', $html) ?? $html;
    }

    return trim($html);
}

test('keeps a message that says "On" early and quotes a "wrote:" attribution later (old regex ate it)', function () {
    $text = "On Monday I emailed about my order and nobody answered.\n"
        ."It still hasn't arrived and I'm getting worried.\n\n"
        ."On Tue, 4 Nov 2025 at 10:00, Soul Dial Support <support@souldial.test> wrote:\n"
        ."> Thanks for your patience, it ships tomorrow.\n";

    expect(legacyStripQuotedReply($text))->toBe('')
        ->and((new QuoteStripper)->fromText($text))->toBe(
            "On Monday I emailed about my order and nobody answered.\nIt still hasn't arrived and I'm getting worried."
        );
});

test('old regex also deletes customer-written blockquotes; the stripper keeps them', function () {
    $html = '<p>Your returns page says:</p><blockquote>Returns accepted within 30 days.</blockquote><p>Mine is day 20, so can I return it?</p>';

    expect(legacyStripQuotedReply($html))->not->toContain('30 days')
        ->and((new QuoteStripper)->fromHtml($html))->toBe(
            "Your returns page says:\n\nReturns accepted within 30 days.\n\nMine is day 20, so can I return it?"
        );
});

dataset('quote cases', [
    'gmail html quote' => [
        'html',
        '<div dir="ltr">Thanks, that worked!<div><br></div><div>Emma</div></div><br><div class="gmail_quote gmail_quote_container"><div dir="ltr" class="gmail_attr">On Mon, 3 Nov 2025 at 10:42, Sam &lt;support@souldial.test&gt; wrote:<br></div><blockquote class="gmail_quote"><div>Try restarting the dial.</div></blockquote></div>',
        "Thanks, that worked!\n\nEmma",
    ],
    'apple mail blockquote type=cite' => [
        'html',
        '<html><body><div>Perfect, see you then.</div><div><br></div><div>Sent from my iPhone</div><div><br><blockquote type="cite">On 4 Nov 2025, at 10:00, Sam &lt;support@souldial.test&gt; wrote:<br><br></blockquote></div><blockquote type="cite"><div>We can deliver on Friday.</div></blockquote></body></html>',
        'Perfect, see you then.',
    ],
    'outlook web html (appendonsend / divRplyFwdMsg)' => [
        'html',
        '<div>Please cancel the order.</div><div id="appendonsend"></div><hr style="display:inline-block;width:98%"><div id="divRplyFwdMsg" dir="ltr"><b>From:</b> Soul Dial Support &lt;support@souldial.test&gt;<br><b>Sent:</b> Monday, November 3, 2025 10:42<br><b>To:</b> Jonas<br><b>Subject:</b> Re: order</div><div>Your order is confirmed.</div>',
        'Please cancel the order.',
    ],
    'outlook desktop text header block with rule' => [
        'text',
        "Yes please, go ahead with the refund.\n\nRegards\nJonas\n\n________________________________\nFrom: Soul Dial Support <support@souldial.test>\nSent: Monday, 3 November 2025 10:42\nTo: Jonas Weber <jonas.weber@corp.example>\nSubject: Re: Refund\n\nWould you like a refund or a replacement?",
        "Yes please, go ahead with the refund.\n\nRegards\nJonas",
    ],
    'original message separator' => [
        'text',
        "Sounds good.\n\n-----Original Message-----\nFrom: Soul Dial\nSent: Monday\nTo: me\nSubject: hi\n\nold text",
        'Sounds good.',
    ],
    'attribution wrapped over two lines' => [
        'text',
        "Thanks Sam. It arrived today.\n\nOn Mon, 3 Nov 2025 at 10:42, Soul Dial Support <\nsupport@souldial.test> wrote:\n\n> Hi Emma,\n> It ships Thursday.",
        'Thanks Sam. It arrived today.',
    ],
    'interleaved > quoting keeps the answers' => [
        'text',
        "Hello,\n\n> Could you confirm the order number?\n\nYes, it is #1037.\n\n> Is the dial damaged?\n\nOnly the box.",
        "Hello,\n\nYes, it is #1037.\n\nOnly the box.",
    ],
    'signature delimiter' => [
        'text',
        "Only the box is damaged.\n\nGrüße,\nJürgen\n\n-- \nJürgen Müller\nLindenstraße 4, 10115 Berlin",
        "Only the box is damaged.\n\nGrüße,\nJürgen",
    ],
    'style and script content never leaks' => [
        'html',
        '<html><head><style>.x{color:red}</style><script>alert(1)</script></head><body><p>Real content.</p><script>track()</script></body></html>',
        'Real content.',
    ],
    'paragraphs and line breaks preserved' => [
        'html',
        '<p>First paragraph.</p><p>Second line one<br>Second line two</p><ul><li>Item A</li><li>Item B</li></ul>',
        "First paragraph.\n\nSecond line one\nSecond line two\n\n- Item A\n- Item B",
    ],
    'a sentence starting with On and ending with wrote: but no date or address is not an attribution' => [
        'text',
        "On the box my daughter wrote:\nHappy birthday Dad\n\nCan you gift wrap it?",
        "On the box my daughter wrote:\nHappy birthday Dad\n\nCan you gift wrap it?",
    ],
    'mid-sentence on and wrote are content' => [
        'text',
        "I placed the order on Monday. My sister wrote a review on your site, she loves it.\nCan I order another?",
        "I placed the order on Monday. My sister wrote a review on your site, she loves it.\nCan I order another?",
    ],
    'entirely quoted message falls back to full text' => [
        'text',
        "> Your order has shipped.\n> Tracking: 1Z999",
        "> Your order has shipped.\n> Tracking: 1Z999",
    ],
    'forward with no comment falls back to full text' => [
        'text',
        "From: Shop <orders@shop.example>\nSent: Monday\nTo: Emma\nSubject: Your receipt\n\nOrder #1042 total 89.00",
        "From: Shop <orders@shop.example>\nSent: Monday\nTo: Emma\nSubject: Your receipt\n\nOrder #1042 total 89.00",
    ],
    'html entirely inside gmail_quote falls back to full text' => [
        'html',
        '<div class="gmail_quote"><div>Forwarded order details: #1042</div></div>',
        'Forwarded order details: #1042',
    ],
]);

test('strips quotes and signatures', function (string $kind, string $input, string $expected) {
    $stripper = new QuoteStripper;
    $actual = $kind === 'html' ? $stripper->fromHtml($input) : $stripper->fromText($input);

    expect($actual)->toBe($expected);
})->with('quote cases');

test('clean() prefers html and falls back to text', function () {
    $stripper = new QuoteStripper;

    expect($stripper->clean('<p>From html</p>', 'From text'))->toBe('From html')
        ->and($stripper->clean(null, "From text\n\n> quoted"))->toBe('From text')
        ->and($stripper->clean('   ', 'Only text'))->toBe('Only text')
        ->and($stripper->clean(null, null))->toBe('');
});

test('a reply typed inside the Gmail signature block is kept', function () {
    $html = '<div dir="ltr"><div>Hi Sam👋</div><div><div dir="ltr" class="gmail_signature" data-smartmail="gmail_signature">'
        .'<div>I can see you ordered four, and the discount was not applied. I can adjust this for you today.</div>'
        .'<div><br>Kind Regards,<br>Natalie<br>example.net</div></div></div></div><br>'
        .'<div class="gmail_quote gmail_quote_container"><div class="gmail_attr">On Thu, Mar 5, 2026 at 2:40 AM Sam &lt;sam@example.com&gt; wrote:<br></div>'
        .'<blockquote class="gmail_quote">Where is my discount?</blockquote></div>';

    $clean = (new QuoteStripper)->clean($html, null);

    expect($clean)->toContain('Hi Sam')
        ->toContain('I can adjust this for you today.')
        ->not->toContain('Where is my discount');
});

test('a short signature block under a real message is still removed', function () {
    $html = '<div dir="ltr"><div>Hi Sam, your order shipped this morning and the tracking link is on its way.</div>'
        .'<div class="gmail_signature" data-smartmail="gmail_signature">Kind Regards,<br>Natalie<br>example.net</div></div>';

    expect((new QuoteStripper)->clean($html, null))
        ->toContain('your order shipped this morning')
        ->not->toContain('Kind Regards');
});
