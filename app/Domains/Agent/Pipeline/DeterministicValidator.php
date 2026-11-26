<?php

namespace App\Domains\Agent\Pipeline;

use App\Domains\Commerce\Actions\Support\ActionClaims;
use Carbon\CarbonImmutable;

/**
 * Code checks that run before the LLM validator. Every date, money amount,
 * URL, discount code, order number and tracking number in the draft must be
 * grounded in the evidence ledger or in knowledge the draft cites; links must
 * point at allowlisted domains; voice "don't" phrases and length limits hold.
 * Pure and deterministic: no I/O.
 */
class DeterministicValidator
{
    private const MONTHS = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';

    private const WEEKDAYS = 'monday|tuesday|wednesday|thursday|friday|saturday|sunday';

    public const HARD_MAX_CHARS = 5000;

    /**
     * @param  list<array<string, mixed>>  $claims
     * @param  array<string, array{id: string, kind: string, title: string|null, body: string, scope: array<string, mixed>}>  $knowledgeSeen
     * @param  list<string>  $allowedDomains
     * @return list<array{check: string, value: string, problem: string}>
     */
    public function check(
        string $draft,
        array $claims,
        EvidenceLedger $ledger,
        array $knowledgeSeen,
        VoiceSpec $voice,
        array $allowedDomains,
    ): array {
        $issues = [];
        $draft = trim($draft);

        if ($draft === '') {
            return [['check' => 'empty', 'value' => '', 'problem' => 'The draft is empty.']];
        }

        // Grounding corpus: the ledger plus knowledge the draft actually cites.
        $cited = [];
        foreach ($claims as $claim) {
            foreach ((array) ($claim['sources'] ?? []) as $source) {
                $source = (string) $source;
                if (! $ledger->has($source) && ! isset($knowledgeSeen[$source])) {
                    $issues[] = ['check' => 'unknown_source', 'value' => $source, 'problem' => "A claim cites {$source}, which is not in the evidence or retrieved knowledge."];
                }
                if (isset($knowledgeSeen[$source])) {
                    $cited[$source] = $knowledgeSeen[$source];
                }
            }
        }
        $corpus = $ledger->corpus()."\n".implode("\n", array_map(fn ($k) => ($k['title'] ?? '').' '.$k['body'], $cited));
        $corpusLower = mb_strtolower($corpus);
        $corpusCompact = $this->compact($corpus);

        foreach ($this->urls($draft) as $url) {
            $host = $this->host($url);
            if ($host === null || ! $this->domainAllowed($host, $allowedDomains)) {
                $issues[] = ['check' => 'link_domain', 'value' => $url, 'problem' => "The link {$url} is not on an allowed domain."];
            } elseif (! str_contains($corpusLower, mb_strtolower(rtrim($url, '/')))) {
                $issues[] = ['check' => 'link', 'value' => $url, 'problem' => "The link {$url} does not appear in the evidence or cited knowledge."];
            }
        }

        foreach ($this->moneyAmounts($draft) as $amount) {
            if (! $this->amountGrounded($amount, $corpus)) {
                $issues[] = ['check' => 'money', 'value' => $amount, 'problem' => "The amount {$amount} does not appear in the evidence or cited knowledge."];
            }
        }

        foreach ($this->percentOffers($draft) as $percent) {
            if (! str_contains($corpusLower, mb_strtolower($percent))) {
                $issues[] = ['check' => 'money', 'value' => $percent, 'problem' => "The {$percent} offer does not appear in the evidence or cited knowledge."];
            }
        }

        $groundDates = $this->dateIndex($corpus);
        foreach ($this->dates($draft) as [$raw, $monthDay]) {
            if ($monthDay === null || ! in_array($monthDay, $groundDates['month_days'], true)) {
                $issues[] = ['check' => 'date', 'value' => $raw, 'problem' => "The date \"{$raw}\" does not appear in the evidence or cited knowledge."];
            }
        }
        foreach ($this->weekdays($draft) as $weekday) {
            if (! str_contains($corpusLower, $weekday) && ! in_array($weekday, $groundDates['weekdays'], true)) {
                $issues[] = ['check' => 'date', 'value' => $weekday, 'problem' => "\"{$weekday}\" is a date promise not supported by the evidence."];
            }
        }
        if (preg_match('/\btomorrow\b/i', $draft) === 1 && ! str_contains($corpusLower, 'tomorrow')) {
            $issues[] = ['check' => 'date', 'value' => 'tomorrow', 'problem' => '"tomorrow" is a date promise not supported by the evidence.'];
        }

        foreach ($this->discountCodes($draft) as $code) {
            if (! str_contains($corpusCompact, $this->compact($code))) {
                $issues[] = ['check' => 'discount_code', 'value' => $code, 'problem' => "The code {$code} does not appear in the evidence or cited knowledge."];
            }
        }

        foreach ($this->identifiers($draft) as $identifier) {
            if (! str_contains($corpusCompact, $this->compact($identifier))) {
                $issues[] = ['check' => 'identifier', 'value' => $identifier, 'problem' => "The order/tracking number {$identifier} does not appear in the evidence."];
            }
        }

        $draftLower = mb_strtolower($draft);
        foreach ($voice->dontTexts() as $phrase) {
            if (mb_strlen($phrase) >= 4 && str_contains($draftLower, mb_strtolower($phrase))) {
                $issues[] = ['check' => 'voice', 'value' => $phrase, 'problem' => "The draft uses a phrase the voice forbids: \"{$phrase}\"."];
            }
        }

        $words = str_word_count(strip_tags($draft));
        if ($voice->maxWords !== null && $words > (int) ceil($voice->maxWords * 1.25)) {
            $issues[] = ['check' => 'length', 'value' => (string) $words, 'problem' => "The draft has {$words} words; the voice allows about {$voice->maxWords}."];
        }
        if (mb_strlen($draft) > self::HARD_MAX_CHARS) {
            $issues[] = ['check' => 'length', 'value' => (string) mb_strlen($draft), 'problem' => 'The draft is far too long.'];
        }

        // ── Phase 9: no "I have refunded/cancelled/updated..." without that action proposed (docs/agent-actions.md) ──
        array_push($issues, ...ActionClaims::unbacked($draft, $ledger));
        // ── end Phase 9 ──

        return $this->unique($issues);
    }

    /** @return list<string> */
    public function urls(string $text): array
    {
        preg_match_all('~\b(?:https?://|www\.)[^\s<>"\')\]]+~i', $text, $m);

        return array_values(array_unique(array_map(fn ($u) => rtrim($u, '.,;:!?'), $m[0])));
    }

    /** @return list<string> */
    public function moneyAmounts(string $text): array
    {
        $pattern = '~(?:(?:A|AU|US|NZ|C|CA)?\$|AUD\s?|USD\s?|NZD\s?|€|£)\s?\d[\d,]*(?:\.\d{1,2})?|\b\d[\d,]*(?:\.\d{1,2})?\s?(?:dollars|aud|usd|nzd|eur|euros|gbp|pounds)\b~i';
        preg_match_all($pattern, $text, $m);

        return array_values(array_unique(array_map('trim', $m[0])));
    }

    /** @return list<string> Percentages offered as refunds/discounts/credit. */
    public function percentOffers(string $text): array
    {
        preg_match_all('~\b\d{1,3}\s?%(?=[^.\n]{0,25}\b(?:off|discount|refund|credit|back)\b)|(?<=refund of |discount of |credit of )\d{1,3}\s?%~i', $text, $m);

        return array_values(array_unique(array_map(fn ($p) => str_replace(' ', '', $p), $m[0])));
    }

    /** @return list<array{0: string, 1: string|null}> [raw text, "m-d" or null when unparseable] */
    public function dates(string $text): array
    {
        $found = [];
        $months = self::MONTHS;

        if (preg_match_all("~\b(\d{1,2})(?:st|nd|rd|th)?\s+(?:of\s+)?({$months})\.?(?:,?\s+(\d{4}))?\b~i", $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $found[] = [$match[0], $this->monthDay($match[2], (int) $match[1])];
            }
        }
        if (preg_match_all("~\b({$months})\.?\s+(\d{1,2})(?:st|nd|rd|th)?(?:,?\s+(\d{4}))?\b~i", $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $found[] = [$match[0], $this->monthDay($match[1], (int) $match[2])];
            }
        }
        if (preg_match_all('~\b(\d{4})-(\d{2})-(\d{2})\b~', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $found[] = [$match[0], sprintf('%02d-%02d', (int) $match[2], (int) $match[3])];
            }
        }
        // Numeric d/m/y (Australian order); also accept m/d when unambiguous in the evidence.
        if (preg_match_all('~\b(\d{1,2})/(\d{1,2})/(\d{2,4})\b~', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $found[] = [$match[0], sprintf('%02d-%02d', (int) $match[2], (int) $match[1])];
            }
        }

        return $found;
    }

    /** @return list<string> */
    public function weekdays(string $text): array
    {
        preg_match_all('~\b('.self::WEEKDAYS.')\b~i', $text, $m);

        return array_values(array_unique(array_map('strtolower', $m[1])));
    }

    /** @return list<string> */
    public function discountCodes(string $text): array
    {
        preg_match_all('~\b(?:code|coupon|promo(?:\s+code)?|voucher)\b\s*[:\-]?\s*["\'“]?([A-Z0-9][A-Z0-9_-]{3,})\b~i', $text, $m);
        $codes = array_filter($m[1], fn ($code) => preg_match('/[A-Z]/', $code) === 1 && strtoupper($code) === $code);

        return array_values(array_unique($codes));
    }

    /**
     * Order numbers (#1234, SD1234) and tracking-number-shaped tokens
     * (10+ alphanumerics containing digits).
     *
     * @return list<string>
     */
    public function identifiers(string $text): array
    {
        $found = [];
        $withoutUrls = preg_replace('~\b(?:https?://|www\.)\S+~i', ' ', $text) ?? $text;

        if (preg_match_all('~#\s?([A-Z]{0,6}\d{3,})\b~i', $withoutUrls, $m)) {
            $found = array_merge($found, $m[1]);
        }
        if (preg_match_all('~\b([A-Z]{2,6}\d{3,}[A-Z]{0,4})\b~', $withoutUrls, $m)) {
            $found = array_merge($found, $m[1]);
        }
        if (preg_match_all('~\b(?=[A-Z0-9]*\d)[A-Z0-9]{10,34}\b~', $withoutUrls, $m)) {
            $found = array_merge($found, $m[0]);
        }

        return array_values(array_unique(array_map('strtoupper', $found)));
    }

    /** @param  list<string>  $allowed */
    public function domainAllowed(string $host, array $allowed): bool
    {
        $host = strtolower(preg_replace('/^www\./', '', $host) ?? $host);
        foreach ($allowed as $domain) {
            $domain = strtolower(trim(preg_replace('#^(https?://)?(www\.)?#', '', $domain) ?? $domain, '/ '));
            if ($domain !== '' && ($host === $domain || str_ends_with($host, '.'.$domain))) {
                return true;
            }
        }

        return false;
    }

    private function host(string $url): ?string
    {
        $url = preg_match('~^https?://~i', $url) === 1 ? $url : 'https://'.$url;
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    private function amountGrounded(string $amount, string $corpus): bool
    {
        $value = $this->number($amount);
        if ($value === null) {
            return false;
        }

        preg_match_all('~\d[\d,]*(?:\.\d{1,2})?~', $corpus, $m);
        foreach ($m[0] as $candidate) {
            if ($this->number($candidate) === $value) {
                return true;
            }
        }

        return false;
    }

    private function number(string $text): ?string
    {
        if (preg_match('~\d[\d,]*(?:\.\d{1,2})?~', $text, $m) !== 1) {
            return null;
        }

        return number_format((float) str_replace(',', '', $m[0]), 2, '.', '');
    }

    /** @return array{month_days: list<string>, weekdays: list<string>} */
    private function dateIndex(string $corpus): array
    {
        $monthDays = [];
        $weekdays = [];

        foreach ($this->dates($corpus) as [, $md]) {
            if ($md !== null) {
                $monthDays[] = $md;
            }
        }
        // ISO timestamps from Shopify / carriers also cover the weekday they fall on.
        if (preg_match_all('~\b(\d{4})-(\d{2})-(\d{2})(?:[T ][\d:.]+(?:Z|[+-]\d{2}:?\d{2})?)?~', $corpus, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $monthDays[] = sprintf('%02d-%02d', (int) $match[2], (int) $match[3]);
                try {
                    $weekdays[] = strtolower(CarbonImmutable::create((int) $match[1], (int) $match[2], (int) $match[3])?->format('l') ?? '');
                } catch (\Throwable) {
                    // Not a real date: ignore.
                }
            }
        }

        return ['month_days' => array_values(array_unique($monthDays)), 'weekdays' => array_values(array_unique(array_filter($weekdays)))];
    }

    private function monthDay(string $month, int $day): ?string
    {
        $index = [
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
            'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
        ][strtolower(substr($month, 0, 3))] ?? null;

        if ($index === null || $day < 1 || $day > 31) {
            return null;
        }

        return sprintf('%02d-%02d', $index, $day);
    }

    private function compact(string $text): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $text) ?? $text);
    }

    /**
     * @param  list<array{check: string, value: string, problem: string}>  $issues
     * @return list<array{check: string, value: string, problem: string}>
     */
    private function unique(array $issues): array
    {
        $seen = [];
        $out = [];
        foreach ($issues as $issue) {
            $key = $issue['check'].'|'.$issue['value'];
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $issue;
            }
        }

        return $out;
    }
}
