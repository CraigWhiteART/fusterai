<?php

namespace App\Domains\Commerce\Actions\Support;

/**
 * Money as integer cents, so limits and refundable checks never compare
 * floats. Shopify sends and accepts decimal strings.
 */
final class Money
{
    public static function cents(mixed $amount): ?int
    {
        if ($amount === null || $amount === '') {
            return null;
        }
        if (is_int($amount)) {
            return $amount * 100;
        }

        $text = trim(str_replace(',', '', (string) $amount));
        if (preg_match('/^-?\d+(?:\.\d+)?$/', $text) !== 1) {
            return null;
        }

        $negative = str_starts_with($text, '-');
        $text = ltrim($text, '-');
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');
        $fraction = substr(str_pad($fraction, 3, '0'), 0, 3);
        // Round half up on the third decimal.
        $cents = ((int) $whole) * 100 + intdiv((int) $fraction + 5, 10);

        return $negative ? -$cents : $cents;
    }

    public static function decimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function format(int $cents, ?string $currency): string
    {
        return trim(($currency ?? '').' '.self::decimal($cents));
    }
}
