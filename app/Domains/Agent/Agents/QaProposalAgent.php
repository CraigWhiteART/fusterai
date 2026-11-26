<?php

namespace App\Domains\Agent\Agents;

use App\Ai\Concerns\HasConfigurableProviderOptions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * `manager` role: turns one group of recurring QA gaps (same playbook,
 * similar missing instruction / knowledge) into ONE proposal of minimal
 * field-level ops. Validated by ProposalService like every other producer.
 */
// No #[Temperature]: current Claude models reject sampling parameters.
#[Provider(Lab::Anthropic)]
#[Model('claude-opus-5-5')]
#[MaxTokens(16000)]
#[Timeout(150)]
class QaProposalAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use HasConfigurableProviderOptions;
    use Promptable;

    public const OPS = ['add_rule', 'replace_rule', 'add_step', 'set_field', 'create_knowledge', 'retire_knowledge', 'add_do', 'add_dont'];

    public function __construct(
        private readonly string $system,
    ) {}

    public function instructions(): string
    {
        return $this->system;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Short title of the change')->required(),
            'rationale' => $schema->string()->description('Why, for the reviewer: the pattern across the conversations')->required(),
            'ops' => $schema->array()->items($schema->object([
                'target_type' => $schema->string()->enum(['playbook', 'knowledge', 'voice'])->required(),
                'target_id' => $schema->integer()->description('Playbook id / knowledge item id; 0 for voice and for create_knowledge')->required(),
                'op' => $schema->string()->enum(self::OPS)->required(),
                'item_id' => $schema->string()->description('Existing rule id for replace_rule; otherwise empty')->required(),
                'field' => $schema->string()->description('set_field: when_text | escalation (playbook) or body | title | scope (knowledge); otherwise empty')->required(),
                'condition' => $schema->string()->description('Rule "if"; "always" or empty for unconditional')->required(),
                'text' => $schema->string()->description('Rule "then" text, step text, phrase, or set_field value')->required(),
                'kind' => $schema->string()->description('create_knowledge: fact | policy | snippet; otherwise empty')->required(),
                'title' => $schema->string()->description('create_knowledge: title; otherwise empty')->required(),
                'body' => $schema->string()->description('create_knowledge: the knowledge text; otherwise empty')->required(),
                'scope_global' => $schema->boolean()->description('create_knowledge: applies everywhere')->required(),
                'scope_playbooks' => $schema->array()->items($schema->string())->description('create_knowledge: playbook slugs it applies to')->required(),
            ])->withoutAdditionalProperties())->description('Minimal field-level edits; empty when no instruction change would help')->required(),
            'suggestions' => $schema->array()->items($schema->string())->description('Changes only a person can make (permissions, limits, modes, rollout, new capabilities); otherwise empty')->required(),
        ];
    }
}
