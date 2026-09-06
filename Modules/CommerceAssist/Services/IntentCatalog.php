<?php

namespace Modules\CommerceAssist\Services;

use Modules\CommerceAssist\Models\CommerceIntent;

class IntentCatalog
{
    /** @return list<array<string, mixed>> */
    public static function defaults(): array
    {
        /** @var list<array<string, mixed>> $defaults */
        $defaults = config('commerce-assist.intents', []);

        return $defaults;
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_values(array_filter(array_map(
            fn (array $intent) => (string) ($intent['slug'] ?? ''),
            self::defaults(),
        )));
    }

    public static function ensureForWorkspace(int $workspaceId): void
    {
        foreach (self::defaults() as $intent) {
            CommerceIntent::firstOrCreate(
                [
                    'workspace_id' => $workspaceId,
                    'slug' => $intent['slug'],
                ],
                [
                    'parent_slug' => $intent['parent_slug'] ?? null,
                    'name' => $intent['name'],
                    'rules' => $intent['rules'],
                    'data_requirements' => $intent['data_requirements'] ?? [],
                    'always_human' => (bool) ($intent['always_human'] ?? false),
                    'auto_send_allowed' => (bool) ($intent['auto_send_allowed'] ?? false),
                    'sort_order' => (int) ($intent['sort_order'] ?? 0),
                ],
            );
        }
    }

    public static function find(int $workspaceId, ?string $slug): ?CommerceIntent
    {
        self::ensureForWorkspace($workspaceId);

        if (! filled($slug)) {
            return CommerceIntent::where('workspace_id', $workspaceId)->where('slug', 'other')->first();
        }

        return CommerceIntent::where('workspace_id', $workspaceId)->where('slug', $slug)->first()
            ?? CommerceIntent::where('workspace_id', $workspaceId)->where('slug', 'other')->first();
    }

    public static function rulesFor(int $workspaceId, ?string $intent, ?string $subtype): string
    {
        $parts = [];

        if ($intent) {
            $parent = self::find($workspaceId, $intent);
            if ($parent) {
                $parts[] = $parent->name.":\n".$parent->rules;
            }
        }

        if ($subtype && $subtype !== $intent) {
            $child = self::find($workspaceId, $subtype);
            if ($child) {
                $parts[] = $child->name.":\n".$child->rules;
            }
        }

        return implode("\n\n", $parts) ?: 'No intent-specific rules. Stay within verified data.';
    }
}
