<?php

namespace App\Domains\Agent\Manager;

use App\Domains\Agent\Models\AgentExample;
use App\Domains\Agent\Models\KnowledgeItem;
use App\Domains\Agent\Models\Playbook;

/**
 * Scope restrictions of a manager chat ("make the tone less formal, but
 * don't touch refund rules"), enforced in code on every staged change.
 *
 * Restrictions come from three places and only ever accumulate:
 *  - the human, explicitly (API `restrictions`, e.g. UI chips) - the only
 *    source that can also remove one;
 *  - a deterministic parser of each request ("don't touch / change / edit
 *    X", "leave X alone", "keep X as is", "only change X");
 *  - the model, through the add_restriction tool (tightening only).
 *
 * Shape: {exclude_playbook_ids: int[], exclude_targets: string[],
 * only_playbook_ids: int[]|null, only_targets: string[]|null, sources: []}.
 */
class ScopeGuard
{
    public const TARGETS = ['voice', 'playbook', 'knowledge', 'example'];

    private const VERBS = 'touch(?:ing)?|chang(?:e|ing)|modify(?:ing)?|edit(?:ing)?|updat(?:e|ing)|alter(?:ing)?|mess(?:ing)?\s+with|affect(?:ing)?|rewrit(?:e|ing)|adjust(?:ing)?';

    /** Words too generic to identify one playbook on their own. */
    private const GENERIC = [
        'order', 'customer', 'request', 'general', 'other', 'issue', 'question', 'status', 'policy', 'rule',
        'playbook', 'reply', 'email', 'message', 'case', 'thing', 'stuff', 'anything', 'part', 'section', 'handling',
    ];

    /** @return array<string, mixed> */
    public static function empty(): array
    {
        return ['exclude_playbook_ids' => [], 'exclude_targets' => [], 'only_playbook_ids' => null, 'only_targets' => null, 'sources' => []];
    }

    /**
     * @param  array<string, mixed>|null  $r
     * @return array<string, mixed>
     */
    public static function normalize(?array $r): array
    {
        $r ??= [];
        $ints = fn ($v) => array_values(array_unique(array_map('intval', array_filter((array) $v, fn ($x) => is_numeric($x) && (int) $x > 0))));
        $targets = fn ($v) => array_values(array_unique(array_intersect(array_map('strval', (array) $v), self::TARGETS)));

        return [
            'exclude_playbook_ids' => $ints($r['exclude_playbook_ids'] ?? []),
            'exclude_targets' => $targets($r['exclude_targets'] ?? []),
            'only_playbook_ids' => ($r['only_playbook_ids'] ?? null) === null ? null : $ints($r['only_playbook_ids']),
            'only_targets' => ($r['only_targets'] ?? null) === null ? null : $targets($r['only_targets']),
            'sources' => array_values(array_slice((array) ($r['sources'] ?? []), -30)),
        ];
    }

    /**
     * Tighten: union of exclusions, intersection of "only" lists.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $add
     * @return array<string, mixed>
     */
    public static function merge(array $base, array $add): array
    {
        $base = self::normalize($base);
        $add = self::normalize($add);
        $only = fn (?array $a, ?array $b) => $a === null ? $b : ($b === null ? $a : array_values(array_intersect($a, $b)));

        return self::normalize([
            'exclude_playbook_ids' => [...$base['exclude_playbook_ids'], ...$add['exclude_playbook_ids']],
            'exclude_targets' => [...$base['exclude_targets'], ...$add['exclude_targets']],
            'only_playbook_ids' => $only($base['only_playbook_ids'], $add['only_playbook_ids']),
            'only_targets' => $only($base['only_targets'], $add['only_targets']),
            'sources' => [...$base['sources'], ...$add['sources']],
        ]);
    }

    public static function isEmpty(array $r): bool
    {
        $r = self::normalize($r);

        return $r['exclude_playbook_ids'] === [] && $r['exclude_targets'] === [] && $r['only_playbook_ids'] === null && $r['only_targets'] === null;
    }

    /**
     * Restrictions stated in a request. Unmatched phrases are kept in
     * `sources` (matched: false) so the UI can show them.
     *
     * @return array<string, mixed>
     */
    public function parse(int $workspaceId, string $text): array
    {
        $out = self::empty();
        $playbooks = Playbook::where('workspace_id', $workspaceId)->whereNull('archived_at')->get(['id', 'slug', 'name']);
        $text = str_replace(['’', '‘'], "'", $text);
        $verbs = self::VERBS;

        $patterns = [
            'exclude' => [
                "/\\b(?:don'?t|do\\s+not|never|please\\s+don'?t|without|no)\\s+(?:{$verbs})\\s+(?<seg>[^.;!?\\n]+)/iu",
                '/\bleave\s+(?<seg>[^.;!?\n]+?)\s+(?:alone|as\s+it\s+is|as\s+they\s+are|as\s+is|unchanged|untouched)\b/iu',
                '/\bkeep\s+(?<seg>[^.;!?\n]+?)\s+(?:the\s+same|as\s+it\s+is|as\s+they\s+are|as\s+is|unchanged|untouched)\b/iu',
            ],
            'only' => [
                "/\\bonly\\s+(?:{$verbs})\\s+(?<seg>[^.;!?\\n]+)/iu",
                "/\\b(?:{$verbs})\\s+only\\s+(?<seg>[^.;!?\\n]+)/iu",
            ],
        ];

        foreach ($patterns as $kind => $list) {
            foreach ($list as $pattern) {
                if (preg_match_all($pattern, $text, $matches) === false) {
                    continue;
                }
                foreach ($matches['seg'] as $segment) {
                    $segment = (string) preg_split('/\s+(?:but|and then|however|so that|because)\s+/iu', $segment)[0];
                    [$ids, $targets] = $this->match($segment, $playbooks);
                    $matched = $ids !== [] || $targets !== [];
                    if ($kind === 'exclude') {
                        $out['exclude_playbook_ids'] = [...$out['exclude_playbook_ids'], ...$ids];
                        $out['exclude_targets'] = [...$out['exclude_targets'], ...$targets];
                    } elseif ($matched) {
                        if ($ids !== []) {
                            $out['only_playbook_ids'] = [...($out['only_playbook_ids'] ?? []), ...$ids];
                            $out['only_targets'] = [...($out['only_targets'] ?? []), 'playbook', ...$targets];
                        } else {
                            $out['only_targets'] = [...($out['only_targets'] ?? []), ...$targets];
                        }
                    }
                    $out['sources'][] = ['from' => 'parser', 'kind' => $kind, 'text' => mb_substr(trim($segment), 0, 200), 'matched' => $matched,
                        'playbook_ids' => $ids, 'targets' => $targets, 'at' => now()->toIso8601String()];
                }
            }
        }

        return self::normalize($out);
    }

    /**
     * Problems with staging these changes under the restrictions (empty = allowed).
     *
     * @param  list<array<string, mixed>>  $changes  ProposalService change input
     * @param  array<string, mixed>  $restrictions
     * @return list<string>
     */
    public function violations(int $workspaceId, array $changes, array $restrictions): array
    {
        $r = self::normalize($restrictions);
        if (self::isEmpty($r)) {
            return [];
        }
        $slugToId = Playbook::where('workspace_id', $workspaceId)->pluck('id', 'slug')->map(fn ($id) => (int) $id)->all();
        $idToName = Playbook::where('workspace_id', $workspaceId)->pluck('name', 'id')->all();
        $name = fn (int $id) => isset($idToName[$id]) && $idToName[$id] !== '' ? "“{$idToName[$id]}”" : "#{$id}";
        $list = fn (array $ids) => implode(', ', array_map($name, $ids));
        $targetWords = fn (array $types) => implode(', ', array_map(fn ($t) => self::TARGET_WORDS[$t] ?? (string) $t, $types));
        $problems = [];

        foreach ($changes as $n => $change) {
            $type = (string) ($change['target_type'] ?? '');
            $targetId = isset($change['target_id']) && (int) $change['target_id'] > 0 ? (int) $change['target_id'] : null;
            $path = 'Left out '.$this->describeChange($type, $targetId, $name);

            if (in_array($type, $r['exclude_targets'], true)) {
                $problems[] = "{$path}: you asked not to change ".(self::TARGET_WORDS[$type] ?? $type).'.';

                continue;
            }
            if ($r['only_targets'] !== null && ! in_array($type, $r['only_targets'], true)) {
                $problems[] = "{$path}: you asked to change only ".$targetWords($r['only_targets']).'.';

                continue;
            }

            $touched = [];
            if ($type === 'playbook') {
                if ($targetId !== null) {
                    $touched[] = $targetId;
                } elseif ($r['only_playbook_ids'] !== null) {
                    $problems[] = "{$path}: you asked to change only the playbooks ".$list($r['only_playbook_ids']).', so a new playbook is out of scope.';

                    continue;
                }
            } elseif ($type === 'knowledge') {
                $slugs = [];
                if ($targetId !== null) {
                    $item = KnowledgeItem::where('workspace_id', $workspaceId)->find($targetId);
                    $slugs = (array) (KnowledgeItem::normalizeScope($item?->scope)['playbooks'] ?? []);
                }
                foreach ((array) ($change['ops'] ?? []) as $op) {
                    $scope = $op['scope'] ?? (($op['field'] ?? null) === 'scope' ? ($op['value'] ?? null) : null);
                    if (is_array($scope)) {
                        $slugs = [...$slugs, ...array_map('strval', (array) ($scope['playbooks'] ?? []))];
                    }
                }
                foreach ($slugs as $slug) {
                    if (isset($slugToId[$slug])) {
                        $touched[] = $slugToId[$slug];
                    }
                }
                if ($r['only_playbook_ids'] !== null && $touched === []) {
                    $problems[] = "{$path}: you asked to change only the playbooks ".$list($r['only_playbook_ids']).', and this knowledge is not limited to them.';

                    continue;
                }
            } elseif ($type === 'example' && $targetId !== null) {
                $slug = AgentExample::where('workspace_id', $workspaceId)->whereKey($targetId)->value('playbook_slug');
                if ($slug !== null && isset($slugToId[(string) $slug])) {
                    $touched[] = $slugToId[(string) $slug];
                }
            }

            foreach (array_unique($touched) as $id) {
                if (in_array($id, $r['exclude_playbook_ids'], true)) {
                    $problems[] = "{$path}: you asked not to touch the playbook {$name($id)}.";
                } elseif ($r['only_playbook_ids'] !== null && ! in_array($id, $r['only_playbook_ids'], true)) {
                    $problems[] = "{$path}: the playbook {$name($id)} is outside what you asked to change (".$list($r['only_playbook_ids']).').';
                }
            }
        }

        return array_values(array_unique($problems));
    }

    /** Plain words for a change target in a refusal. */
    private const TARGET_WORDS = ['voice' => 'the voice', 'playbook' => 'playbooks', 'knowledge' => 'knowledge', 'example' => 'examples'];

    /** "the change to the voice" / "the change to the playbook “Refunds”" / "the new playbook" … */
    private function describeChange(string $type, ?int $targetId, callable $name): string
    {
        return match (true) {
            $type === 'voice' => 'the change to the voice',
            $type === 'playbook' && $targetId !== null => 'the change to the playbook '.$name($targetId),
            $type === 'playbook' => 'the new playbook',
            $type === 'knowledge' && $targetId !== null => "the change to knowledge item #{$targetId}",
            $type === 'knowledge' => 'the new knowledge item',
            $type === 'example' && $targetId !== null => "the change to example #{$targetId}",
            $type === 'example' => 'the new example',
            default => "a {$type} change",
        };
    }

    /**
     * @param  iterable<Playbook>  $playbooks
     * @return array{0: list<int>, 1: list<string>}
     */
    private function match(string $segment, iterable $playbooks): array
    {
        $lower = mb_strtolower($segment);
        $stems = self::stems($lower);
        $targets = [];
        if (preg_match('/\b(?:voice|tone|wording|greetings?|sign[- ]?offs?|phrasing|personality)\b/u', $lower) === 1) {
            $targets[] = 'voice';
        }
        if (preg_match('/\b(?:knowledge|facts?|kb|knowledge\s+base)\b/u', $lower) === 1) {
            $targets[] = 'knowledge';
        }
        if (preg_match('/\bexamples?\b/u', $lower) === 1) {
            $targets[] = 'example';
        }

        $ids = [];
        foreach ($playbooks as $playbook) {
            $slug = mb_strtolower((string) $playbook->slug);
            $nameText = mb_strtolower((string) $playbook->name);
            if (str_contains($lower, $slug) || str_contains($lower, str_replace('_', ' ', $slug)) || ($nameText !== '' && str_contains($lower, $nameText))) {
                $ids[] = (int) $playbook->id;

                continue;
            }
            $distinct = array_diff(array_unique([...self::stems(str_replace('_', ' ', $slug)), ...self::stems($nameText)]), self::stems(implode(' ', self::GENERIC)));
            if (array_intersect($distinct, $stems) !== []) {
                $ids[] = (int) $playbook->id;
            }
        }

        if ($ids === [] && preg_match('/\b(?:any|all|the)?\s*(?:rules|playbooks?)\b/u', $lower) === 1 && $targets === []) {
            $targets[] = 'playbook';
        }

        return [array_values(array_unique($ids)), $targets];
    }

    /** @return list<string> */
    public static function stems(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($words as $word) {
            if (mb_strlen($word) < 4) {
                continue;
            }
            $word = (string) preg_replace('/(?:ies)$/u', 'y', $word);
            $word = (string) preg_replace('/(?:ing|ed|es|s)$/u', '', $word);
            if (mb_strlen($word) >= 4) {
                $out[] = $word;
            }
        }

        return array_values(array_unique($out));
    }
}
