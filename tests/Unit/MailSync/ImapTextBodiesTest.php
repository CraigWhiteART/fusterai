<?php

use App\Domains\Mailbox\Sync\Data\FolderRef;
use App\Domains\Mailbox\Sync\Data\FolderState;
use App\Domains\Mailbox\Sync\Imap\BodyStructure;
use App\Domains\Mailbox\Sync\Imap\ImapMailSource;
use App\Domains\Mailbox\Sync\Imap\ImapWireParser;
use App\Domains\Mailbox\Sync\Parsing\MessageParser;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;

/** Same replay harness as ImapMailSourceTest (own copy so --filter can run this file alone). */
function tbScriptedImap(array $script): ImapProtocol
{
    return new class($script) extends ImapProtocol
    {
        /** @var list<string> */
        public array $commands = [];

        public function __construct(private array $script)
        {
            parent::__construct(Config::make());
            $this->stream = fopen('php://memory', 'r+');
        }

        public function write(Response $response, string $data): void
        {
            $this->commands[] = rtrim($data);
            $tag = explode(' ', $data, 2)[0];
            $reply = array_shift($this->script) ?? "* BYE\r\n{TAG} OK bye\r\n";
            $pos = ftell($this->stream);
            fseek($this->stream, 0, SEEK_END);
            fwrite($this->stream, str_replace('{TAG}', $tag, $reply));
            fseek($this->stream, $pos);
        }
    };
}

function tbLit(string $bytes): string
{
    return '{'.strlen($bytes)."}\r\n".$bytes;
}

const TB_STRUCTURE = '((("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "QUOTED-PRINTABLE" 52 1 NIL NIL NIL)'
    .'("TEXT" "HTML" ("CHARSET" "UTF-8") NIL NIL "QUOTED-PRINTABLE" 70 1 NIL NIL NIL) "ALTERNATIVE" ("BOUNDARY" "b1") NIL NIL)'
    .'("IMAGE" "JPEG" ("NAME" "crack.jpg") "<ii_1>" NIL "BASE64" 4000000 NIL ("ATTACHMENT" ("FILENAME" "crack.jpg")) NIL NIL)'
    .'("APPLICATION" "PDF" ("NAME*" "utf-8\'\'Rechnung%20M%C3%A4rz.pdf") NIL NIL "BASE64" 800 NIL ("ATTACHMENT" NIL) NIL NIL)'
    .' "MIXED" ("BOUNDARY" "b0") NIL NIL)';

test('BODYSTRUCTURE gives the text sections and describes the attachments', function () {
    $tokens = (new ImapWireParser)->parseFetch('* 1 FETCH (UID 101 BODYSTRUCTURE '.TB_STRUCTURE.")\r\nT OK\r\n")[0]['BODYSTRUCTURE'];

    $plan = BodyStructure::plan($tokens);

    expect(array_column($plan['text'], 'section'))->toBe(['1.1', '1.2'])
        ->and($plan['text'][0]['encoding'])->toBe('quoted-printable')
        ->and($plan['text'][0]['charset'])->toBe('UTF-8')
        ->and($plan['attachments'])->toBe([
            ['name' => 'crack.jpg', 'mime' => 'image/jpeg', 'size' => 3_000_000, 'inline' => false],
            ['name' => 'Rechnung März.pdf', 'mime' => 'application/pdf', 'size' => 600, 'inline' => false],
        ])
        ->and(BodyStructure::plan(['TEXT', 'PLAIN', ['CHARSET', 'UTF-8'], null, null, '7BIT', '12', '1']))->toBeNull();
});

test('history fetch downloads only header and text parts of a big message, small ones whole, and never sets \\Seen', function () {
    $examine = "* OK [UIDVALIDITY 594931781] UIDs valid.\r\n* 3 EXISTS\r\n* OK [UIDNEXT 110] Predicted next UID.\r\n{TAG} OK [READ-ONLY] selected.\r\n";
    $structures = '* 1 FETCH (UID 101 RFC822.SIZE 4001200 BODYSTRUCTURE '.TB_STRUCTURE.")\r\n"
        .'* 2 FETCH (UID 102 RFC822.SIZE 900 BODYSTRUCTURE ("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "7BIT" 20 1 NIL NIL NIL))'."\r\n"
        ."{TAG} OK Success\r\n";
    $header = "Message-ID: <big@x>\r\nSubject: Cracked shade\r\nFrom: Emma <emma@example.net>\r\nTo: support@souldial.test\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed;\r\n boundary=\"b0\"\r\n\r\n";
    $plain = "The glass shade is cracked =E2=80=93 see the photo.\r\n";
    $html = "<p>The glass shade is cracked =E2=80=93 see the photo.</p>\r\n";
    $sections = '* 1 FETCH (UID 101 BODY[HEADER] '.tbLit($header).' BODY[1.1] '.tbLit($plain).' BODY[1.2] '.tbLit($html).")\r\n{TAG} OK Success\r\n";
    $small = "Message-ID: <small@x>\r\nSubject: hi\r\n\r\nHello there\r\n";
    $whole = '* 2 FETCH (UID 102 BODY[] '.tbLit($small).")\r\n{TAG} OK Success\r\n";

    $protocol = tbScriptedImap([$examine, $structures, $sections, $whole]);
    $source = new ImapMailSource([], protocol: $protocol);
    $state = new FolderState(new FolderRef('[Gmail]/All Mail', FolderRef::ROLE_ALL), 594931781, 110);

    $bodies = $source->fetchTextBodies($state, [101, 102]);

    expect($protocol->commands)->toBe([
        'TAG1 EXAMINE "[Gmail]/All Mail"',
        'TAG2 UID FETCH 101,102 (UID RFC822.SIZE BODYSTRUCTURE)',
        'TAG3 UID FETCH 101 (UID BODY.PEEK[HEADER] BODY.PEEK[1.1] BODY.PEEK[1.2])',
        'TAG4 UID FETCH 102 (UID BODY.PEEK[])',
    ]);
    foreach ($protocol->commands as $command) {
        expect($command)->not->toMatch('/\b(SELECT|STORE|EXPUNGE|COPY|MOVE)\b/')
            ->and($command)->not->toMatch('/BODY\[/'); // always BODY.PEEK
    }

    expect(array_keys($bodies))->toBe([101, 102])
        ->and($bodies[101]->textOnly)->toBeTrue()
        ->and($bodies[101]->attachments[0]['name'])->toBe('crack.jpg')
        ->and($bodies[102]->textOnly)->toBeFalse()
        ->and($bodies[102]->raw)->toBe($small);

    $parsed = (new MessageParser)->parse($bodies[101]->raw);
    expect($parsed->subject)->toBe('Cracked shade')
        ->and($parsed->fromEmail())->toBe('emma@example.net')
        ->and($parsed->bodyText)->toContain('cracked – see the photo')
        ->and($parsed->bodyHtml)->toContain('<p>The glass shade')
        ->and($parsed->attachments)->toBe([]);
});
