<?php

namespace App\Domains\Commerce\Actions\Support;

/**
 * Strips secrets from anything stored in agent_action_executions.request /
 * response. Keys that look like credentials are replaced wholesale, and
 * token-shaped strings are masked wherever they appear.
 */
final class Redactor
{
    private const SECRET_KEY = '/(token|secret|password|authorization|api[_-]?key|access[_-]?key|cookie|credential)/i';

    private const SECRET_VALUE = '/\b(?:shp(?:at|ca|ss|pa|ua)_[A-Za-z0-9]+|sk-[A-Za-z0-9_-]{8,}|Bearer\s+[A-Za-z0-9._-]+)/';

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function redact(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY, $key) === 1 && ! in_array($key, ['idempotency_key', 'idempotencyKey'], true)) {
                $out[$key] = '[redacted]';

                continue;
            }
            $out[$key] = match (true) {
                is_array($value) => self::redact($value),
                is_string($value) => self::string($value),
                default => $value,
            };
        }

        return $out;
    }

    public static function string(string $value): string
    {
        return preg_replace(self::SECRET_VALUE, '[redacted]', $value) ?? '[redacted]';
    }
}
