<?php

namespace App\Domains\Agent\Proposals;

use App\Domains\Agent\Models\Proposal;
use App\Domains\Agent\Models\ProposalChange;
use InvalidArgumentException;

/**
 * Applies a human's resolution of one conflict or example flag. Resolutions
 * that change the proposal edit its ops (and re-validate them):
 *
 *   keep_old     drop the proposed item (the existing instruction stays)
 *   replace_old  keep the proposed item and remove the existing one
 *   scope_new    give the proposed rule an "if" condition (or the proposed
 *                knowledge a playbook scope) so both can hold
 *   keep_both / acknowledge   mark as accepted, no edit (warnings, or a
 *                failed AI check the human chose to accept)
 *   retire / keep             example flags: add a retire_example change, or keep it
 */
class ConflictResolver
{
    public const RESOLUTIONS = ['keep_old', 'replace_old', 'scope_new', 'keep_both', 'acknowledge', 'retire', 'keep'];

    public function __construct(
        private readonly ProposalService $proposals,
    ) {}

    /** @throws InvalidArgumentException|InvalidProposalException|ProposalStateException */
    public function resolve(Proposal $proposal, string $id, string $resolution, ?string $condition, int $userId, ?string $note = null): Proposal
    {
        if (! $proposal->isOpen()) {
            throw new ProposalStateException("This proposal is {$proposal->status}.", 'not_open');
        }
        $report = (array) $proposal->conflicts;
        $items = (array) ($report['items'] ?? []);
        $flags = (array) ($report['example_flags'] ?? []);

        $index = $this->find($items, $id);
        $flagIndex = $index === null ? $this->find($flags, $id) : null;
        if ($index === null && $flagIndex === null) {
            throw new InvalidArgumentException("No conflict \"{$id}\" on this proposal.");
        }

        $entry = $index !== null ? $items[$index] : $flags[$flagIndex];
        if (! in_array($resolution, (array) ($entry['options'] ?? []), true)) {
            throw new InvalidArgumentException("\"{$resolution}\" is not an option here (".implode(', ', (array) $entry['options']).').');
        }

        $changes = $this->proposals->changeInput($proposal->loadMissing('changes'));
        $edited = match ($resolution) {
            'keep_old' => $this->dropNew($changes, $entry),
            'replace_old' => $this->removeExisting($changes, (array) $entry['existing']),
            'scope_new' => $this->scopeNew($changes, $entry, (string) $condition),
            'retire' => [...$changes, ['target_type' => ProposalChange::TARGET_EXAMPLE, 'target_id' => (int) $entry['example_id'], 'ops' => [['op' => 'retire_example', 'reason' => mb_substr((string) $entry['reason'], 0, 500)]]]],
            default => null,
        };

        $stamp = ['resolution' => $resolution, 'resolved_by' => $userId, 'resolved_at' => now()->toIso8601String(), 'note' => $note];
        if ($index !== null) {
            $items[$index] = [...$entry, ...$stamp];
        } else {
            $flags[$flagIndex] = [...$entry, ...$stamp];
        }

        if ($edited !== null) {
            if ($edited === []) {
                throw new InvalidArgumentException('Keeping the old instruction leaves nothing in this proposal: reject it instead.');
            }
            $proposal = $this->proposals->replaceChanges($proposal, $edited, keepConflicts: true);
        }

        $report['items'] = $items;
        $report['example_flags'] = $flags;
        $report['summary'] = [
            'blocking' => count(array_filter($items, fn ($i) => ($i['severity'] ?? '') === 'blocking' && ($i['resolution'] ?? null) === null)),
            'warnings' => count(array_filter($items, fn ($i) => ($i['severity'] ?? '') === 'warning' && ($i['resolution'] ?? null) === null)),
            'examples_flagged' => count(array_filter($flags, fn ($f) => ($f['resolution'] ?? null) === null)),
        ];
        $proposal->forceFill(['conflicts' => $report])->save();
        $this->proposals->broadcast($proposal);

        return $proposal->load('changes');
    }

    /** @param  list<array<string, mixed>>  $list */
    private function find(array $list, string $id): ?int
    {
        foreach ($list as $i => $item) {
            if (($item['id'] ?? null) === $id) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     * @param  array<string, mixed>  $entry
     * @return list<array<string, mixed>>
     */
    private function dropNew(array $changes, array $entry): array
    {
        $new = (array) $entry['new'];
        $ci = (int) $entry['change_index'];
        if (! isset($changes[$ci])) {
            return $changes;
        }

        // A whole create (knowledge) goes; otherwise only the op that introduced the item.
        if (in_array($new['op'] ?? null, ['create_knowledge'], true)) {
            unset($changes[$ci]);

            return array_values($changes);
        }

        $changes[$ci]['ops'] = array_values(array_filter($changes[$ci]['ops'], function (array $op) use ($new) {
            if (($new['item_id'] ?? null) !== null) {
                return ($op['id'] ?? null) !== $new['item_id'];
            }

            return ! (($op['op'] ?? null) === 'set_field' && ($op['field'] ?? null) === ($new['field'] ?? null));
        }));
        if ($changes[$ci]['ops'] === []) {
            unset($changes[$ci]);
        }

        return array_values($changes);
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     * @param  array<string, mixed>  $existing
     * @return list<array<string, mixed>>
     */
    private function removeExisting(array $changes, array $existing): array
    {
        [$type, $targetId, $op] = match ($existing['target_type']) {
            'voice' => ['voice', null, ['op' => 'remove_item', 'field' => $existing['field'], 'id' => $existing['item_id']]],
            'playbook' => ['playbook', (int) $existing['target_id'], ['op' => 'remove_rule', 'id' => $existing['item_id']]],
            'knowledge' => ['knowledge', (int) $existing['target_id'], ['op' => 'retire_knowledge']],
            default => throw new InvalidArgumentException('That existing item cannot be removed by a proposal.'),
        };

        foreach ($changes as $i => $change) {
            if ($change['target_type'] === $type && ($change['target_id'] ?? null) === $targetId) {
                $changes[$i]['ops'][] = $op;

                return $changes;
            }
        }
        $changes[] = ['target_type' => $type, 'target_id' => $targetId, 'ops' => [$op]];

        return $changes;
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     * @param  array<string, mixed>  $entry
     * @return list<array<string, mixed>>
     */
    private function scopeNew(array $changes, array $entry, string $condition): array
    {
        $condition = trim($condition);
        if ($condition === '') {
            throw new InvalidArgumentException('scope_new needs a condition: when the new rule applies (or the playbook slugs for knowledge).');
        }
        $new = (array) $entry['new'];
        $ci = (int) $entry['change_index'];

        foreach ($changes[$ci]['ops'] as $j => $op) {
            if (($new['item_id'] ?? null) !== null && ($op['id'] ?? null) === $new['item_id'] && in_array($op['op'], ['add_rule', 'replace_rule'], true)) {
                $changes[$ci]['ops'][$j]['if'] = mb_substr($condition, 0, 500);

                return $changes;
            }
            if ($op['op'] === 'create_knowledge') {
                $changes[$ci]['ops'][$j]['scope'] = ['global' => false, 'playbooks' => array_values(array_filter(array_map('trim', explode(',', $condition))))];

                return $changes;
            }
        }
        if (($changes[$ci]['target_type'] ?? null) === ProposalChange::TARGET_KNOWLEDGE) {
            $changes[$ci]['ops'][] = ['op' => 'set_field', 'field' => 'scope', 'value' => ['global' => false, 'playbooks' => array_values(array_filter(array_map('trim', explode(',', $condition))))]];

            return $changes;
        }

        throw new InvalidArgumentException('Only a proposed rule or knowledge item can be scoped.');
    }
}
