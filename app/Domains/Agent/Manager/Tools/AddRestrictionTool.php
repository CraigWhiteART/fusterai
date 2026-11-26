<?php

namespace App\Domains\Agent\Manager\Tools;

use App\Domains\Agent\Manager\ScopeGuard;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Lets the model record a scope restriction the user stated that the parser
 * missed. It can only tighten the chat's scope, never loosen it.
 */
class AddRestrictionTool extends ManagerTool
{
    public function name(): string
    {
        return 'add_restriction';
    }

    public function description(): string
    {
        return 'Record a scope restriction the user asked for ("don\'t touch the refund rules", "only change the tone") so it is enforced on every proposal in this chat. It can only add restrictions. Current restrictions are listed in your instructions.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'exclude_playbooks' => $schema->array()->items($schema->string())->description('Playbook slugs or ids that must not be changed'),
            'exclude_targets' => $schema->array()->items($schema->string()->enum(ScopeGuard::TARGETS))->description('Whole areas that must not be changed'),
            'only_targets' => $schema->array()->items($schema->string()->enum(ScopeGuard::TARGETS))->description('Areas the user limited the changes to'),
            'reason' => $schema->string()->description('The user\'s words')->required(),
        ];
    }

    protected function isStage(): bool
    {
        return true;
    }

    protected function label(array $arguments): string
    {
        return 'Recording a scope restriction';
    }

    protected function run(array $arguments): string
    {
        $ids = [];
        $unknown = [];
        foreach ((array) ($arguments['exclude_playbooks'] ?? []) as $ref) {
            $playbook = $this->playbook($ref);
            if ($playbook !== null) {
                $ids[] = $playbook->id;
            } else {
                $unknown[] = (string) $ref;
            }
        }
        $add = [
            'exclude_playbook_ids' => $ids,
            'exclude_targets' => (array) ($arguments['exclude_targets'] ?? []),
            'only_targets' => ($arguments['only_targets'] ?? []) === [] ? null : (array) $arguments['only_targets'],
            'sources' => [['from' => 'model', 'kind' => 'exclude', 'text' => mb_substr((string) ($arguments['reason'] ?? ''), 0, 200), 'matched' => true,
                'playbook_ids' => $ids, 'targets' => (array) ($arguments['exclude_targets'] ?? []), 'at' => now()->toIso8601String()]],
        ];
        $this->session->restrictions = ScopeGuard::merge($this->session->restrictions, $add);
        $r = $this->session->restrictions;

        return 'Restrictions now in force: '.json_encode(array_diff_key($r, ['sources' => true]))
            .($unknown !== [] ? '. Unknown playbooks ignored: '.implode(', ', $unknown) : '');
    }
}
