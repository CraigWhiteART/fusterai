<?php

namespace App\Domains\Mailbox\Sync\Imap;

use App\Domains\Mailbox\Models\Mailbox;
use App\Domains\Mailbox\Sync\Contracts\MailSource;
use App\Domains\Mailbox\Sync\Data\FolderRef;
use App\Domains\Mailbox\Sync\Data\FolderState;
use App\Domains\Mailbox\Sync\Data\MailBody;
use App\Domains\Mailbox\Sync\Data\MailEnvelope;
use App\Domains\Mailbox\Sync\Exceptions\MailSourceException;
use App\Domains\Mailbox\Sync\Exceptions\UidValidityChanged;
use App\Domains\Mailbox\Sync\Parsing\TextOnlyMessage;
use DateTimeInterface;
use Throwable;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;

/**
 * MailSource over IMAP, using webklex/php-imap only for the socket, TLS and
 * LOGIN. Commands are sent with ImapProtocol::sendRequest() and the response
 * bytes are read back with ImapProtocol::nextLine(), reassembling literals by
 * byte count, then parsed by ImapWireParser. webklex's own FETCH decoder is
 * bypassed because it mis-tokenises quoted X-GM-LABELS (see ImapWireParser).
 *
 * Read-only by construction: folders are opened with EXAMINE (never SELECT)
 * and content is fetched with BODY.PEEK, so \Seen is never set. No STORE,
 * EXPUNGE, COPY or MOVE command exists in this class. Writes (marking read,
 * archiving) live in ImapInboxArchiver.
 *
 * Without X-GM-EXT-1 (any non-Gmail server, or Gmail with the extension
 * missing) the X-GM-* items are left out of FETCH, envelopes carry null Gmail
 * ids, and the sync folders fall back to INBOX + the \Sent folder. Threading
 * then relies on Message-ID / In-Reply-To / References alone.
 */
class ImapMailSource implements MailSource
{
    use SpeaksImap;

    public const GMAIL_CAPABILITY = 'X-GM-EXT-1';

    /** Max UIDs per body FETCH, to bound memory per round trip. */
    private const BODY_CHUNK = 20;

    /** UIDs per BODYSTRUCTURE FETCH (small responses). */
    private const STRUCTURE_CHUNK = 50;

    /** fetchTextBodies(): messages up to this size are fetched whole. */
    private const TEXT_ONLY_ABOVE_BYTES = 262_144;

    private ?ImapProtocol $protocol = null;

    /** @var list<string>|null */
    private ?array $capabilities = null;

    private ?string $examinedFolder = null;

    private ?int $examinedValidity = null;

    private readonly ImapWireParser $parser;

    /**
     * @param  array{host?: string, port?: int|string, encryption?: string|false|null, validate_cert?: bool, username?: string, password?: string, timeout?: int}  $config
     * @param  ImapProtocol|null  $protocol  an already connected and authenticated protocol (tests replay recorded sessions through this)
     */
    public function __construct(private readonly array $config, ?ImapWireParser $parser = null, ?ImapProtocol $protocol = null)
    {
        $this->parser = $parser ?? new ImapWireParser;
        $this->protocol = $protocol;
    }

    public static function fromMailbox(Mailbox $mailbox): self
    {
        return new self(self::configFor($mailbox));
    }

    /**
     * The mailbox's IMAP settings, ready to connect with.
     *
     * @return array<string, mixed>
     */
    public static function configFor(Mailbox $mailbox): array
    {
        $config = $mailbox->imap_config;
        if (! $config || empty($config['host']) || empty($config['username'])) {
            throw new MailSourceException("Mailbox {$mailbox->id} has no usable IMAP config");
        }

        $config['password'] = self::normalizePassword((string) ($config['password'] ?? ''));

        return $config;
    }

    /** Gmail app passwords are 16 letters, often pasted with spaces. */
    public static function normalizePassword(string $password): string
    {
        $stripped = str_replace(' ', '', $password);

        return preg_match('/^[a-z]{16}$/i', $stripped) ? $stripped : $password;
    }

    public function __destruct()
    {
        $this->close();
    }

    // ── MailSource ───────────────────────────────────────────────────

    public function supportsGmailExtensions(): bool
    {
        return in_array(self::GMAIL_CAPABILITY, $this->capabilities(), true);
    }

    public function syncFolders(): array
    {
        $folders = $this->listFolders();

        if ($this->supportsGmailExtensions()) {
            foreach ($folders as $folder) {
                if ($folder->role === FolderRef::ROLE_ALL) {
                    return [$folder];
                }
            }
            // Gmail with "Show in IMAP" off for All Mail: fall through to INBOX + Sent.
        }

        $picked = [];
        foreach ($folders as $folder) {
            if ($folder->role === FolderRef::ROLE_INBOX) {
                $picked[FolderRef::ROLE_INBOX] ??= $folder;
            }
            if ($folder->role === FolderRef::ROLE_SENT) {
                $picked[FolderRef::ROLE_SENT] ??= $folder;
            }
        }

        if (! isset($picked[FolderRef::ROLE_SENT])) {
            foreach ($folders as $folder) {
                if (preg_match('/^(INBOX[.\/])?Sent( Items| Messages| Mail)?$/i', (string) $folder->displayName)) {
                    $picked[FolderRef::ROLE_SENT] = new FolderRef($folder->name, FolderRef::ROLE_SENT, $folder->attributes, $folder->displayName, $folder->delimiter);
                    break;
                }
            }
        }

        $picked[FolderRef::ROLE_INBOX] ??= new FolderRef('INBOX', FolderRef::ROLE_INBOX, [], 'INBOX');

        return array_values($picked);
    }

    public function folderState(FolderRef $folder): FolderState
    {
        $data = $this->examine($folder->name);

        return new FolderState($folder, $data['uidvalidity'], $data['uidnext'], $data['exists']);
    }

    public function fetchEnvelopes(FolderState $state, int $fromUid, int $toUid): array
    {
        $fromUid = max(1, $fromUid);
        if ($toUid < $fromUid) {
            return [];
        }

        $this->ensureExamined($state);

        $items = ['UID', 'FLAGS', 'INTERNALDATE', 'RFC822.SIZE'];
        if ($this->supportsGmailExtensions()) {
            array_push($items, 'X-GM-MSGID', 'X-GM-THRID', 'X-GM-LABELS');
        }
        $items[] = 'BODY.PEEK[HEADER]';

        // Explicit upper bound, never "*": "UID FETCH n:*" returns the newest
        // message even when n is above every existing UID.
        $raw = $this->command("UID FETCH {$fromUid}:{$toUid} (".implode(' ', $items).')');

        return $this->envelopesFromFetch($raw, $state, $fromUid, $toUid);
    }

    public function fetchBodies(FolderState $state, array $uids): array
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids), fn (int $u) => $u > 0)));
        if ($uids === []) {
            return [];
        }

        $this->ensureExamined($state);

        $bodies = [];
        foreach (array_chunk($uids, self::BODY_CHUNK) as $chunk) {
            $raw = $this->command('UID FETCH '.implode(',', $chunk).' (UID BODY.PEEK[])');
            foreach ($this->parser->parseFetch($raw) as $item) {
                $uid = isset($item['UID']) && is_string($item['UID']) ? (int) $item['UID'] : 0;
                $source = $item['BODY[]'] ?? null;
                if ($uid > 0 && in_array($uid, $chunk, true) && is_string($source)) {
                    $bodies[$uid] = new MailBody($state->folder->name, $state->uidValidity, $uid, $source);
                }
            }
        }

        ksort($bodies);

        return $bodies;
    }

    /**
     * Small messages (most mail without attachments) are fetched whole: one
     * round trip and no structure parsing. Bigger multipart messages are
     * fetched as BODYSTRUCTURE, then the header and the text sections only.
     */
    public function fetchTextBodies(FolderState $state, array $uids): array
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids), fn (int $u) => $u > 0)));
        if ($uids === []) {
            return [];
        }

        $this->ensureExamined($state);

        $bodies = [];
        $whole = [];
        foreach (array_chunk($uids, self::STRUCTURE_CHUNK) as $chunk) {
            $raw = $this->command('UID FETCH '.implode(',', $chunk).' (UID RFC822.SIZE BODYSTRUCTURE)');
            foreach ($this->parser->parseFetch($raw) as $item) {
                $uid = isset($item['UID']) && is_string($item['UID']) ? (int) $item['UID'] : 0;
                if ($uid <= 0 || ! in_array($uid, $chunk, true)) {
                    continue;
                }
                $size = is_string($item['RFC822.SIZE'] ?? null) ? (int) $item['RFC822.SIZE'] : 0;
                $plan = $size > self::TEXT_ONLY_ABOVE_BYTES && is_array($item['BODYSTRUCTURE'] ?? null)
                    ? BodyStructure::plan($item['BODYSTRUCTURE'])
                    : null;

                if ($plan === null) {
                    $whole[] = $uid;

                    continue;
                }

                $body = $this->fetchTextSections($state, $uid, $plan);
                if ($body !== null) {
                    $bodies[$uid] = $body;
                }
            }
        }

        $bodies += $this->fetchBodies($state, $whole);
        ksort($bodies);

        return $bodies;
    }

    public function firstUidOnOrAfter(FolderState $state, DateTimeInterface $date): ?int
    {
        $this->ensureExamined($state);

        $uids = $this->parser->parseSearch($this->command('UID SEARCH SINCE '.$date->format('j-M-Y')));

        return $uids[0] ?? null;
    }

    public function close(): void
    {
        if ($this->protocol !== null) {
            try {
                $this->protocol->logout();
            } catch (Throwable) {
                // Already gone.
            }
        }
        $this->protocol = null;
        $this->capabilities = null;
        $this->examinedFolder = null;
        $this->examinedValidity = null;
    }

    // ── Diagnostics ──────────────────────────────────────────────────

    /**
     * Read-only spike against a real server: capabilities, folder discovery,
     * the newest $count envelopes with Gmail ids and labels, and a check that
     * the FLAGS of those messages are byte-identical before and after (i.e.
     * nothing was marked read). See tests/Manual/gmail-probe.php.
     *
     * @return array<string, mixed>
     */
    public function probe(int $count = 5): array
    {
        $report = [
            'capabilities' => $this->capabilities(),
            'x_gm_ext_1' => $this->supportsGmailExtensions(),
            'folders' => array_map(fn (FolderRef $f) => [
                'name' => $f->name,
                'display_name' => $f->displayName,
                'attributes' => $f->attributes,
                'role' => $f->role,
            ], $this->listFolders()),
        ];

        $syncFolders = $this->syncFolders();
        $report['sync_folders'] = array_map(fn (FolderRef $f) => $f->displayName ?? $f->name, $syncFolders);

        $state = $this->folderState($syncFolders[0]);
        $report['folder_state'] = [
            'folder' => $state->folder->name,
            'uidvalidity' => $state->uidValidity,
            'uidnext' => $state->uidNext,
            'exists' => $state->exists,
        ];

        // UIDs in All Mail are sparse; window on the last 14 days instead of a UID span.
        $hi = $state->maxUid();
        $lo = $this->firstUidOnOrAfter($state, new \DateTimeImmutable('-14 days')) ?? max(1, $hi - 500);
        $report['window'] = ['from_uid' => $lo, 'to_uid' => $hi];
        $flagsBefore = $this->command("UID FETCH {$lo}:{$hi} (UID FLAGS)");
        $envelopes = array_slice($this->fetchEnvelopes($state, $lo, $hi), -max(1, $count));
        $flagsAfter = $this->command("UID FETCH {$lo}:{$hi} (UID FLAGS)");

        $report['envelopes'] = array_map(function (MailEnvelope $e) {
            preg_match('/^Subject:[ \t]*(.*)$/mi', $e->rawHeaders, $s);
            preg_match('/^Message-ID:[ \t]*(.*)$/mi', $e->rawHeaders, $m);

            return [
                'uid' => $e->uid,
                'gm_msgid' => $e->gmMsgid,
                'gm_thrid' => $e->gmThrid,
                'labels' => $e->labels,
                'flags' => $e->flags,
                'internal_date' => $e->internalDate?->format(DATE_ATOM),
                'size' => $e->size,
                'message_id' => trim($m[1] ?? ''),
                'subject' => trim($s[1] ?? ''),
            ];
        }, $envelopes);

        $report['flags_unchanged'] = $this->stripTags($flagsBefore) === $this->stripTags($flagsAfter);

        return $report;
    }

    // ── Internals ────────────────────────────────────────────────────

    /** @return list<string> */
    private function capabilities(): array
    {
        if ($this->capabilities === null) {
            // Ask after LOGIN: Gmail only lists X-GM-EXT-1 once authenticated.
            $this->capabilities = $this->parser->parseCapabilities($this->command('CAPABILITY'));
        }

        return $this->capabilities;
    }

    /** @return list<FolderRef> */
    private function listFolders(): array
    {
        $caps = $this->capabilities();
        $command = in_array('SPECIAL-USE', $caps, true) && in_array('LIST-EXTENDED', $caps, true)
            ? 'LIST "" "*" RETURN (SPECIAL-USE)'
            : 'LIST "" "*"'; // Gmail includes \All, \Sent ... in plain LIST

        return $this->parser->parseList($this->command($command));
    }

    /** @return array{uidvalidity: int, uidnext: int, exists: int} */
    private function examine(string $folder): array
    {
        $raw = $this->command('EXAMINE '.$this->quote($folder));
        $data = $this->parser->parseExamine($raw);

        if ($data['uidvalidity'] === null) {
            throw new MailSourceException("EXAMINE {$folder} returned no UIDVALIDITY");
        }

        $this->examinedFolder = $folder;
        $this->examinedValidity = $data['uidvalidity'];

        return [
            'uidvalidity' => $data['uidvalidity'],
            // UIDNEXT is optional in RFC 3501; without it fall back to "no new mail known".
            'uidnext' => $data['uidnext'] ?? 0,
            'exists' => $data['exists'],
        ];
    }

    private function ensureExamined(FolderState $state): void
    {
        if ($this->examinedFolder !== $state->folder->name) {
            $this->examine($state->folder->name);
        }

        if ($this->examinedValidity !== $state->uidValidity) {
            throw new UidValidityChanged($state->folder->name, $state->uidValidity, (int) $this->examinedValidity);
        }
    }

    /** @return list<MailEnvelope> */
    private function envelopesFromFetch(string $raw, FolderState $state, int $fromUid, int $toUid): array
    {
        $envelopes = [];
        foreach ($this->parser->parseFetch($raw) as $item) {
            $uid = isset($item['UID']) && is_string($item['UID']) && ctype_digit($item['UID']) ? (int) $item['UID'] : 0;
            if ($uid < $fromUid || $uid > $toUid) {
                continue;
            }

            $size = $item['RFC822.SIZE'] ?? null;

            $envelopes[$uid] = new MailEnvelope(
                folder: $state->folder->name,
                uidValidity: $state->uidValidity,
                uid: $uid,
                rawHeaders: is_string($item['BODY[HEADER]'] ?? null) ? $item['BODY[HEADER]'] : '',
                gmMsgid: ImapWireParser::gmId($item['X-GM-MSGID'] ?? null),
                gmThrid: ImapWireParser::gmId($item['X-GM-THRID'] ?? null),
                labels: ImapWireParser::labels($item['X-GM-LABELS'] ?? null),
                flags: ImapWireParser::flags($item['FLAGS'] ?? null),
                internalDate: ImapWireParser::internalDate($item['INTERNALDATE'] ?? null),
                size: is_string($size) && ctype_digit($size) ? (int) $size : null,
            );
        }

        ksort($envelopes);

        return array_values($envelopes);
    }

    /**
     * @param  array{text: list<array{section: string, type: string, charset: ?string, encoding: string, size: int}>, attachments: list<array{name: string, mime: string, size: int, inline: bool}>}  $plan
     */
    private function fetchTextSections(FolderState $state, int $uid, array $plan): ?MailBody
    {
        $items = ['UID', 'BODY.PEEK[HEADER]'];
        foreach ($plan['text'] as $part) {
            $items[] = 'BODY.PEEK['.$part['section'].']';
        }

        $raw = $this->command("UID FETCH {$uid} (".implode(' ', $items).')');

        foreach ($this->parser->parseFetch($raw) as $item) {
            $got = isset($item['UID']) && is_string($item['UID']) ? (int) $item['UID'] : 0;
            if ($got !== $uid || ! is_string($item['BODY[HEADER]'] ?? null)) {
                continue;
            }

            $parts = [];
            foreach ($plan['text'] as $part) {
                $bytes = $item['BODY['.$part['section'].']'] ?? null;
                if (is_string($bytes)) {
                    $parts[] = ['type' => $part['type'], 'charset' => $part['charset'], 'encoding' => $part['encoding'], 'body' => $bytes];
                }
            }

            return new MailBody(
                $state->folder->name,
                $state->uidValidity,
                $uid,
                TextOnlyMessage::build($item['BODY[HEADER]'], $parts),
                textOnly: true,
                attachments: $plan['attachments'],
            );
        }

        return null; // expunged meanwhile
    }

    private function stripTags(string $raw): string
    {
        return (string) preg_replace('/^TAG\d+ .*$/m', '', $raw);
    }
}
