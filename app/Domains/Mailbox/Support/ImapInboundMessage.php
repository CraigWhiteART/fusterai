<?php

namespace App\Domains\Mailbox\Support;

use Webklex\PHPIMAP\Message;

class ImapInboundMessage
{
    public const MAX_ATTACHMENT_BYTES = 2_000_000;

    public static function toPayload(Message $message): array
    {
        return [
            'message_id' => $message->getMessageId()->first() ?? '',
            'subject' => (string) $message->getSubject()->first(),
            'from_email' => $message->getFrom()->first()->mail ?? '',
            'from_name' => $message->getFrom()->first()->personal ?? '',
            'body_html' => $message->hasHTMLBody() ? $message->getHTMLBody() : '',
            'body_text' => $message->hasTextBody() ? $message->getTextBody() : '',
            'in_reply_to' => $message->getInReplyTo()->first() ?? '',
            'references' => $message->getReferences()->first() ?? '',
            'attachments' => self::attachments($message),
            'cc' => self::cc($message),
            'headers' => [
                'auto_submitted' => self::headerValue($message, 'Auto-Submitted'),
                'x_auto_response_suppress' => self::headerValue($message, 'X-Auto-Response-Suppress'),
                'precedence' => self::headerValue($message, 'Precedence'),
                'x_fusterai_auto_reply' => self::headerValue($message, 'X-FusterAI-AutoReply'),
            ],
        ];
    }

    public static function headerValue(Message $message, string $name): string
    {
        $header = $message->getHeader();
        if (! $header) {
            return '';
        }

        try {
            return trim((string) $header->get($name));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @param  iterable<int, object>  $attachments
     * @return list<array{name: string, content: string, mime: string, size: int}>
     */
    public static function filterAttachments(iterable $attachments, int $maxBytes = self::MAX_ATTACHMENT_BYTES): array
    {
        $kept = [];

        foreach ($attachments as $attachment) {
            $size = (int) $attachment->getSize();
            if ($size > $maxBytes) {
                continue;
            }

            $content = $attachment->getContent();
            if (strlen($content) > $maxBytes) {
                continue;
            }

            $kept[] = [
                'name' => $attachment->getName(),
                'content' => base64_encode($content),
                'mime' => $attachment->getMimeType(),
                'size' => $size,
            ];
        }

        return $kept;
    }

    private static function attachments(Message $message): array
    {
        return self::filterAttachments($message->getAttachments());
    }

    private static function cc(Message $message): array
    {
        $cc = [];
        try {
            foreach ($message->getCC() as $address) {
                if (! empty($address->mail)) {
                    $cc[] = ['email' => $address->mail, 'name' => $address->personal ?? ''];
                }
            }
        } catch (\Throwable) {
            // Some IMAP servers return malformed CC — skip silently
        }

        return $cc;
    }
}
