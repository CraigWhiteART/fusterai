<?php

namespace Modules\CommerceAssist\Support;

class PlainText
{
    public static function from(?string $html): string
    {
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    public static function percentChanged(string $original, string $edited): int
    {
        $a = strtolower(self::from($original));
        $b = strtolower(self::from($edited));

        if ($a === $b) {
            return 0;
        }

        similar_text($a, $b, $percent);

        return (int) max(0, min(100, round(100 - $percent)));
    }
}
