<?php

namespace App\Domains\Mailbox\Sync\Parsing;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Minimal RFC 5322 header reader: unfolds continuation lines, keeps repeated
 * headers, decodes RFC 2047 encoded words, parses address lists and Message-ID
 * lists.
 *
 * Written instead of reusing webklex's Header because that class explodes
 * References on single spaces (breaking folded chains of 3+ ids), collapses
 * multi-valued headers inconsistently (string vs array depending on count) and
 * needs a Config. This class has no dependencies and works offline.
 */
final class HeaderBlock
{
    /** @param array<string, list<string>> $headers lower-cased name => raw unfolded values */
    private function __construct(private readonly array $headers) {}

    public static function parse(string $raw): self
    {
        $raw = str_replace("\r\n", "\n", $raw);
        // The header block ends at the first empty line.
        $end = strpos($raw, "\n\n");
        if ($end !== false) {
            $raw = substr($raw, 0, $end);
        }

        $headers = [];
        $current = null;
        foreach (explode("\n", $raw) as $line) {
            if ($line === '') {
                continue;
            }
            if (($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
                // Folded: RFC 5322 unfolding removes only the CRLF.
                $last = array_key_last($headers[$current]);
                $headers[$current][$last] .= $line;

                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                $current = null;

                continue;
            }
            $current = strtolower(trim(substr($line, 0, $colon)));
            $headers[$current][] = ltrim(substr($line, $colon + 1));
        }

        return new self($headers);
    }

    public function has(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /** First raw (undecoded) value, trimmed. */
    public function raw(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? [];

        return $values === [] ? null : trim($values[0]);
    }

    /** @return list<string> all raw values */
    public function all(string $name): array
    {
        return array_map('trim', $this->headers[strtolower($name)] ?? []);
    }

    /** First value with encoded words decoded to UTF-8. */
    public function text(string $name): ?string
    {
        $raw = $this->raw($name);

        return $raw === null ? null : self::decodeWords($raw);
    }

    /**
     * Address list (From, To, Cc, Reply-To...), across repeated headers.
     *
     * @return list<array{email: string, name: string}>
     */
    public function addresses(string $name): array
    {
        $out = [];
        foreach ($this->all($name) as $value) {
            foreach (self::parseAddressList($value) as $address) {
                $out[] = $address;
            }
        }

        return $out;
    }

    /**
     * Message-IDs in a header (In-Reply-To, References), in order, without
     * angle brackets, de-duplicated.
     *
     * @return list<string>
     */
    public function messageIds(string $name): array
    {
        $ids = [];
        foreach ($this->all($name) as $value) {
            if (preg_match_all('/<([^<>\s]+)>/', $value, $m)) {
                array_push($ids, ...$m[1]);
            } else {
                // Broken mailers omit the brackets.
                foreach (preg_split('/[\s,]+/', $value) ?: [] as $token) {
                    if (str_contains($token, '@')) {
                        $ids[] = $token;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter(array_map([self::class, 'normalizeMessageId'], $ids))));
    }

    public function date(string $name = 'date'): ?DateTimeImmutable
    {
        $raw = $this->raw($name);
        if ($raw === null || $raw === '') {
            return null;
        }

        // Drop comments like "(UTC)" / "(GMT Standard Time)".
        $clean = trim((string) preg_replace('/\([^)]*\)/', '', $raw));

        try {
            return (new DateTimeImmutable($clean))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    // ── Static helpers ───────────────────────────────────────────────

    public static function normalizeMessageId(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }
        $id = trim($id);
        $id = trim($id, '<> ');

        return $id === '' ? null : $id;
    }

    /** RFC 2047 encoded words to UTF-8; invalid bytes are scrubbed. */
    public static function decodeWords(string $value): string
    {
        $decoded = $value;
        if (str_contains($value, '=?')) {
            // Whitespace between adjacent encoded words is not significant.
            $value = (string) preg_replace('/(\?=)\s+(=\?)/', '$1$2', $value);
            $result = function_exists('iconv_mime_decode')
                ? @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8')
                : false;
            $decoded = is_string($result) ? $result : mb_decode_mimeheader($value);
        }

        if (! mb_check_encoding($decoded, 'UTF-8')) {
            $decoded = mb_convert_encoding($decoded, 'UTF-8', 'ISO-8859-1');
        }

        return trim($decoded);
    }

    /**
     * @return list<array{email: string, name: string}>
     */
    public static function parseAddressList(string $value): array
    {
        $out = [];
        foreach (self::splitAddresses($value) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            // Group syntax "Team: a@x, b@y;" - strip the display name.
            if (preg_match('/^[^"<>@]*:\s*(.*?);?$/s', $part, $g) && ! str_contains(strtok($part, ':') ?: '', '@')) {
                foreach (self::parseAddressList($g[1]) as $member) {
                    $out[] = $member;
                }

                continue;
            }

            $name = '';
            if (preg_match('/<([^<>]*)>/', $part, $m)) {
                $email = $m[1];
                $name = trim(str_replace($m[0], '', $part));
            } else {
                $email = $part;
                if (preg_match('/\(([^)]*)\)/', $email, $c)) {
                    $name = $c[1];
                    $email = str_replace($c[0], '', $email);
                }
            }

            $email = strtolower(trim($email, " \t\"'"));
            if (! str_contains($email, '@')) {
                continue;
            }

            $name = trim($name);
            if (strlen($name) >= 2 && $name[0] === '"' && str_ends_with($name, '"')) {
                $name = stripcslashes(substr($name, 1, -1));
            }
            $name = self::decodeWords($name);
            if (strcasecmp($name, $email) === 0) {
                $name = '';
            }

            $out[] = ['email' => $email, 'name' => $name];
        }

        return $out;
    }

    /**
     * Split on commas that are outside quotes, angle brackets and comments.
     *
     * @return list<string>
     */
    private static function splitAddresses(string $value): array
    {
        $parts = [];
        $buf = '';
        $quoted = false;
        $angle = 0;
        $paren = 0;
        $len = strlen($value);

        for ($i = 0; $i < $len; $i++) {
            $c = $value[$i];
            if ($quoted) {
                if ($c === '\\' && $i + 1 < $len) {
                    $buf .= $c.$value[++$i];

                    continue;
                }
                if ($c === '"') {
                    $quoted = false;
                }
                $buf .= $c;

                continue;
            }

            match ($c) {
                '"' => $quoted = true,
                '<' => $angle++,
                '>' => $angle = max(0, $angle - 1),
                '(' => $paren++,
                ')' => $paren = max(0, $paren - 1),
                default => null,
            };

            if (($c === ',' || $c === ';') && $angle === 0 && $paren === 0 && ! self::insideGroup($buf)) {
                $parts[] = $buf;
                $buf = '';

                continue;
            }
            $buf .= $c;
        }
        $parts[] = $buf;

        return $parts;
    }

    /** True while inside "Group: a, b;" so its commas do not split. */
    private static function insideGroup(string $buf): bool
    {
        $colon = strpos($buf, ':');
        if ($colon === false) {
            return false;
        }
        $before = substr($buf, 0, $colon);

        return ! str_contains($before, '@') && ! str_contains($before, '<') && ! str_contains($before, '"')
            && ! str_contains(substr($buf, $colon), ';'); // ";" closes the group
    }
}
