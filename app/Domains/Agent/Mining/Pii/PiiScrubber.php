<?php

namespace App\Domains\Agent\Mining\Pii;

/**
 * Removes personal identifiers from mined text and checks the result.
 *
 *   scrub()      replaces emails, phone numbers, street addresses, order
 *                numbers, tracking numbers and the given customer names with
 *                placeholders ([email], [phone], [address], [order],
 *                [tracking], [name]).
 *   violations() a deliberately broader post-check on the result: anything
 *                that still looks like one of those (an "@" address, a run of
 *                nine or more digits, "#123", a token carrying eight digits, a
 *                street address, a listed name) is reported.
 *   clean()      scrub + post-check; null when the post-check still fails,
 *                so the caller drops the item and counts the reason.
 *
 * Names shorter than three letters, and names that are ordinary English
 * words ("Will", "May", "Grace"...), are not scrubbed or checked: replacing
 * every "will" would wreck the text. Staff names are not customer names and
 * are left alone (sign-offs are part of the voice).
 */
final class PiiScrubber
{
    public const EMAIL = 'email';

    public const PHONE = 'phone';

    public const ADDRESS = 'address';

    public const ORDER = 'order_number';

    public const TRACKING = 'tracking_number';

    public const NAME = 'customer_name';

    private const PLACEHOLDERS = [
        '[email]' => self::EMAIL,
        '[phone]' => self::PHONE,
        '[address]' => self::ADDRESS,
        '[order]' => self::ORDER,
        '[tracking]' => self::TRACKING,
        '[name]' => self::NAME,
    ];

    /** Given names / surnames that are also common words. */
    private const COMMON_WORDS = [
        'will', 'may', 'june', 'april', 'august', 'grace', 'mark', 'bill', 'rose', 'joy', 'hope', 'faith', 'summer', 'dawn',
        'ray', 'guy', 'art', 'sky', 'star', 'love', 'rich', 'chance', 'sunny', 'justice', 'cash', 'major', 'king', 'prince',
        'young', 'little', 'green', 'white', 'brown', 'black', 'long', 'short', 'hill', 'wood', 'woods', 'park', 'lee', 'page',
        'day', 'case', 'hall', 'west', 'north', 'south', 'east', 'cook', 'baker', 'mason', 'carter', 'hunter', 'miles',
        'frank', 'earnest', 'ernest', 'pat', 'sue', 'don', 'dean', 'drew', 'jack', 'max', 'sam', 'rob', 'nick', 'jordan',
        'thanks', 'hello', 'support', 'team', 'customer', 'order', 'store', 'shop', 'info', 'sales', 'admin', 'contact',
        'service', 'help', 'mail', 'email', 'noreply', 'reply', 'the', 'and', 'you', 'your', 'dear', 'mate',
        'post', 'price', 'express', 'parcel', 'ship', 'courier', 'dial', 'soul', 'gaming', 'games', 'gamer', 'australia',
    ];

    private const STREET_TYPES = 'Street|St|Road|Rd|Avenue|Ave|Drive|Dr|Court|Ct|Place|Pl|Lane|Ln|Crescent|Cres|Boulevard|Blvd|Parade|Pde|Terrace|Tce|Way|Close|Highway|Hwy|Circuit|Cct|Grove|Esplanade|Esp|Square|Sq|Mews';

    /** @var list<string> */
    private readonly array $orderPrefixes;

    /** @param  list<string>|null  $orderPrefixes */
    public function __construct(?array $orderPrefixes = null)
    {
        $prefixes = $orderPrefixes ?? (array) config('agent_mining.pii.order_prefixes', ['SD']);
        $this->orderPrefixes = array_values(array_filter(array_map(
            fn ($p) => preg_replace('/[^A-Za-z]/', '', (string) $p) ?? '',
            $prefixes,
        ), fn ($p) => $p !== ''));
    }

    /** @param  list<string>  $names */
    public function scrub(string $text, array $names = []): string
    {
        // Emails first: they contain digits and dots that other patterns would split.
        $text = $this->replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[email]', $text);

        // Tracking numbers before orders and phones (longer, more specific).
        $text = $this->replace('/\b4PX\d{8,}[A-Z]{0,2}\b/iu', '[tracking]', $text);
        $text = $this->replace('/\b1Z[0-9A-Z]{16}\b/iu', '[tracking]', $text);
        $text = $this->replace('/\b[A-Z]{2}\d{9}[A-Z]{2}\b/iu', '[tracking]', $text);
        $text = $this->replace('/\b(?=(?:[A-Z]*\d){8})(?=[A-Z0-9]*[A-Z])[A-Z0-9]{10,34}\b/iu', '[tracking]', $text);
        $text = $this->replace('/\b\d{12,22}\b/u', '[tracking]', $text);

        // Order numbers: "#1234", store prefix + digits, "order 1234".
        $text = $this->replace('/(?<![\w&])#\s?\d{3,}\b/u', '[order]', $text);
        if ($this->orderPrefixes !== []) {
            $alt = implode('|', array_map(fn ($p) => preg_quote($p, '/'), $this->orderPrefixes));
            $text = $this->replace('/\b(?:'.$alt.')[-\s]?\d{3,}\b/iu', '[order]', $text);
        }
        $text = preg_replace(
            '/\b(order|ord|invoice|reference|ref)(\s*(?:no\.?|number|num|#|:)?\s*)#?\d{4,}\b/iu',
            '$1 [order]',
            $text,
        ) ?? $text;

        // Phone numbers: any run of digits and separators with 9 to 15 digits.
        $text = preg_replace_callback('/(?<![\w\/])\+?\(?\d[\d\s().\-]{7,}\d(?![\w\/])/u', function (array $m): string {
            $digits = strlen(preg_replace('/\D/', '', $m[0]) ?? '');

            return $digits >= 9 && $digits <= 15 && ! self::looksLikeDate($m[0]) ? '[phone]' : $m[0];
        }, $text) ?? $text;

        // Street addresses, PO boxes, "State 2000" and UK postcodes.
        $text = $this->replace($this->streetPattern(), '[address]', $text);
        $text = $this->replace('/\b(?:P\.?\s?O\.?\s?Box|Locked\s+Bag|GPO\s+Box)\s*\d+\b/iu', '[address]', $text);
        $text = $this->replace('/\b(?:NSW|VIC|QLD|SA|WA|TAS|NT|ACT)\s+\d{4}\b/u', '[address]', $text);
        $text = $this->replace('/\b[A-Z]{1,2}\d[A-Z\d]?\s+\d[A-Z]{2}\b/u', '[address]', $text);

        foreach ($this->nameVariants($names) as $name) {
            $text = $this->replace($this->namePattern($name), '[name]', $text);
        }

        return $text;
    }

    /**
     * What in $text still looks like an identifier. Broader than scrub().
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public function violations(string $text, array $names = []): array
    {
        $found = [];

        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9\-]+(?:\.[A-Z0-9\-]+)+/iu', $text) === 1) {
            $found[] = self::EMAIL;
        }

        if (preg_match_all('/\+?\(?\d[\d\s().\-]{6,}\d/u', $text, $m) > 0) {
            foreach ($m[0] as $span) {
                if (strlen(preg_replace('/\D/', '', $span) ?? '') >= 9 && ! self::looksLikeDate($span)) {
                    $found[] = self::PHONE;
                    break;
                }
            }
        }

        $orderPattern = '/(?<![\w&])#\s?\d{3,}|\b(?:order|invoice)\s*(?:no\.?|number|#|:)?\s*#?\d{4,}\b/iu';
        if ($this->orderPrefixes !== []) {
            $alt = implode('|', array_map(fn ($p) => preg_quote($p, '/'), $this->orderPrefixes));
            $orderPattern = '/(?<![\w&])#\s?\d{3,}|\b(?:order|invoice)\s*(?:no\.?|number|#|:)?\s*#?\d{4,}\b|\b(?:'.$alt.')[-\s]?\d{3,}\b/iu';
        }
        if (preg_match($orderPattern, $text) === 1) {
            $found[] = self::ORDER;
        }

        if (preg_match('/\b(?=(?:[A-Z]*\d){8})[A-Z0-9]{8,40}\b/iu', $text) === 1) {
            $found[] = self::TRACKING;
        }

        if (preg_match($this->streetPattern(), $text) === 1
            || preg_match('/\b(?:P\.?\s?O\.?\s?Box|Locked\s+Bag|GPO\s+Box)\s*\d+/iu', $text) === 1
            || preg_match('/\b(?:NSW|VIC|QLD|SA|WA|TAS|NT|ACT)\s+\d{4}\b/u', $text) === 1) {
            $found[] = self::ADDRESS;
        }

        foreach ($this->nameVariants($names) as $name) {
            if (preg_match($this->namePattern($name), $text) === 1) {
                $found[] = self::NAME;
                break;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Scrub, then post-check. Null (and $reason set) when the text still
     * carries an identifier after scrubbing. $strict also rejects text the
     * scrubber had to change: a rule or knowledge item that named a
     * customer, an address or an order is about one case, not a policy.
     *
     * @param  list<string>  $names
     */
    public function clean(string $text, array $names = [], ?string &$reason = null, bool $strict = false): ?string
    {
        $reason = null;
        $scrubbed = trim($this->scrub($text, $names));

        if ($strict) {
            foreach (self::PLACEHOLDERS as $placeholder => $kind) {
                if (substr_count($scrubbed, $placeholder) > substr_count($text, $placeholder)) {
                    $reason = $kind;

                    return null;
                }
            }
        }
        $violations = $this->violations($scrubbed, $names);

        if ($violations !== []) {
            $reason = $violations[0];

            return null;
        }

        return $scrubbed;
    }

    /**
     * Name parts worth scrubbing for one customer: the full name, each part of
     * it, and the letter groups of the email's local part.
     *
     * @return list<string>
     */
    public static function namesFrom(?string $name, ?string $email = null): array
    {
        $out = [];
        $name = trim((string) $name);
        if ($name !== '' && ! str_contains($name, '@')) {
            $out[] = $name;
            foreach (preg_split('/[\s,.]+/u', $name) ?: [] as $part) {
                $out[] = $part;
            }
        }

        $local = strtolower(strstr((string) $email, '@', true) ?: '');
        foreach (preg_split('/[^a-z]+/', $local) ?: [] as $part) {
            $out[] = ucfirst($part); // matched as a name is written, see namePattern()
        }

        return array_values(array_unique(array_filter($out, fn ($n) => self::usableName($n))));
    }

    /**
     * @param  list<string>  $names
     * @return list<string> longest first, so a full name goes before its parts
     */
    private function nameVariants(array $names): array
    {
        $names = array_values(array_unique(array_filter(array_map('trim', $names), fn ($n) => self::usableName($n))));
        usort($names, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return $names;
    }

    /**
     * A name matches only as a name is written: exactly as given, Title Case
     * or UPPER CASE. So a surname like "Post" or "Price" does not wipe out
     * "post" or "price" in a policy.
     */
    private function namePattern(string $name): string
    {
        $title = mb_convert_case(mb_strtolower($name), MB_CASE_TITLE, 'UTF-8');
        $forms = array_unique([$name, $title, mb_strtoupper($name)]);
        $alt = implode('|', array_map(fn ($f) => preg_replace('/\s+/u', '\s+', preg_quote($f, '/')) ?? preg_quote($f, '/'), $forms));

        return '/(?<![\p{L}\[])(?:'.$alt.')(?![\p{L}\]])/u';
    }

    /** "2026-11-26 10", "26.11.2026 9:30": a date (and time), not a phone number. */
    private static function looksLikeDate(string $span): bool
    {
        return preg_match('/\b\d{4}-\d{1,2}-\d{1,2}\b|\b\d{1,2}[.\-]\d{1,2}[.\-](?:\d{4}|\d{2})\b/', $span) === 1;
    }

    private static function usableName(string $name): bool
    {
        $name = trim($name);

        return mb_strlen($name) >= 3
            && preg_match('/^[\p{L}][\p{L}\s\'\-]*$/u', $name) === 1
            && ! in_array(mb_strtolower($name), self::COMMON_WORDS, true);
    }

    private function streetPattern(): string
    {
        // "12 Smith Street", "3/45 King St", "Unit 4, 10 Long Bay Road". The
        // street name words must be capitalised; the type is case-insensitive.
        return '/\b(?:(?i:unit|apt|apartment|suite|level|shop)\s*\d+[A-Za-z]?\s*[,\/]?\s*)?\d{1,5}[A-Za-z]?(?:\s*\/\s*\d{1,5})?\s+(?:[A-Z][A-Za-z\'\-]+\s+){1,3}(?i:'.self::STREET_TYPES.')\b\.?/u';
    }

    private function replace(string $pattern, string $with, string $text): string
    {
        return preg_replace($pattern, $with, $text) ?? $text;
    }
}
