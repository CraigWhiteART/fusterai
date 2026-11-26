<?php

namespace App\Domains\Agent\Mining;

/**
 * Canned replies ("macros"): the same staff reply sent to many customers.
 * A reply is normalised (greeting line and sign-off dropped, case, digits,
 * punctuation and spacing folded, names and numbers gone with them) and
 * hashed. A conversation whose single human reply hashes like an already
 * extracted one is counted as a use of that macro instead of being sent to
 * the model again.
 */
final class MacroDetector
{
    /** Normalised replies shorter than this are too generic to be a macro ("Thanks!"). */
    public const MIN_CHARS = 80;

    private const GREETING = '/^\s*(hi|hey|hello|hiya|dear|good (morning|afternoon|evening)|g\'day|greetings)\b.{0,60}$/iu';

    private const SIGN_OFF = '/^\s*(cheers|thanks|thank you|many thanks|kind regards|regards|best|best regards|warm regards|all the best|take care|talk soon|sincerely)\b[\s,.!]*.{0,30}$/iu';

    public static function hash(string $reply): ?string
    {
        $normalised = self::normalise($reply);

        return mb_strlen($normalised) >= self::MIN_CHARS ? sha1($normalised) : null;
    }

    public static function normalise(string $reply): string
    {
        $lines = preg_split('/\R/u', trim($reply)) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== ''));

        if ($lines !== [] && preg_match(self::GREETING, $lines[0]) === 1) {
            array_shift($lines);
        }
        foreach ($lines as $i => $line) {
            if ($i > 0 && preg_match(self::SIGN_OFF, $line) === 1) {
                $lines = array_slice($lines, 0, $i);
                break;
            }
        }

        $text = mb_strtolower(implode(' ', $lines));
        $text = preg_replace('/\S+@\S+/u', ' ', $text) ?? $text;
        $text = preg_replace('/[^\p{L}\s]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
