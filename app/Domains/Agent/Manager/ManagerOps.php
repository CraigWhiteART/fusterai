<?php

namespace App\Domains\Agent\Manager;

use App\Domains\Agent\Models\Playbook;

/**
 * Turns the loose op objects a model writes (tool arguments or structured
 * output rows) into ProposalService changes. It only reshapes: every op name
 * and field is passed on, so ProposalService keeps setting aside forbidden
 * ops (permissions, limits, modes, rollout) as suggestions.
 */
class ManagerOps
{
    /**
     * @param  list<mixed>  $changes  [{target_type, target_id?, playbook?, ops: [...]}]
     * @return list<array{target_type: string, target_id: int|null, ops: list<array<string, mixed>>}>
     */
    public static function changes(int $workspaceId, array $changes): array
    {
        $out = [];
        foreach ($changes as $change) {
            if (! is_array($change)) {
                continue;
            }
            $type = (string) ($change['target_type'] ?? '');
            $targetId = isset($change['target_id']) && is_numeric($change['target_id']) && (int) $change['target_id'] > 0 ? (int) $change['target_id'] : null;
            if ($type === 'playbook' && $targetId === null && filled($change['playbook'] ?? null)) {
                $id = Playbook::where('workspace_id', $workspaceId)->where('slug', (string) $change['playbook'])->value('id');
                $targetId = $id !== null ? (int) $id : null;
            }
            $ops = [];
            foreach ((array) ($change['ops'] ?? []) as $op) {
                if (is_array($op) && filled($op['op'] ?? null)) {
                    $ops[] = self::op($op);
                }
            }
            $out[] = ['target_type' => $type, 'target_id' => $type === 'voice' ? null : $targetId, 'ops' => $ops];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    public static function op(array $op): array
    {
        $name = (string) $op['op'];
        $op = array_filter($op, fn ($v) => $v !== null && $v !== '' && $v !== []);
        $op['op'] = $name;

        // Rules: "text" is accepted for "then"; "condition" for "if".
        if (in_array($name, ['add_rule', 'replace_rule'], true)) {
            if (! isset($op['then']) && isset($op['text'])) {
                $op['then'] = $op['text'];
            }
            if (! isset($op['if']) && isset($op['condition'])) {
                $op['if'] = $op['condition'];
            }
            unset($op['text'], $op['condition']);
        }
        if (in_array($name, ['replace_rule', 'remove_rule', 'replace_item', 'remove_item'], true) && ! isset($op['id']) && isset($op['item_id'])) {
            $op['id'] = $op['item_id'];
        }
        unset($op['item_id']);

        if ($name === 'set_field') {
            $field = (string) ($op['field'] ?? '');
            $op['value'] = match ($field) {
                'required_evidence' => array_values(array_filter(array_map('trim', (array) ($op['values'] ?? explode(',', (string) ($op['value'] ?? '')))))),
                'scope' => self::scope($op['scope'] ?? $op['value'] ?? []),
                'max_words' => isset($op['value']) && is_numeric($op['value']) ? (int) $op['value'] : null,
                default => $op['value'] ?? ($op['values'] ?? null),
            };
            unset($op['values'], $op['scope']);
        }
        if ($name === 'create_knowledge') {
            $op['scope'] = self::scope($op['scope'] ?? []);
            $op['kind'] ??= 'policy';
            if (! isset($op['body']) && isset($op['text'])) {
                $op['body'] = $op['text'];
                unset($op['text']);
            }
        }
        if ($name === 'create_playbook' && isset($op['rules']) && is_array($op['rules'])) {
            $op['rules'] = array_values(array_filter(array_map(fn ($r) => is_array($r)
                ? array_filter(['if' => $r['if'] ?? null, 'then' => $r['then'] ?? ($r['text'] ?? null)], fn ($v) => $v !== null && $v !== '')
                : ['then' => (string) $r], $op['rules']), fn ($r) => isset($r['then'])));
        }
        if ($name === 'replace_example' && ! isset($op['reply']) && isset($op['text'])) {
            $op['reply'] = $op['text'];
            unset($op['text']);
        }

        return $op;
    }

    /** @return array{global: bool, playbooks: list<string>, products: list<string>, countries: list<string>} */
    public static function scope(mixed $scope): array
    {
        $scope = is_array($scope) ? $scope : [];
        $list = fn ($v) => array_values(array_filter(array_map(fn ($x) => trim((string) $x), (array) $v), fn ($x) => $x !== ''));

        return [
            'global' => (bool) ($scope['global'] ?? false),
            'playbooks' => $list($scope['playbooks'] ?? []),
            'products' => $list($scope['products'] ?? []),
            'countries' => array_map('strtoupper', $list($scope['countries'] ?? [])),
        ];
    }
}
