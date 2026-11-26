<?php

namespace App\Domains\Mailbox\Sync\Fake;

use App\Domains\Mailbox\Models\Mailbox;
use App\Domains\Mailbox\Sync\Contracts\MailSource;
use App\Domains\Mailbox\Sync\Contracts\MailSourceFactory;
use App\Domains\Mailbox\Sync\Data\FolderRef;
use App\Domains\Mailbox\Sync\Data\FolderState;
use App\Domains\Mailbox\Sync\Data\MailBody;
use App\Domains\Mailbox\Sync\Data\MailEnvelope;
use App\Domains\Mailbox\Sync\Exceptions\MailSourceException;
use App\Domains\Mailbox\Sync\Exceptions\UidValidityChanged;
use App\Domains\Mailbox\Sync\Parsing\HeaderBlock;
use App\Domains\Mailbox\Sync\Parsing\MessageParser;
use App\Domains\Mailbox\Sync\Parsing\TextOnlyMessage;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * In-memory MailSource for tests, fed from .eml files plus per-message
 * metadata (UID, Gmail ids, labels, INTERNALDATE). Gmail mode exposes one
 * "[Gmail]/All Mail" folder with \All; non-Gmail mode exposes INBOX + Sent.
 *
 * Also acts as its own MailSourceFactory so it can be handed straight to
 * MailSyncService.
 *
 * Failure injection: failOn('fetchEnvelopes', 2) makes the 2nd call throw.
 */
final class FakeMailSource implements MailSource, MailSourceFactory
{
    public const ALL_MAIL = '[Gmail]/All Mail';

    /** @var array<string, array{ref: FolderRef, uidvalidity: int, messages: array<int, array{raw: string, gm_msgid: ?string, gm_thrid: ?string, labels: list<string>, flags: list<string>, internal_date: ?DateTimeImmutable}>}> */
    private array $folders = [];

    /** @var array<string, int> method => call number that throws */
    private array $failures = [];

    /** @var array<string, int> */
    public array $calls = [];

    public bool $closed = false;

    public function __construct(private readonly bool $gmail = true)
    {
        if ($gmail) {
            $this->folders[self::ALL_MAIL] = ['ref' => new FolderRef(self::ALL_MAIL, FolderRef::ROLE_ALL, ['\\HasNoChildren', '\\All'], self::ALL_MAIL, '/'), 'uidvalidity' => 1, 'messages' => []];
        } else {
            $this->folders['INBOX'] = ['ref' => new FolderRef('INBOX', FolderRef::ROLE_INBOX, [], 'INBOX', '/'), 'uidvalidity' => 1, 'messages' => []];
            $this->folders['Sent'] = ['ref' => new FolderRef('Sent', FolderRef::ROLE_SENT, ['\\Sent'], 'Sent', '/'), 'uidvalidity' => 1, 'messages' => []];
        }
    }

    /**
     * Load a directory holding *.eml files and a manifest.json:
     * [{"file": "01.eml", "uid": 101, "gm_msgid": "...", "gm_thrid": "...",
     *   "labels": ["\\Inbox"], "internal_date": "2025-11-03T09:15:02Z", "folder": "INBOX"}, ...]
     */
    public static function fromFixtures(string $dir, bool $gmail = true, ?string $manifest = null): self
    {
        $source = new self($gmail);
        $entries = json_decode((string) file_get_contents($manifest ?? $dir.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);

        foreach ($entries as $entry) {
            $source->addMessage(
                raw: (string) file_get_contents($dir.'/'.$entry['file']),
                uid: (int) $entry['uid'],
                gmMsgid: $gmail ? ($entry['gm_msgid'] ?? null) : null,
                gmThrid: $gmail ? ($entry['gm_thrid'] ?? null) : null,
                labels: $gmail ? ($entry['labels'] ?? []) : [],
                internalDate: $entry['internal_date'] ?? null,
                folder: $gmail ? null : ($entry['folder'] ?? 'INBOX'),
            );
        }

        return $source;
    }

    public function forMailbox(Mailbox $mailbox): MailSource
    {
        $this->closed = false;

        return $this;
    }

    /** @param list<string> $labels */
    public function addMessage(
        string $raw,
        int $uid,
        ?string $gmMsgid = null,
        ?string $gmThrid = null,
        array $labels = [],
        ?string $internalDate = null,
        ?string $folder = null,
        array $flags = ['\\Seen'],
    ): self {
        $folder ??= $this->defaultFolder();
        if (! isset($this->folders[$folder])) {
            throw new MailSourceException("Fake has no folder {$folder}");
        }

        $date = $internalDate !== null
            ? new DateTimeImmutable($internalDate)
            : (HeaderBlock::parse($raw)->date() ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));

        $this->folders[$folder]['messages'][$uid] = [
            'raw' => $raw,
            'gm_msgid' => $gmMsgid,
            'gm_thrid' => $gmThrid,
            'labels' => $labels,
            'flags' => $flags,
            'internal_date' => $date,
        ];
        ksort($this->folders[$folder]['messages']);

        return $this;
    }

    public function removeMessage(int $uid, ?string $folder = null): self
    {
        unset($this->folders[$folder ?? $this->defaultFolder()]['messages'][$uid]);

        return $this;
    }

    /**
     * Simulate a server-side UIDVALIDITY change: new epoch, and every message
     * renumbered by $uidOffset (what a real rebuild does).
     */
    public function changeUidValidity(int $uidValidity, int $uidOffset = 0, ?string $folder = null): self
    {
        $folder ??= $this->defaultFolder();
        $renumbered = [];
        foreach ($this->folders[$folder]['messages'] as $uid => $message) {
            $renumbered[$uid + $uidOffset] = $message;
        }
        $this->folders[$folder]['messages'] = $renumbered;
        $this->folders[$folder]['uidvalidity'] = $uidValidity;

        return $this;
    }

    /** Make the $nth call (1-based, counted from now) to $method throw. */
    public function failOn(string $method, int $nth = 1): self
    {
        $this->failures[$method] = ($this->calls[$method] ?? 0) + $nth;

        return $this;
    }

    public function maxUid(?string $folder = null): int
    {
        $uids = array_keys($this->folders[$folder ?? $this->defaultFolder()]['messages']);

        return $uids === [] ? 0 : max($uids);
    }

    // ── MailSource ───────────────────────────────────────────────────

    public function syncFolders(): array
    {
        $this->track(__FUNCTION__);

        return array_values(array_map(fn ($f) => $f['ref'], $this->folders));
    }

    public function supportsGmailExtensions(): bool
    {
        return $this->gmail;
    }

    public function folderState(FolderRef $folder): FolderState
    {
        $this->track(__FUNCTION__);
        $f = $this->folder($folder->name);

        return new FolderState($f['ref'], $f['uidvalidity'], $this->maxUid($folder->name) + 1, count($f['messages']));
    }

    public function fetchEnvelopes(FolderState $state, int $fromUid, int $toUid): array
    {
        $this->track(__FUNCTION__);
        $f = $this->checked($state);

        $out = [];
        foreach ($f['messages'] as $uid => $m) {
            if ($uid < $fromUid || $uid > $toUid) {
                continue;
            }
            $raw = str_replace("\r\n", "\n", $m['raw']);
            $headerEnd = strpos($raw, "\n\n");
            $headers = $headerEnd === false ? $raw : substr($raw, 0, $headerEnd + 2);

            $out[] = new MailEnvelope(
                folder: $state->folder->name,
                uidValidity: $state->uidValidity,
                uid: $uid,
                rawHeaders: $headers,
                gmMsgid: $m['gm_msgid'],
                gmThrid: $m['gm_thrid'],
                labels: $m['labels'],
                flags: $m['flags'],
                internalDate: $m['internal_date'],
                size: strlen($m['raw']),
            );
        }

        return $out;
    }

    public function fetchBodies(FolderState $state, array $uids): array
    {
        $this->track(__FUNCTION__);
        $f = $this->checked($state);

        $out = [];
        foreach ($uids as $uid) {
            if (isset($f['messages'][$uid])) {
                $out[$uid] = new MailBody($state->folder->name, $state->uidValidity, $uid, $f['messages'][$uid]['raw']);
            }
        }
        ksort($out);

        return $out;
    }

    /** Always the text-only stand-in, so tests see what history ingest gets from a real server for a big message. */
    public function fetchTextBodies(FolderState $state, array $uids): array
    {
        $this->track(__FUNCTION__);
        $f = $this->checked($state);

        $out = [];
        foreach ($uids as $uid) {
            if (! isset($f['messages'][$uid])) {
                continue;
            }
            $raw = str_replace("\r\n", "\n", $f['messages'][$uid]['raw']);
            $headerEnd = strpos($raw, "\n\n");
            $header = $headerEnd === false ? $raw : substr($raw, 0, $headerEnd + 1);
            $parsed = (new MessageParser)->parse($f['messages'][$uid]['raw']);

            $parts = [];
            foreach (['text/plain' => $parsed->bodyText, 'text/html' => $parsed->bodyHtml] as $type => $text) {
                if ($text !== null) {
                    $parts[] = ['type' => $type, 'charset' => 'utf-8', 'encoding' => 'base64', 'body' => chunk_split(base64_encode($text))];
                }
            }

            $out[$uid] = new MailBody(
                $state->folder->name,
                $state->uidValidity,
                $uid,
                TextOnlyMessage::build($header, $parts),
                textOnly: true,
                attachments: array_map(fn (array $a) => [
                    'name' => $a['name'], 'mime' => $a['mime'], 'size' => $a['size'], 'inline' => $a['inline'],
                ], $parsed->attachments),
            );
        }
        ksort($out);

        return $out;
    }

    public function firstUidOnOrAfter(FolderState $state, DateTimeInterface $date): ?int
    {
        $this->track(__FUNCTION__);
        $f = $this->checked($state);

        // IMAP SEARCH SINCE compares dates only, ignoring time and timezone.
        $day = $date->format('Y-m-d');
        foreach ($f['messages'] as $uid => $m) {
            if ($m['internal_date']->format('Y-m-d') >= $day) {
                return $uid;
            }
        }

        return null;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    // ── Internals ────────────────────────────────────────────────────

    private function defaultFolder(): string
    {
        return $this->gmail ? self::ALL_MAIL : 'INBOX';
    }

    /** @return array{ref: FolderRef, uidvalidity: int, messages: array<int, mixed>} */
    private function folder(string $name): array
    {
        if (! isset($this->folders[$name])) {
            throw new MailSourceException("Fake has no folder {$name}");
        }

        return $this->folders[$name];
    }

    /** @return array{ref: FolderRef, uidvalidity: int, messages: array<int, mixed>} */
    private function checked(FolderState $state): array
    {
        $f = $this->folder($state->folder->name);
        if ($f['uidvalidity'] !== $state->uidValidity) {
            throw new UidValidityChanged($state->folder->name, $state->uidValidity, $f['uidvalidity']);
        }

        return $f;
    }

    private function track(string $method): void
    {
        $this->calls[$method] = ($this->calls[$method] ?? 0) + 1;

        if (isset($this->failures[$method]) && $this->calls[$method] === $this->failures[$method]) {
            unset($this->failures[$method]);
            throw new MailSourceException("Simulated failure in {$method}");
        }
    }
}
