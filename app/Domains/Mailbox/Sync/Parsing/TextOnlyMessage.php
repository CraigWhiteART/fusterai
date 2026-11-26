<?php

namespace App\Domains\Mailbox\Sync\Parsing;

/**
 * Builds a small stand-in RFC 822 message from a message's header block and
 * its text parts only, so MessageParser can decode it exactly like a full
 * message (charsets, transfer encodings, quote stripping) without the
 * attachment bytes ever having been downloaded.
 */
final class TextOnlyMessage
{
    /** Headers that describe the original MIME tree and must not survive. */
    private const DROP = ['content-type', 'content-transfer-encoding', 'mime-version', 'content-disposition'];

    /**
     * @param  list<array{type: string, charset: ?string, encoding: string, body: string}>  $parts  still transfer-encoded, as on the wire
     */
    public static function build(string $headerBlock, array $parts): string
    {
        $boundary = 'fusterai-text-only-'.bin2hex(random_bytes(6));

        $raw = self::withoutMimeHeaders($headerBlock)
            ."MIME-Version: 1.0\r\n"
            .'Content-Type: multipart/alternative; boundary="'.$boundary."\"\r\n"
            ."\r\n";

        if ($parts === []) {
            $parts = [['type' => 'text/plain', 'charset' => 'utf-8', 'encoding' => '7bit', 'body' => '']];
        }

        foreach ($parts as $part) {
            $type = $part['type'].($part['charset'] !== null && $part['charset'] !== '' ? '; charset="'.addcslashes($part['charset'], '"\\').'"' : '');
            $raw .= "--{$boundary}\r\n"
                ."Content-Type: {$type}\r\n"
                .'Content-Transfer-Encoding: '.$part['encoding']."\r\n"
                ."\r\n"
                .rtrim($part['body'], "\r\n")."\r\n";
        }

        return $raw."--{$boundary}--\r\n";
    }

    /** The header block without MIME structure headers (folded lines included), CRLF-terminated, no blank line. */
    public static function withoutMimeHeaders(string $headerBlock): string
    {
        $lines = preg_split('/\r\n|\n/', $headerBlock) ?: [];
        $out = '';
        $skipping = false;
        foreach ($lines as $line) {
            if ($line === '') {
                continue; // end of header block (or stray blank)
            }
            if ($line[0] === ' ' || $line[0] === "\t") {
                if (! $skipping) {
                    $out .= $line."\r\n";
                }

                continue;
            }
            $name = strtolower(trim(explode(':', $line, 2)[0]));
            $skipping = in_array($name, self::DROP, true);
            if (! $skipping) {
                $out .= $line."\r\n";
            }
        }

        return $out;
    }
}
