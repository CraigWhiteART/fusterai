<?php

use App\Domains\Mailbox\Sync\Data\FolderRef;
use App\Domains\Mailbox\Sync\Data\FolderState;
use App\Domains\Mailbox\Sync\Exceptions\MailSourceException;
use App\Domains\Mailbox\Sync\Exceptions\UidValidityChanged;
use App\Domains\Mailbox\Sync\Imap\ImapMailSource;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;

/**
 * Replays recorded server responses through webklex's real ImapProtocol
 * (sendRequest + nextLine), so ImapMailSource's command strings and literal
 * reassembly are exercised without a server. Each write() pops the next
 * scripted reply; "{TAG}" is replaced by the command's tag.
 *
 * @param  list<string>  $script
 */
function scriptedImap(array $script): ImapProtocol
{
    return new class($script) extends ImapProtocol
    {
        /** @var list<string> */
        public array $commands = [];

        /** @param list<string> $script */
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

function imapLit(string $bytes): string
{
    return '{'.strlen($bytes)."}\r\n".$bytes;
}

const GMAIL_CAPS = "* CAPABILITY IMAP4rev1 UNSELECT IDLE NAMESPACE QUOTA ID XLIST CHILDREN X-GM-EXT-1 UIDPLUS COMPRESS=DEFLATE ENABLE MOVE CONDSTORE ESEARCH UTF8=ACCEPT SPECIAL-USE\r\n{TAG} OK Thats all she wrote!\r\n";

const GMAIL_LIST = "* LIST (\\HasNoChildren) \"/\" \"INBOX\"\r\n"
    ."* LIST (\\HasChildren \\Noselect) \"/\" \"[Gmail]\"\r\n"
    ."* LIST (\\All \\HasNoChildren) \"/\" \"[Gmail]/Alle Nachrichten\"\r\n"
    ."* LIST (\\HasNoChildren \\Sent) \"/\" \"[Gmail]/Gesendet\"\r\n"
    ."{TAG} OK Success\r\n";

const GMAIL_EXAMINE = "* FLAGS (\\Answered \\Flagged \\Draft \\Deleted \\Seen)\r\n"
    ."* OK [PERMANENTFLAGS ()] Flags permitted.\r\n"
    ."* OK [UIDVALIDITY 594931781] UIDs valid.\r\n"
    ."* 3 EXISTS\r\n"
    ."* 0 RECENT\r\n"
    ."* OK [UIDNEXT 110] Predicted next UID.\r\n"
    ."{TAG} OK [READ-ONLY] [Gmail]/Alle Nachrichten selected. (Success)\r\n";

test('reads All Mail over the raw protocol: EXAMINE, PEEK, Gmail items, literals', function () {
    $h1 = "Message-ID: <a@x>\r\nSubject: First\r\n\r\n";
    $h2 = "Message-ID: <b@x>\r\nSubject: Second (has a {5} in it)\r\n\r\n";
    $fetch = '* 1 FETCH (UID 101 FLAGS (\\Seen) INTERNALDATE " 3-Nov-2025 09:15:02 +0000" RFC822.SIZE 1200 X-GM-MSGID 1847650000000000101 X-GM-THRID 1847650000000000101 X-GM-LABELS ("\\\\Inbox" "Contact form") BODY[HEADER] '.imapLit($h1).")\r\n"
        .'* 2 FETCH (UID 109 FLAGS () INTERNALDATE "06-Nov-2025 21:30:13 +0100" RFC822.SIZE 80 X-GM-MSGID 18000000000000000109 X-GM-THRID 18000000000000000109 X-GM-LABELS (\\Inbox) BODY[HEADER] '.imapLit($h2).")\r\n"
        ."{TAG} OK Success\r\n";

    $protocol = scriptedImap([GMAIL_CAPS, GMAIL_LIST, GMAIL_EXAMINE, $fetch]);
    $source = new ImapMailSource([], protocol: $protocol);

    $folders = $source->syncFolders();
    expect($folders)->toHaveCount(1)
        ->and($folders[0]->name)->toBe('[Gmail]/Alle Nachrichten')
        ->and($folders[0]->role)->toBe(FolderRef::ROLE_ALL);

    $state = $source->folderState($folders[0]);
    expect($state->uidValidity)->toBe(594931781)->and($state->uidNext)->toBe(110);

    $envelopes = $source->fetchEnvelopes($state, 101, 109);

    expect($protocol->commands)->toBe([
        'TAG1 CAPABILITY',
        'TAG2 LIST "" "*"',
        'TAG3 EXAMINE "[Gmail]/Alle Nachrichten"',
        'TAG4 UID FETCH 101:109 (UID FLAGS INTERNALDATE RFC822.SIZE X-GM-MSGID X-GM-THRID X-GM-LABELS BODY.PEEK[HEADER])',
    ]);
    expect($envelopes)->toHaveCount(2)
        ->and($envelopes[0]->uid)->toBe(101)
        ->and($envelopes[0]->rawHeaders)->toBe($h1)
        ->and($envelopes[0]->labels)->toBe(['\\Inbox', 'Contact form'])
        ->and($envelopes[0]->flags)->toBe(['\\Seen'])
        ->and($envelopes[1]->gmMsgid)->toBe('18000000000000000109')
        ->and($envelopes[1]->rawHeaders)->toBe($h2)
        ->and($envelopes[1]->uidValidity)->toBe(594931781);

    foreach ($protocol->commands as $command) {
        expect($command)->not->toMatch('/\b(SELECT|STORE|EXPUNGE|COPY|MOVE)\b/')
            ->and($command)->not->toMatch('/BODY\[/');
    }
});

test('fetches full bodies with BODY.PEEK[] and keys them by UID', function () {
    $raw = "Message-ID: <a@x>\r\nSubject: hi\r\n\r\nHello\r\n";
    $protocol = scriptedImap([
        GMAIL_CAPS,
        GMAIL_LIST,
        GMAIL_EXAMINE,
        '* 1 FETCH (UID 101 BODY[] '.imapLit($raw).")\r\n{TAG} OK Success\r\n",
        "* SEARCH 105 101 107\r\n{TAG} OK SEARCH completed\r\n",
    ]);
    $source = new ImapMailSource([], protocol: $protocol);
    $state = $source->folderState($source->syncFolders()[0]);

    $bodies = $source->fetchBodies($state, [101, 102]);
    $first = $source->firstUidOnOrAfter($state, new DateTimeImmutable('2025-11-01'));

    expect(array_keys($bodies))->toBe([101])
        ->and($bodies[101]->raw)->toBe($raw)
        ->and($first)->toBe(101)
        ->and($protocol->commands[3])->toBe('TAG4 UID FETCH 101,102 (UID BODY.PEEK[])')
        ->and($protocol->commands[4])->toBe('TAG5 UID SEARCH SINCE 1-Nov-2025');
});

test('without X-GM-EXT-1 it falls back to INBOX + Sent and omits the Gmail items', function () {
    $caps = "* CAPABILITY IMAP4rev1 IDLE SPECIAL-USE LIST-EXTENDED\r\n{TAG} OK done\r\n";
    $list = "* LIST (\\HasNoChildren) \".\" INBOX\r\n"
        ."* LIST (\\HasNoChildren \\Sent) \".\" \"INBOX.Sent Items\"\r\n"
        ."* LIST (\\HasNoChildren \\Trash) \".\" INBOX.Trash\r\n"
        ."{TAG} OK List completed\r\n";
    $examine = "* OK [UIDVALIDITY 7] Ok\r\n* OK [UIDNEXT 3] Ok\r\n* 2 EXISTS\r\n{TAG} OK [READ-ONLY] Examine completed\r\n";
    $fetch = '* 1 FETCH (UID 1 FLAGS () INTERNALDATE "01-Nov-2025 10:00:00 +0000" RFC822.SIZE 40 BODY[HEADER] '.imapLit("Message-ID: <q@x>\r\n\r\n").")\r\n{TAG} OK done\r\n";

    $protocol = scriptedImap([$caps, $list, $examine, $fetch]);
    $source = new ImapMailSource([], protocol: $protocol);

    $folders = $source->syncFolders();
    $envelopes = $source->fetchEnvelopes($source->folderState($folders[0]), 1, 2);

    expect(array_map(fn (FolderRef $f) => $f->name, $folders))->toBe(['INBOX', 'INBOX.Sent Items'])
        ->and($source->supportsGmailExtensions())->toBeFalse()
        ->and($protocol->commands[1])->toBe('TAG2 LIST "" "*" RETURN (SPECIAL-USE)')
        ->and($protocol->commands[3])->toBe('TAG4 UID FETCH 1:2 (UID FLAGS INTERNALDATE RFC822.SIZE BODY.PEEK[HEADER])')
        ->and($envelopes[0]->gmMsgid)->toBeNull()
        ->and($envelopes[0]->labels)->toBe([]);
});

test('a UIDVALIDITY that changed under a FolderState is refused', function () {
    $protocol = scriptedImap([str_replace('UIDVALIDITY 594931781', 'UIDVALIDITY 1', GMAIL_EXAMINE)]);
    $source = new ImapMailSource([], protocol: $protocol);
    $stale = new FolderState(new FolderRef('[Gmail]/Alle Nachrichten', FolderRef::ROLE_ALL), 594931781, 110);

    expect(fn () => $source->fetchEnvelopes($stale, 101, 109))->toThrow(UidValidityChanged::class)
        ->and($protocol->commands)->toBe(['TAG1 EXAMINE "[Gmail]/Alle Nachrichten"']);
});

test('a NO response becomes a MailSourceException', function () {
    $protocol = scriptedImap(["{TAG} NO [NONEXISTENT] Unknown Mailbox\r\n"]);
    $source = new ImapMailSource([], protocol: $protocol);

    expect(fn () => $source->folderState(new FolderRef('[Gmail]/Nope', 'other')))
        ->toThrow(MailSourceException::class, 'NO');
});
