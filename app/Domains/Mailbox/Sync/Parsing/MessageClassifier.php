<?php

namespace App\Domains\Mailbox\Sync\Parsing;

use App\Domains\Mailbox\Sync\Enums\MailClassification;
use App\Domains\Mailbox\Sync\Enums\MailDirection;

/**
 * Header-only first pass at "is this a human support message?". Works on an
 * envelope (no body needed) so the metadata backfill can classify the whole
 * history cheaply. Deliberately conservative: anything not clearly automated
 * is Support.
 */
final class MessageClassifier
{
    private const AUTO_SUBJECT = '/^\s*(?:auto(?:matic)?[- ]?(?:reply|response|antwort)|out of (?:the )?office|abwesenheitsnotiz|r[ée]ponse automatique|respuesta autom[áa]tica|vacation reply|on vacation|i am (?:currently )?out of)/iu';

    private const BOUNCE_SENDER = '/^(?:mailer-daemon|postmaster)@/i';

    /**
     * Senders that are machines. Contact forms also send from such addresses,
     * but with the customer in Reply-To; those stay Support.
     */
    private const NO_REPLY_LOCAL = '/^(?:no-?reply|do-?not-?reply|donotreply|noreply-[\w.-]+|mailer|notifications?|notify|alerts?|bounces?|system|automated|info-noreply)(?:[+.-][\w.-]*)?$/i';

    public function classify(ParsedMessage $message, MailDirection $direction): MailClassification
    {
        $from = $message->fromEmail();
        if ($from === null) {
            return MailClassification::Unknown;
        }

        if ($direction !== MailDirection::Inbound) {
            // Our own mail; our auto-replies are still Auto.
            return $message->header('x-fusterai-autoreply') !== null ? MailClassification::Auto : MailClassification::Support;
        }

        if ($this->isAutoReply($message)) {
            return MailClassification::Auto;
        }

        if (preg_match(self::BOUNCE_SENDER, $from)) {
            return MailClassification::Notification;
        }

        $precedence = strtolower((string) $message->header('precedence'));
        if ($message->header('list-id') !== null
            || $message->header('list-unsubscribe') !== null
            || in_array($precedence, ['bulk', 'list', 'junk'], true)) {
            return MailClassification::Bulk;
        }

        if (self::isNoReplyAddress($from)) {
            // Contact forms (Shopify, WordPress...) send as a robot with the
            // customer in Reply-To, sometimes even marked auto-generated.
            return $this->customerReplyTo($message) !== null ? MailClassification::Support : MailClassification::Notification;
        }

        $autoSubmitted = strtolower(trim((string) $message->header('auto-submitted')));
        if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
            // auto-generated (RFC 3834): order notifications, alerts.
            return MailClassification::Notification;
        }

        return MailClassification::Support;
    }

    public function isAutoReply(ParsedMessage $message): bool
    {
        $autoSubmitted = strtolower(trim((string) $message->header('auto-submitted')));
        if (str_starts_with($autoSubmitted, 'auto-replied')) {
            return true;
        }
        if ($message->header('x-autoreply') !== null || $message->header('x-autorespond') !== null) {
            return true;
        }
        if (strtolower((string) $message->header('precedence')) === 'auto_reply') {
            return true;
        }
        if ($message->header('x-fusterai-autoreply') !== null) {
            return true;
        }

        // X-Auto-Response-Suppress alone is not treated as an auto-reply: it
        // only asks *us* not to auto-respond (it is kept in headers for that),
        // and some clients set it on mail typed by a human.
        return (bool) preg_match(self::AUTO_SUBJECT, (string) $message->subject);
    }

    public static function isNoReplyAddress(string $email): bool
    {
        $local = strtolower(explode('@', $email, 2)[0]);

        return (bool) preg_match(self::NO_REPLY_LOCAL, $local) || (bool) preg_match(self::BOUNCE_SENDER, $email);
    }

    /** @return array{email: string, name: string}|null */
    private function customerReplyTo(ParsedMessage $message): ?array
    {
        foreach ($message->replyTo as $address) {
            if (strcasecmp($address['email'], (string) $message->fromEmail()) !== 0 && ! self::isNoReplyAddress($address['email'])) {
                return $address;
            }
        }

        return null;
    }
}
