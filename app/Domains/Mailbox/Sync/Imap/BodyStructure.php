<?php

namespace App\Domains\Mailbox\Sync\Imap;

/**
 * Reads an IMAP BODYSTRUCTURE (RFC 3501 7.4.2, as tokenised by
 * ImapWireParser) and works out which sections hold the readable text and
 * what the attachments are, so history can be fetched without attachment
 * bytes.
 *
 * Only multipart messages get a plan; a single-part message is fetched whole
 * by the caller (its one part is the text, or it is small enough).
 */
final class BodyStructure
{
    /**
     * @param  list<mixed>  $structure
     * @return array{text: list<array{section: string, type: string, charset: ?string, encoding: string, size: int}>, attachments: list<array{name: string, mime: string, size: int, inline: bool}>}|null
     */
    public static function plan(array $structure): ?array
    {
        if (! isset($structure[0]) || ! is_array($structure[0])) {
            return null; // single part
        }

        $leaves = [];
        self::walk($structure, '', $leaves);

        $text = [];
        $attachments = [];
        $seen = [];
        foreach ($leaves as $leaf) {
            $isText = $leaf['type'] === 'text'
                && in_array($leaf['subtype'], ['plain', 'html'], true)
                && $leaf['disposition'] !== 'attachment'
                && $leaf['name'] === null
                && ! isset($seen[$leaf['subtype']]);

            if ($isText) {
                $seen[$leaf['subtype']] = true;
                $text[] = [
                    'section' => $leaf['section'],
                    'type' => 'text/'.$leaf['subtype'],
                    'charset' => $leaf['params']['charset'] ?? null,
                    'encoding' => $leaf['encoding'],
                    'size' => $leaf['size'],
                ];

                continue;
            }

            $attachments[] = [
                'name' => $leaf['name'] ?? ($leaf['type'] === 'message' ? 'message.eml' : 'attachment'),
                'mime' => $leaf['type'].'/'.$leaf['subtype'],
                // Encoded size; base64 is 4/3 of the decoded bytes.
                'size' => $leaf['encoding'] === 'base64' ? intdiv($leaf['size'] * 3, 4) : $leaf['size'],
                'inline' => $leaf['disposition'] === 'inline' || ($leaf['disposition'] === null && $leaf['id'] !== null && $leaf['type'] === 'image'),
            ];
        }

        return ['text' => $text, 'attachments' => $attachments];
    }

    /**
     * @param  list<mixed>  $node
     * @param  list<array<string, mixed>>  $leaves
     */
    private static function walk(array $node, string $section, array &$leaves): void
    {
        if (isset($node[0]) && is_array($node[0])) {
            $i = 1;
            foreach ($node as $child) {
                if (! is_array($child)) {
                    break; // the multipart subtype and extension data follow the parts
                }
                self::walk($child, $section === '' ? (string) $i : $section.'.'.$i, $leaves);
                $i++;
            }

            return;
        }

        $type = strtolower(is_string($node[0] ?? null) ? $node[0] : 'application');
        $subtype = strtolower(is_string($node[1] ?? null) ? $node[1] : 'octet-stream');
        $params = self::params($node[2] ?? null);

        // Disposition sits after the type-specific fields (md5 first).
        $dispositionAt = match (true) {
            $type === 'text' => 9,
            $type === 'message' && $subtype === 'rfc822' => 11,
            default => 8,
        };
        $disposition = $node[$dispositionAt] ?? null;
        $dispositionType = is_array($disposition) && is_string($disposition[0] ?? null) ? strtolower($disposition[0]) : null;
        $dispositionParams = is_array($disposition) ? self::params($disposition[1] ?? null) : [];

        $name = $dispositionParams['filename'] ?? $params['name'] ?? null;

        $leaves[] = [
            'section' => $section === '' ? '1' : $section,
            'type' => $type,
            'subtype' => $subtype,
            'params' => $params,
            'id' => is_string($node[3] ?? null) ? $node[3] : null,
            'encoding' => strtolower(is_string($node[5] ?? null) ? $node[5] : '7bit'),
            'size' => is_string($node[6] ?? null) && ctype_digit($node[6]) ? (int) $node[6] : 0,
            'disposition' => $dispositionType,
            'name' => $name !== null && $name !== '' ? $name : null,
        ];
    }

    /** @return array<string, string> lower-cased keys, RFC 2231 / 2047 values decoded */
    private static function params(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }

        $out = [];
        for ($i = 0; $i + 1 < count($list); $i += 2) {
            if (! is_string($list[$i]) || ! is_string($list[$i + 1])) {
                continue;
            }
            $key = strtolower($list[$i]);
            $value = $list[$i + 1];

            if (str_ends_with($key, '*')) {
                // RFC 2231: charset'language'percent-encoded
                $key = rtrim($key, '*');
                $parts = explode("'", $value, 3);
                $value = rawurldecode($parts[2] ?? $value);
            } elseif (str_contains($value, '=?')) {
                $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
                $value = $decoded !== false ? $decoded : $value;
            }

            $out[$key] = $value;
        }

        return $out;
    }
}
