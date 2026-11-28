<?php

namespace App\Domains\Agent\Support;

/**
 * The inbox category of a conversation (conversations.agent_category): a
 * fixed set, decided by the playbook the agent's triage chose, or by the
 * triage model itself when no playbook fits. Keys, labels and the playbook
 * map live in config/agent.php.
 */
final class Categories
{
    public const OTHER = 'other';

    /** @return array<string, string> key => label */
    public static function labels(): array
    {
        /** @var array<string, string> $labels */
        $labels = (array) config('agent.categories', []);

        return $labels + [self::OTHER => 'Other'];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::labels());
    }

    public static function isValid(mixed $key): bool
    {
        return is_string($key) && array_key_exists($key, self::labels());
    }

    /** The exact slug, then the part before the first dot, else "other". */
    public static function forPlaybook(string $slug): string
    {
        $map = (array) config('agent.playbook_categories', []);
        $category = $map[$slug] ?? $map[explode('.', $slug, 2)[0]] ?? self::OTHER;

        return self::isValid($category) ? $category : self::OTHER;
    }

    /** Whether the slug (or its prefix) is in the map at all. */
    public static function knowsPlaybook(string $slug): bool
    {
        $map = (array) config('agent.playbook_categories', []);

        return isset($map[$slug]) || isset($map[explode('.', $slug, 2)[0]]);
    }

    /**
     * A chosen playbook that is in the map always wins; otherwise (no
     * playbook, or one nobody has mapped yet) what the triage model said,
     * and anything unknown is "other".
     */
    public static function resolve(?string $playbookSlug, mixed $triageCategory): string
    {
        if ($playbookSlug !== null && self::knowsPlaybook($playbookSlug)) {
            return self::forPlaybook($playbookSlug);
        }

        return self::isValid($triageCategory) ? $triageCategory : self::OTHER;
    }
}
