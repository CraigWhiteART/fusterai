<?php

namespace App\Domains\Mailbox\Sync\Parsing;

use Throwable;
use Webklex\PHPIMAP\Message;

/**
 * Raw RFC 822 to ledger fields. Pure: no IMAP connection, no database.
 *
 * Headers are read by HeaderBlock (our own; see its docblock for why). MIME
 * decoding of the body (multipart walking, base64 / quoted-printable, charset
 * conversion, attachments) is delegated to webklex's Message::fromString(),
 * which works offline on a string. If webklex throws on a malformed message
 * the parser degrades to "headers + undecoded body as text" rather than
 * failing the sync.
 */
final class MessageParser
{
    public function __construct(private readonly QuoteStripper $stripper = new QuoteStripper) {}

    /** Header block only (envelope sync): no bodies, no attachments. */
    public function parseHeaders(string $rawHeaders): ParsedMessage
    {
        return $this->build(HeaderBlock::parse($rawHeaders));
    }

    public function parse(string $raw): ParsedMessage
    {
        $headers = HeaderBlock::parse($raw);

        [$html, $text, $attachments] = $this->decodeBody($raw);

        $html = $html !== null && trim($html) !== '' ? $html : null;
        $text = $text !== null && trim($text) !== '' ? $text : null;

        return $this->build(
            $headers,
            bodyHtml: $html,
            bodyText: $text,
            bodyClean: $this->stripper->clean($html, $text),
            attachments: $attachments,
            hasBody: true,
        );
    }

    /**
     * @param  list<array{name: string, mime: string, size: int, content_id: ?string, inline: bool, content: string}>  $attachments
     */
    private function build(
        HeaderBlock $headers,
        ?string $bodyHtml = null,
        ?string $bodyText = null,
        ?string $bodyClean = null,
        array $attachments = [],
        bool $hasBody = false,
    ): ParsedMessage {
        $messageId = $headers->messageIds('message-id')[0] ?? null;
        $synthetic = false;
        if ($messageId === null) {
            // Stable stand-in so non-Gmail rows still have a dedupe key.
            $seed = implode("\n", [
                $headers->raw('date'), $headers->raw('from'), $headers->raw('to'), $headers->raw('subject'),
            ]);
            $messageId = sha1($seed).'@missing-message-id.invalid';
            $synthetic = true;
        }

        $signals = [];
        foreach (ParsedMessage::SIGNAL_HEADERS as $name) {
            $value = $headers->text($name);
            if ($value !== null) {
                $signals[$name] = mb_substr($value, 0, 1000);
            }
        }

        $subject = $headers->text('subject');

        return new ParsedMessage(
            messageId: $messageId,
            messageIdSynthetic: $synthetic,
            inReplyTo: $headers->messageIds('in-reply-to')[0] ?? null,
            references: $headers->messageIds('references'),
            from: $headers->addresses('from')[0] ?? null,
            replyTo: $headers->addresses('reply-to'),
            to: $headers->addresses('to'),
            cc: $headers->addresses('cc'),
            subject: $subject === null ? null : (string) preg_replace('/\s+/u', ' ', $subject),
            sentAt: $headers->date(),
            headers: $signals,
            bodyHtml: $bodyHtml,
            bodyText: $bodyText,
            bodyClean: $bodyClean,
            attachments: $attachments,
            hasBody: $hasBody,
        );
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: list<array{name: string, mime: string, size: int, content_id: ?string, inline: bool, content: string}>}
     */
    private function decodeBody(string $raw): array
    {
        try {
            $message = Message::fromString($raw);
        } catch (Throwable) {
            return [null, $this->undecodedBody($raw), []];
        }

        $bodies = $message->getBodies();
        $html = isset($bodies['html']) && is_string($bodies['html']) ? $bodies['html'] : null;
        $text = isset($bodies['text']) && is_string($bodies['text']) ? $bodies['text'] : null;

        $attachments = [];
        foreach ($message->getAttachments() as $attachment) {
            $content = (string) $attachment->getContent();
            // webklex's id is the Content-ID without brackets, or a content hash
            // when the part has none (a hash never appears as cid: in the HTML).
            $id = trim((string) ($attachment->getId() ?? ''), '<> ');
            // Inline = an image the HTML body actually references via cid:.
            $inline = $id !== '' && $html !== null && stripos($html, 'cid:'.$id) !== false;
            $mime = strtolower(trim(explode(';', (string) ($attachment->content_type ?? ''))[0]));

            $attachments[] = [
                'name' => (string) ($attachment->getName() ?: 'attachment'),
                'mime' => $mime !== '' ? $mime : 'application/octet-stream',
                'size' => strlen($content),
                'content_id' => $inline ? $id : null,
                'inline' => $inline,
                'content' => $content,
            ];
        }

        return [$html, $text, $attachments];
    }

    private function undecodedBody(string $raw): ?string
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $pos = strpos($raw, "\n\n");

        return $pos === false ? null : substr($raw, $pos + 2);
    }
}
