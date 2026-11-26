<?php

use App\Domains\Mailbox\Sync\Data\FolderRef;
use App\Domains\Mailbox\Sync\Imap\ImapWireParser;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;

/** Build "{n}\r\n<bytes>" for a literal. */
function imapLiteral(string $bytes): string
{
    return '{'.strlen($bytes)."}\r\n".$bytes;
}

function gmailFetchResponse(): string
{
    $header1 = "Message-ID: <CAEmma001@mail.gmail.com>\r\nSubject: Where is my order?\r\n\r\n";
    $header2 = "Message-ID: <x@y>\r\nSubject: (no subject)\r\n\r\n";

    return '* 1 FETCH (X-GM-THRID 1847650000000000101 X-GM-MSGID 1847650000000000101 '
        .'X-GM-LABELS ("\\\\Inbox" \\Important "Contact form" "Say \\"hi\\"" &AMk-quipe "Kunden/R&APw-ckfragen") '
        .'UID 101 RFC822.SIZE 2048 INTERNALDATE " 3-Nov-2025 09:15:02 +0000" FLAGS (\\Seen $Forwarded) '
        .'BODY[HEADER] '.imapLiteral($header1).")\r\n"
        .'* 2 FETCH (X-GM-THRID 18446744073709551615 X-GM-MSGID 18000000000000000109 X-GM-LABELS () '
        .'UID 109 RFC822.SIZE 77 INTERNALDATE "06-Nov-2025 21:30:13 +0100" FLAGS () '
        .'BODY[HEADER] '.imapLiteral($header2).")\r\n"
        ."* 3 EXPUNGE\r\n"
        ."TAG7 OK Success\r\n";
}

test('parses a Gmail FETCH with ids, labels, flags, internaldate and a header literal', function () {
    $items = (new ImapWireParser)->parseFetch(gmailFetchResponse());

    expect($items)->toHaveCount(2);

    $first = $items[0];
    expect($first['UID'])->toBe('101')
        ->and(ImapWireParser::gmId($first['X-GM-MSGID']))->toBe('1847650000000000101')
        ->and(ImapWireParser::gmId($first['X-GM-THRID']))->toBe('1847650000000000101')
        ->and(ImapWireParser::flags($first['FLAGS']))->toBe(['\\Seen', '$Forwarded'])
        ->and($first['RFC822.SIZE'])->toBe('2048')
        ->and(ImapWireParser::internalDate($first['INTERNALDATE'])?->format(DATE_ATOM))->toBe('2025-11-03T09:15:02+00:00')
        ->and($first['BODY[HEADER]'])->toBe("Message-ID: <CAEmma001@mail.gmail.com>\r\nSubject: Where is my order?\r\n\r\n");
});

test('labels keep spaces, unescape quotes and decode modified UTF-7', function () {
    $items = (new ImapWireParser)->parseFetch(gmailFetchResponse());

    expect(ImapWireParser::labels($items[0]['X-GM-LABELS']))->toBe([
        '\\Inbox',
        '\\Important',
        'Contact form',
        'Say "hi"',
        'Équipe',
        'Kunden/Rückfragen',
    ])->and(ImapWireParser::labels($items[1]['X-GM-LABELS']))->toBe([]);
});

test('20-digit unsigned 64-bit ids survive as exact strings', function () {
    $items = (new ImapWireParser)->parseFetch(gmailFetchResponse());

    expect(ImapWireParser::gmId($items[1]['X-GM-MSGID']))->toBe('18000000000000000109')
        ->and(ImapWireParser::gmId($items[1]['X-GM-THRID']))->toBe('18446744073709551615')
        ->and(ImapWireParser::internalDate($items[1]['INTERNALDATE'])?->format(DATE_ATOM))->toBe('2025-11-06T21:30:13+01:00');

    // 18446744073709551615 > PHP_INT_MAX: an int cast would have clamped it.
    expect((string) PHP_INT_MAX)->toBe('9223372036854775807');
});

test('labels can arrive as literals and NIL is null', function () {
    $raw = '* 4 FETCH (UID 7 X-GM-LABELS ('.imapLiteral('Line one label')." \\Sent) X-GM-MSGID 42 BODY[HEADER] NIL)\r\nTAG1 OK done\r\n";

    $item = (new ImapWireParser)->parseFetch($raw)[0];

    expect(ImapWireParser::labels($item['X-GM-LABELS']))->toBe(['Line one label', '\\Sent'])
        ->and($item['BODY[HEADER]'])->toBeNull()
        ->and(ImapWireParser::gmId($item['X-GM-MSGID']))->toBe('42');
});

test('a literal containing parentheses, quotes and CRLF does not break tokenising', function () {
    $body = "Subject: (weird) \"quoted\" {3}\r\nX: )))\r\n\r\nline 1\r\nTAG9 OK not really\r\n";
    $raw = '* 1 FETCH (UID 5 BODY[] '.imapLiteral($body)." FLAGS (\\Seen))\r\nTAG9 OK Success\r\n";

    $parser = new ImapWireParser;
    $item = $parser->parseFetch($raw)[0];

    expect($item['BODY[]'])->toBe($body)
        ->and($item['FLAGS'])->toBe(['\\Seen'])
        ->and($parser->taggedStatus($raw, 'TAG9'))->toBe(['status' => 'OK', 'text' => 'Success']);
});

test('parses capabilities and detects X-GM-EXT-1', function () {
    $raw = "* CAPABILITY IMAP4rev1 UNSELECT IDLE NAMESPACE QUOTA ID XLIST CHILDREN X-GM-EXT-1 UIDPLUS COMPRESS=DEFLATE ENABLE MOVE CONDSTORE ESEARCH UTF8=ACCEPT LIST-EXTENDED LIST-STATUS LITERAL- SPECIAL-USE APPENDLIMIT=35651584\r\nTAG2 OK Thats all she wrote!\r\n";

    $caps = (new ImapWireParser)->parseCapabilities($raw);

    expect($caps)->toContain('X-GM-EXT-1')->toContain('SPECIAL-USE')->toContain('IMAP4REV1');
});

test('finds the localised All Mail folder by its \All attribute', function () {
    $raw = "* LIST (\\HasNoChildren) \"/\" \"INBOX\"\r\n"
        ."* LIST (\\HasChildren \\Noselect) \"/\" \"[Gmail]\"\r\n"
        ."* LIST (\\All \\HasNoChildren) \"/\" \"[Gmail]/Alle Nachrichten\"\r\n"
        ."* LIST (\\HasNoChildren \\Sent) \"/\" \"[Gmail]/Gesendet\"\r\n"
        ."* LIST (\\HasNoChildren \\Trash) \"/\" \"[Gmail]/Papierkorb\"\r\n"
        ."* LIST (\\HasNoChildren) \"/\" \"Kunden/R&APw-ckfragen\"\r\n"
        ."TAG3 OK Success\r\n";

    $folders = (new ImapWireParser)->parseList($raw);
    $byRole = collect($folders)->groupBy(fn (FolderRef $f) => $f->role);

    expect($byRole[FolderRef::ROLE_ALL]->first()->name)->toBe('[Gmail]/Alle Nachrichten')
        ->and($byRole[FolderRef::ROLE_SENT]->first()->name)->toBe('[Gmail]/Gesendet')
        ->and($byRole[FolderRef::ROLE_INBOX]->first()->name)->toBe('INBOX')
        ->and($folders[5]->name)->toBe('Kunden/R&APw-ckfragen')
        ->and($folders[5]->displayName)->toBe('Kunden/Rückfragen');
});

test('parses EXAMINE for UIDVALIDITY, UIDNEXT and EXISTS', function () {
    $raw = "* FLAGS (\\Answered \\Flagged \\Draft \\Deleted \\Seen \$NotPhishing \$Phishing)\r\n"
        ."* OK [PERMANENTFLAGS ()] Flags permitted.\r\n"
        ."* OK [UIDVALIDITY 594931781] UIDs valid.\r\n"
        ."* 48213 EXISTS\r\n"
        ."* 0 RECENT\r\n"
        ."* OK [UIDNEXT 391877] Predicted next UID.\r\n"
        ."* OK [HIGHESTMODSEQ 46588237]\r\n"
        ."TAG4 OK [READ-ONLY] [Gmail]/All Mail selected. (Success)\r\n";

    expect((new ImapWireParser)->parseExamine($raw))->toBe([
        'uidvalidity' => 594931781,
        'uidnext' => 391877,
        'exists' => 48213,
    ]);
});

test('parses UID SEARCH results in ascending order', function () {
    $raw = "* SEARCH 391870 391002 391500\r\nTAG5 OK SEARCH completed (Success)\r\n";

    expect((new ImapWireParser)->parseSearch($raw))->toBe([391002, 391500, 391870])
        ->and((new ImapWireParser)->parseSearch("* SEARCH\r\nTAG6 OK done\r\n"))->toBe([]);
});

test('reports a failed tagged completion', function () {
    $raw = "TAG8 BAD Could not parse command\r\n";

    expect((new ImapWireParser)->taggedStatus($raw, 'TAG8'))->toBe(['status' => 'BAD', 'text' => 'Could not parse command']);
});

test('documents why webklex decodeLine is bypassed: it splits the last quoted label of a list', function () {
    $protocol = new ImapProtocol(Config::make());
    $decode = new ReflectionMethod(ImapProtocol::class, 'decodeLine');

    $tokens = $decode->invoke($protocol, new Response(0), '1 FETCH (X-GM-LABELS ("\\\\Inbox" "My Label") UID 5)');
    $webklexLabels = $tokens[2][1];

    // webklex 6.2: ['\\\\Inbox', '"My', 'Label"'] - broken. If this starts
    // failing, webklex fixed it and ImapWireParser could be reconsidered.
    expect($webklexLabels)->not->toBe(['\\Inbox', 'My Label']);

    $ours = (new ImapWireParser)->parseFetch('* 1 FETCH (X-GM-LABELS ("\\\\Inbox" "My Label") UID 5)'."\r\n");
    expect(ImapWireParser::labels($ours[0]['X-GM-LABELS']))->toBe(['\\Inbox', 'My Label']);
});
