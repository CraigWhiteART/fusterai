<?php

namespace App\Domains\Mailbox\Sync\Imap;

use App\Domains\Mailbox\Sync\Data\FolderRef;
use DateTimeImmutable;

/**
 * Pure parser for raw IMAP server responses (RFC 3501 + Gmail's X-GM-EXT-1).
 *
 * Why not webklex's ImapProtocol::decodeLine()? In webklex/php-imap 6.2 its
 * quoted-string rule only ends a string at `"` followed by a space or end of
 * line, so the last quoted item of a list (`("\\Important" "My Label")`) is
 * split into two broken tokens. X-GM-LABELS hits that on every message with a
 * quoted label. It also leaves backslash escapes in place. This parser takes
 * the raw bytes ImapMailSource reads off the wire and handles atoms, quoted
 * strings with escapes, literals ({n} / ~{n}), NIL and nested lists.
 *
 * Token model: string (atom, quoted string or literal), null (NIL), or a PHP
 * list for a parenthesised list. Numbers stay strings, so 64-bit Gmail ids
 * survive intact.
 */
final class ImapWireParser
{
    private const STATUS_WORDS = ['OK', 'NO', 'BAD', 'BYE', 'PREAUTH'];

    private string $buf = '';

    private int $pos = 0;

    private int $len = 0;

    /**
     * Split a raw response (possibly several untagged lines plus the tagged
     * completion) into response lines.
     *
     * Status lines (`* OK [UIDNEXT 7] Predicted`) are not tokenised past the
     * status word because their human text is free-form; they come back as
     * tokens [STATUS, responseCode|null, text].
     *
     * @return list<array{tag: string, tokens: list<mixed>}>
     */
    public function parseLines(string $raw): array
    {
        $this->buf = $raw;
        $this->pos = 0;
        $this->len = strlen($raw);

        $lines = [];
        while ($this->pos < $this->len) {
            $this->skipNewlines();
            if ($this->pos >= $this->len) {
                break;
            }

            $tag = $this->readUntilSpaceOrEol();
            $this->skipSpaces();

            $tokens = $this->isStatusAhead() ? $this->readStatusLine() : $this->readTokensToEol();
            $lines[] = ['tag' => $tag, 'tokens' => $tokens];
        }

        return $lines;
    }

    /**
     * Each untagged FETCH response as an item map, keys upper-cased
     * (UID, X-GM-MSGID, X-GM-LABELS, INTERNALDATE, RFC822.SIZE, FLAGS,
     * BODY[HEADER], BODY[] ...), plus SEQ for the message sequence number.
     *
     * @return list<array<string, mixed>>
     */
    public function parseFetch(string $raw): array
    {
        $out = [];
        foreach ($this->parseLines($raw) as $line) {
            $t = $line['tokens'];
            if ($line['tag'] !== '*' || count($t) < 3 || ! is_string($t[1]) || strtoupper($t[1]) !== 'FETCH' || ! is_array($t[2])) {
                continue;
            }

            $items = ['SEQ' => $t[0]];
            $list = $t[2];
            for ($i = 0; $i + 1 < count($list); $i += 2) {
                if (is_string($list[$i])) {
                    $items[strtoupper($list[$i])] = $list[$i + 1];
                }
            }
            $out[] = $items;
        }

        return $out;
    }

    /** @return list<string> upper-cased capability atoms */
    public function parseCapabilities(string $raw): array
    {
        $caps = [];
        foreach ($this->parseLines($raw) as $line) {
            $t = $line['tokens'];
            if ($line['tag'] === '*' && isset($t[0]) && is_string($t[0]) && strtoupper($t[0]) === 'CAPABILITY') {
                foreach (array_slice($t, 1) as $cap) {
                    if (is_string($cap)) {
                        $caps[] = strtoupper($cap);
                    }
                }
            }

            // Capabilities can also arrive as a response code: "OK [CAPABILITY ...]".
            if (isset($t[1]) && is_string($t[1]) && preg_match('/^\[CAPABILITY ([^\]]*)\]$/i', $t[1], $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $cap) {
                    $caps[] = strtoupper($cap);
                }
            }
        }

        return array_values(array_unique($caps));
    }

    /**
     * Folders from a LIST response, with a role guessed from RFC 6154
     * special-use attributes.
     *
     * @return list<FolderRef>
     */
    public function parseList(string $raw): array
    {
        $folders = [];
        foreach ($this->parseLines($raw) as $line) {
            $t = $line['tokens'];
            if ($line['tag'] !== '*' || count($t) < 4 || ! is_string($t[0])) {
                continue;
            }
            $kind = strtoupper($t[0]);
            if ($kind !== 'LIST' && $kind !== 'XLIST') {
                continue;
            }

            $attributes = array_values(array_filter(is_array($t[1]) ? $t[1] : [], 'is_string'));
            $name = is_string($t[3]) ? $t[3] : '';
            if ($name === '') {
                continue;
            }

            $folders[] = new FolderRef(
                name: $name,
                role: $this->roleFor($name, $attributes),
                attributes: $attributes,
                displayName: self::decodeModifiedUtf7($name),
                delimiter: is_string($t[2]) ? $t[2] : null,
            );
        }

        return $folders;
    }

    /**
     * EXAMINE/SELECT response.
     *
     * @return array{uidvalidity: ?int, uidnext: ?int, exists: int}
     */
    public function parseExamine(string $raw): array
    {
        $result = ['uidvalidity' => null, 'uidnext' => null, 'exists' => 0];

        foreach ($this->parseLines($raw) as $line) {
            if ($line['tag'] !== '*') {
                continue;
            }
            $t = $line['tokens'];

            if (count($t) >= 2 && is_string($t[0]) && ctype_digit($t[0]) && is_string($t[1]) && strtoupper($t[1]) === 'EXISTS') {
                $result['exists'] = (int) $t[0];
            }

            if (isset($t[1]) && is_string($t[1]) && preg_match('/^\[(UIDVALIDITY|UIDNEXT) (\d+)\]$/i', $t[1], $m)) {
                $result[strtolower($m[1])] = (int) $m[2];
            }
        }

        return $result;
    }

    /** @return list<int> */
    public function parseSearch(string $raw): array
    {
        $uids = [];
        foreach ($this->parseLines($raw) as $line) {
            $t = $line['tokens'];
            if ($line['tag'] === '*' && isset($t[0]) && is_string($t[0]) && strtoupper($t[0]) === 'SEARCH') {
                foreach (array_slice($t, 1) as $n) {
                    if (is_string($n) && ctype_digit($n)) {
                        $uids[] = (int) $n;
                    }
                }
            }
        }
        sort($uids);

        return $uids;
    }

    /**
     * Status of the tagged completion line.
     *
     * @return array{status: string, text: string}|null
     */
    public function taggedStatus(string $raw, string $tag): ?array
    {
        foreach ($this->parseLines($raw) as $line) {
            if ($line['tag'] === $tag) {
                $t = $line['tokens'];

                return [
                    'status' => strtoupper((string) ($t[0] ?? '')),
                    'text' => trim(($t[1] ?? '').' '.($t[2] ?? '')),
                ];
            }
        }

        return null;
    }

    // ── Value helpers ────────────────────────────────────────────────

    /** Decimal-string id (X-GM-MSGID/THRID) or null. Never cast to int. */
    public static function gmId(mixed $value): ?string
    {
        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }
        $id = ltrim($value, '0');

        return $id === '' ? '0' : $id;
    }

    /**
     * X-GM-LABELS list to UTF-8 strings. Gmail sends system labels as atoms
     * (\Inbox, \Sent, \Important) and user labels as atoms, quoted strings or
     * literals, in modified UTF-7.
     *
     * @return list<string>
     */
    public static function labels(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $labels = [];
        foreach ($value as $label) {
            if (is_string($label) && $label !== '') {
                $labels[] = self::decodeModifiedUtf7($label);
            }
        }

        return $labels;
    }

    /** @return list<string> */
    public static function flags(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, fn ($f) => is_string($f) && $f !== '')) : [];
    }

    /** INTERNALDATE ("17-Jul-1996 02:44:25 -0700", day may be space-padded). */
    public static function internalDate(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('j-M-Y H:i:s O', trim($value));

        return $date ?: null;
    }

    /** RFC 3501 5.1.3 modified UTF-7 mailbox/label name to UTF-8. */
    public static function decodeModifiedUtf7(string $value): string
    {
        if (! str_contains($value, '&')) {
            return $value;
        }

        if (! mb_check_encoding($value, 'UTF7-IMAP')) {
            return $value;
        }

        $decoded = mb_convert_encoding($value, 'UTF-8', 'UTF7-IMAP');

        return is_string($decoded) && $decoded !== '' ? $decoded : $value;
    }

    // ── Folder roles ─────────────────────────────────────────────────

    /** @param list<string> $attributes */
    private function roleFor(string $name, array $attributes): string
    {
        $attrs = array_map('strtolower', $attributes);

        if (in_array('\\all', $attrs, true) || in_array('\\allmail', $attrs, true)) {
            return FolderRef::ROLE_ALL;
        }
        if (in_array('\\sent', $attrs, true)) {
            return FolderRef::ROLE_SENT;
        }
        if (strcasecmp($name, 'INBOX') === 0 || in_array('\\inbox', $attrs, true)) {
            return FolderRef::ROLE_INBOX;
        }

        return 'other';
    }

    // ── Tokeniser ────────────────────────────────────────────────────

    private function isStatusAhead(): bool
    {
        foreach (self::STATUS_WORDS as $word) {
            $l = strlen($word);
            if (strncasecmp(substr($this->buf, $this->pos, $l), $word, $l) === 0) {
                $next = $this->buf[$this->pos + $l] ?? "\n";
                if ($next === ' ' || $next === "\r" || $next === "\n") {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<mixed> [STATUS, "[CODE ...]"|null, text] */
    private function readStatusLine(): array
    {
        $status = strtoupper($this->readUntilSpaceOrEol());
        $this->skipSpaces();

        $code = null;
        if (($this->buf[$this->pos] ?? '') === '[') {
            $end = strpos($this->buf, ']', $this->pos);
            $eol = $this->eolPos();
            if ($end !== false && $end < $eol) {
                $code = substr($this->buf, $this->pos, $end - $this->pos + 1);
                $this->pos = $end + 1;
                $this->skipSpaces();
            }
        }

        $eol = $this->eolPos();
        $text = rtrim(substr($this->buf, $this->pos, $eol - $this->pos), "\r");
        $this->pos = $eol;

        return [$status, $code, $text];
    }

    /** @return list<mixed> */
    private function readTokensToEol(): array
    {
        $tokens = [];
        while ($this->pos < $this->len) {
            $this->skipSpaces();
            $c = $this->buf[$this->pos] ?? '';
            if ($c === "\r" || $c === "\n" || $c === '') {
                break;
            }
            if ($c === ')') {
                // Stray close paren at top level: skip it.
                $this->pos++;

                continue;
            }
            $tokens[] = $this->readToken();
        }

        return $tokens;
    }

    private function readToken(): mixed
    {
        $c = $this->buf[$this->pos];

        if ($c === '(') {
            $this->pos++;

            return $this->readList();
        }
        if ($c === '"') {
            return $this->readQuoted();
        }
        if ($c === '{' || ($c === '~' && ($this->buf[$this->pos + 1] ?? '') === '{')) {
            $literal = $this->readLiteral();
            if ($literal !== null) {
                return $literal;
            }
        }

        $atom = $this->readAtom();

        return strcasecmp($atom, 'NIL') === 0 ? null : $atom;
    }

    /** @return list<mixed> */
    private function readList(): array
    {
        $items = [];
        while ($this->pos < $this->len) {
            $this->skipSpaces();
            $c = $this->buf[$this->pos] ?? '';
            if ($c === ')') {
                $this->pos++;

                return $items;
            }
            if ($c === "\r" || $c === "\n" || $c === '') {
                // Unterminated list: the server forgot a paren. Close it here.
                return $items;
            }
            $items[] = $this->readToken();
        }

        return $items;
    }

    private function readQuoted(): string
    {
        $this->pos++; // opening quote
        $out = '';
        while ($this->pos < $this->len) {
            $c = $this->buf[$this->pos];
            if ($c === '\\' && $this->pos + 1 < $this->len) {
                $out .= $this->buf[$this->pos + 1];
                $this->pos += 2;

                continue;
            }
            if ($c === '"') {
                $this->pos++;

                return $out;
            }
            if ($c === "\r" || $c === "\n") {
                return $out; // unterminated; quoted strings cannot span lines
            }
            $out .= $c;
            $this->pos++;
        }

        return $out;
    }

    private function readLiteral(): ?string
    {
        if (! preg_match('/\G~?\{(\d+)\+?\}\r?\n/', $this->buf, $m, 0, $this->pos)) {
            return null;
        }
        $this->pos += strlen($m[0]);
        $n = (int) $m[1];
        $literal = substr($this->buf, $this->pos, $n);
        $this->pos += strlen($literal);

        return $literal;
    }

    private function readAtom(): string
    {
        $start = $this->pos;
        $depth = 0;
        while ($this->pos < $this->len) {
            $c = $this->buf[$this->pos];
            if ($c === '[') {
                $depth++;
            } elseif ($c === ']') {
                $depth = max(0, $depth - 1);
            } elseif ($c === "\r" || $c === "\n") {
                break;
            } elseif ($depth === 0 && ($c === ' ' || $c === '(' || $c === ')')) {
                break;
            }
            $this->pos++;
        }

        if ($this->pos === $start) {
            // Unknown single char we cannot start a token with; consume it.
            $this->pos++;

            return $this->buf[$start];
        }

        return substr($this->buf, $start, $this->pos - $start);
    }

    private function readUntilSpaceOrEol(): string
    {
        $start = $this->pos;
        while ($this->pos < $this->len && ! in_array($this->buf[$this->pos], [' ', "\r", "\n"], true)) {
            $this->pos++;
        }

        return substr($this->buf, $start, $this->pos - $start);
    }

    private function skipSpaces(): void
    {
        while ($this->pos < $this->len && $this->buf[$this->pos] === ' ') {
            $this->pos++;
        }
    }

    private function skipNewlines(): void
    {
        while ($this->pos < $this->len && in_array($this->buf[$this->pos], ["\r", "\n", ' '], true)) {
            $this->pos++;
        }
    }

    private function eolPos(): int
    {
        $eol = strpos($this->buf, "\n", $this->pos);

        return $eol === false ? $this->len : $eol;
    }
}
