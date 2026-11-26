<?php

use App\Domains\Mailbox\Sync\Enums\MailClassification;
use App\Domains\Mailbox\Sync\Enums\MailDirection;
use App\Domains\Mailbox\Sync\Parsing\HeaderBlock;
use App\Domains\Mailbox\Sync\Parsing\MessageClassifier;
use App\Domains\Mailbox\Sync\Parsing\MessageParser;
use App\Domains\Mailbox\Sync\Parsing\ParsedMessage;

function parseFixture(string $file): ParsedMessage
{
    return (new MessageParser)->parse((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/mail/'.$file));
}

test('customer enquiry: identity, addresses, date, bodies', function () {
    $m = parseFixture('01-customer-enquiry.eml');

    expect($m->messageId)->toBe('CAEmma001.k3j2@mail.gmail.com')
        ->and($m->messageIdSynthetic)->toBeFalse()
        ->and($m->inReplyTo)->toBeNull()
        ->and($m->references)->toBe([])
        ->and($m->from)->toBe(['email' => 'emma.clarke@example.net', 'name' => 'Emma Clarke'])
        ->and($m->to)->toBe([['email' => 'support@souldial.test', 'name' => 'Soul Dial Support']])
        ->and($m->subject)->toBe('Where is my order #1042?')
        ->and($m->sentAt?->format(DATE_ATOM))->toBe('2025-11-03T09:15:00+00:00')
        ->and($m->bodyText)->toContain('On Friday I ordered the walnut dial lamp')
        ->and($m->bodyHtml)->toContain('<div dir="ltr">')
        ->and($m->bodyClean)->toBe("Hi there,\n\nOn Friday I ordered the walnut dial lamp (order #1042) and the tracking page has not moved since Saturday.\n\nCould you check where it is? I need it before the 12th.\n\nThanks,\nEmma")
        ->and($m->hasAttachments())->toBeFalse();
});

test('agent reply sent from Gmail: threading headers and quoted-printable html', function () {
    $m = parseFixture('02-agent-reply-from-gmail.eml');

    expect($m->messageId)->toBe('CAAgent002.q9w8@mail.gmail.com')
        ->and($m->inReplyTo)->toBe('CAEmma001.k3j2@mail.gmail.com')
        ->and($m->references)->toBe(['CAEmma001.k3j2@mail.gmail.com'])
        ->and($m->from['email'])->toBe('support@souldial.test')
        ->and($m->bodyHtml)->toContain('class="gmail_quote gmail_quote_container"')
        ->and($m->bodyClean)->toBe("Hi Emma,\n\nSorry for the wait. Your lamp left our warehouse on Saturday and the carrier scanned it this morning, so it should reach you by Thursday.\n\nBest wishes,\nSam");
});

test('customer follow-up: folded References keeps the full chain, top-posted reply is extracted', function () {
    $m = parseFixture('03-customer-followup-top-posted.eml');

    expect($m->references)->toBe(['CAEmma001.k3j2@mail.gmail.com', 'CAAgent002.q9w8@mail.gmail.com'])
        ->and($m->inReplyTo)->toBe('CAAgent002.q9w8@mail.gmail.com')
        ->and($m->referencedIds())->toBe(['CAAgent002.q9w8@mail.gmail.com', 'CAEmma001.k3j2@mail.gmail.com'])
        ->and($m->bodyClean)->toBe("Thanks Sam. It arrived today but the glass shade is cracked.\n\nCan you send a replacement shade? I'm happy to send a photo.\n\nEmma")
        ->and($m->bodyText)->toContain('> Sorry for the wait');
});

test('shopify contact form: robot sender, customer in Reply-To, style content dropped', function () {
    $m = parseFixture('04-shopify-contact-form.eml');

    expect($m->from)->toBe(['email' => 'mailer@shopify.com', 'name' => 'Soul Dial'])
        ->and($m->replyTo)->toBe([['email' => 'liam.patel@example.org', 'name' => 'Liam Patel']])
        ->and($m->to)->toBe([['email' => 'support@souldial.test', 'name' => '']])
        ->and($m->sentAt?->format(DATE_ATOM))->toBe('2025-11-03T16:02:09+00:00')
        ->and($m->bodyText)->toBeNull()
        ->and($m->bodyClean)->not->toContain('font-family')
        ->and($m->bodyClean)->toContain('Do you ship the brass dial to Canada?')
        ->and((new MessageClassifier)->classify($m, MailDirection::Inbound))->toBe(MailClassification::Support);
});

test('out-of-office: auto-reply signal headers kept and classified auto; latin-1 decoded', function () {
    $m = parseFixture('05-out-of-office.eml');

    expect($m->header('auto-submitted'))->toBe('auto-replied')
        ->and($m->header('x-auto-response-suppress'))->toBe('All')
        ->and($m->bodyText)->toContain('Mit freundlichen Grüßen')
        ->and((new MessageClassifier)->classify($m, MailDirection::Inbound))->toBe(MailClassification::Auto);
});

test('newsletter: bulk headers, encoded-word subject, script never in clean body', function () {
    $m = parseFixture('06-newsletter.eml');

    expect($m->subject)->toBe('This week at Brand Supply — 20% off brass')
        ->and($m->header('list-id'))->toBe('Brand Supply Newsletter <newsletter.brandsupply.example>')
        ->and($m->header('precedence'))->toBe('bulk')
        ->and($m->header('list-unsubscribe'))->toContain('https://brandsupply.example/u/abc123')
        ->and($m->bodyClean)->not->toContain('track(')
        ->and($m->bodyClean)->not->toContain('background')
        ->and((new MessageClassifier)->classify($m, MailDirection::Inbound))->toBe(MailClassification::Bulk);
});

test('multipart with inline image and attachment', function () {
    $m = parseFixture('07-inline-image-and-attachment.eml');

    $byName = collect($m->attachments)->keyBy('name');

    expect($m->cc)->toBe([['email' => 'tom.clarke@example.net', 'name' => 'Clarke, Tom']])
        ->and($m->references)->toBe(['CAEmma001.k3j2@mail.gmail.com', 'CAAgent002.q9w8@mail.gmail.com', 'CAEmma003.z7x6@mail.gmail.com'])
        ->and($m->attachments)->toHaveCount(2)
        ->and($byName['shade.png']['inline'])->toBeTrue()
        ->and($byName['shade.png']['content_id'])->toBe('ii_shade01')
        ->and($byName['shade.png']['mime'])->toBe('image/png')
        ->and(substr($byName['shade.png']['content'], 1, 3))->toBe('PNG')
        ->and($byName['invoice-1042.pdf']['inline'])->toBeFalse()
        ->and($byName['invoice-1042.pdf']['mime'])->toBe('application/pdf')
        ->and(str_starts_with($byName['invoice-1042.pdf']['content'], '%PDF-1.4'))->toBeTrue()
        ->and($m->hasAttachments())->toBeTrue()
        ->and($m->bodyHtml)->toContain('cid:ii_shade01')
        ->and($m->bodyClean)->toBe("Here is the photo of the cracked shade, and the invoice is attached.\n\nEmma");
});

test('plain text with > quoting, encoded From name and legacy fusterai references', function () {
    $m = parseFixture('08-plain-text-quoted.eml');

    expect($m->from)->toBe(['email' => 'juergen.mueller@posteo.example', 'name' => 'Jürgen Müller'])
        ->and($m->subject)->toBe('Re: Rücksendung / return label')
        ->and($m->sentAt?->format(DATE_ATOM))->toBe('2025-11-06T20:30:12+00:00')
        ->and($m->inReplyTo)->toBe('thread-58-0b6f3a1e-7c2d-4e8f-9a1b-2c3d4e5f6a7b@fusterai')
        ->and($m->references)->toBe(['conversation-31@fusterai', 'thread-58-0b6f3a1e-7c2d-4e8f-9a1b-2c3d4e5f6a7b@fusterai'])
        ->and($m->bodyHtml)->toBeNull()
        ->and($m->bodyClean)->toBe("Hello,\n\nYes, it is #1037.\n\nOnly the box. The dial works fine, so I would rather keep it\nif you can offer a small discount instead.\n\nGrüße,\nJürgen");
});

test('parseHeaders works on a header block alone (envelope sync)', function () {
    $raw = (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/mail/03-customer-followup-top-posted.eml');
    $headers = substr($raw, 0, strpos(str_replace("\r\n", "\n", $raw), "\n\n"));

    $m = (new MessageParser)->parseHeaders($headers);

    expect($m->messageId)->toBe('CAEmma003.z7x6@mail.gmail.com')
        ->and($m->references)->toHaveCount(2)
        ->and($m->hasBody)->toBeFalse()
        ->and($m->bodyClean)->toBeNull();
});

test('a message without Message-ID gets a stable synthetic id', function () {
    $raw = "From: a@example.com\r\nTo: b@example.com\r\nSubject: hi\r\nDate: Mon, 3 Nov 2025 09:00:00 +0000\r\n\r\nbody\r\n";
    $parser = new MessageParser;

    $one = $parser->parse($raw);
    $two = $parser->parseHeaders($raw);

    expect($one->messageIdSynthetic)->toBeTrue()
        ->and($one->messageId)->toEndWith('@missing-message-id.invalid')
        ->and($two->messageId)->toBe($one->messageId);
});

test('address lists handle quoted commas, groups, comments and bare addresses', function () {
    expect(HeaderBlock::parseAddressList('"Doe, John" <John.Doe@Example.com>, jane@example.org (Jane), Team: a@x.test, b@y.test;, <c@z.test>'))->toBe([
        ['email' => 'john.doe@example.com', 'name' => 'Doe, John'],
        ['email' => 'jane@example.org', 'name' => 'Jane'],
        ['email' => 'a@x.test', 'name' => ''],
        ['email' => 'b@y.test', 'name' => ''],
        ['email' => 'c@z.test', 'name' => ''],
    ])->and(HeaderBlock::parseAddressList('undisclosed-recipients:;'))->toBe([]);
});
