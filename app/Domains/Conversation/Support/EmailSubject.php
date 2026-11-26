<?php

namespace App\Domains\Conversation\Support;

final class EmailSubject
{
    /** Strip leading Re:/Fwd:/Fw: prefixes (any combination) for a conversation subject. */
    public static function normalize(?string $subject): string
    {
        $subject = (string) $subject;
        do {
            $prev = $subject;
            $subject = (string) preg_replace('/^(fwd?|re)\s*:\s*/i', '', trim($subject));
        } while ($subject !== $prev);

        return $subject !== '' ? $subject : '(No Subject)';
    }
}
