<?php

namespace App\Domains\Agent\Mining;

use App\Domains\Agent\Models\Playbook;

/** The workspace's playbooks as the mining prompts list them (human-approved content only). */
class PlaybookCatalogue
{
    /** @return list<array{id: int, slug: string, name: string, when_text: string}> */
    public function forWorkspace(int $workspaceId): array
    {
        return Playbook::query()
            ->with('liveVersion')
            ->where('workspace_id', $workspaceId)
            ->whereNull('archived_at')
            ->orderByDesc('priority')
            ->orderBy('slug')
            ->get()
            ->map(fn (Playbook $p) => [
                'id' => $p->id,
                'slug' => $p->slug,
                'name' => $p->name,
                'when_text' => (string) ($p->liveVersion->when_text ?? $p->name),
            ])
            ->values()
            ->all();
    }

    /** Lower-case, dots kept (playbook slugs use them), everything else folded to "_". */
    public static function normaliseLabel(string $label): string
    {
        $label = mb_strtolower(trim($label));
        $label = preg_replace('/[^a-z0-9.]+/', '_', $label) ?? $label;
        $label = trim(preg_replace('/_+/', '_', $label) ?? $label, '_.');

        return mb_substr($label === '' ? 'unlabelled' : $label, 0, 64);
    }

    /** Comparison key: dots and underscores are the same, word order ignored, trailing plural s dropped. */
    public static function looseKey(string $label): string
    {
        $words = preg_split('/[._]+/', self::normaliseLabel($label)) ?: [];
        $stop = ['the', 'a', 'an', 'of', 'for', 'to', 'and', 'or', 'request', 'question', 'issue', 'enquiry', 'inquiry'];
        $words = array_values(array_filter(array_map(
            fn ($w) => strlen($w) > 3 ? preg_replace('/s$/', '', $w) : $w,
            $words,
        ), fn ($w) => $w !== '' && ! in_array($w, $stop, true)));
        sort($words);

        return implode('_', $words) ?: self::normaliseLabel($label);
    }
}
