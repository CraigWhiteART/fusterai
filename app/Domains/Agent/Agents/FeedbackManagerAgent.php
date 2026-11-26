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
 * `manager` role: routes a human's correction of a draft to what should
 * change (scope) and writes minimal field-level ops. Its ops are validated
 * in code (OpsApplier / ProposalService); permission and limit ops are
 * refused there whatever it says.
 */
// No #[Temperature]: current Claude models reject sampling parameters.
#[Provider(Lab::Anthropic)]
#[Model('claude-opus-5-5')]
#[MaxTokens(16000)]
#[Timeout(150)]
class FeedbackManagerAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use HasConfigurableProviderOptions;
    use Promptable;

    public const SCOPES = ['one_off', 'voice', 'playbook', 'knowledge', 'example', 'unclear'];

    public const OPS = [
        'add_do', 'add_dont', 'add_greeting', 'add_sign_off', 'replace_item', 'remove_item', 'set_field',
        'add_rule', 'replace_rule', 'remove_rule', 'add_step', 'create_knowledge', 'retire_knowledge',
        'retire_example', 'replace_example',
    ];

    /** @param  list<string>  $scopes  Scopes it may choose (a forced scope, or every scope). */
    public function __construct(
        private readonly string $system,
        private readonly array $scopes = self::SCOPES,
    ) {}

    public function instructions(): string
    {
        return $this->system;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'scope' => $schema->string()->enum($this->scopes)->description('What should change')->required(),
            'reason' => $schema->string()->description('One or two sentences for the reviewer: why this scope')->required(),
            'question' => $schema->string()->description('Only for scope "unclear": ONE short clarifying question; otherwise empty')->required(),
            'playbook_slug' => $schema->string()->description('Target playbook slug for scope "playbook"; otherwise empty')->required(),
            'knowledge_id' => $schema->integer()->description('Knowledge item id to change for scope "knowledge"; 0 to create a new item')->required(),
            'example_id' => $schema->integer()->description('Example id (from X<id>) for scope "example"; otherwise 0')->required(),
            'title' => $schema->string()->description('Short title of the change, e.g. "Never promise delivery dates"')->required(),
            'one_off_instruction' => $schema->string()->description('For scope "one_off": the instruction for re-writing only this reply; otherwise empty')->required(),
            'ops' => $schema->array()->items($schema->object([
                'op' => $schema->string()->enum(self::OPS)->required(),
                'field' => $schema->string()->description('For set_field / replace_item / remove_item: the field name; otherwise empty')->required(),
                'item_id' => $schema->string()->description('Existing item id for replace / remove ops; otherwise empty')->required(),
                'text' => $schema->string()->description('New phrase / step text, or the rule "then" text; otherwise empty')->required(),
                'condition' => $schema->string()->description('Rule "if" condition; "always" or empty for unconditional')->required(),
                'value' => $schema->string()->description('set_field value as text (comma-separated for lists); replace_example: the new reply')->required(),
                'kind' => $schema->string()->description('create_knowledge: fact | policy | snippet; otherwise empty')->required(),
                'title' => $schema->string()->description('create_knowledge: title; otherwise empty')->required(),
                'body' => $schema->string()->description('create_knowledge: the knowledge text; otherwise empty')->required(),
                'scope_global' => $schema->boolean()->description('create_knowledge: applies everywhere')->required(),
                'scope_playbooks' => $schema->array()->items($schema->string())->description('create_knowledge: playbook slugs it applies to')->required(),
            ])->withoutAdditionalProperties())->description('Minimal field-level edits. Empty for one_off and unclear.')->required(),
            'must_not_include' => $schema->array()->items($schema->string())->description('Short exact phrases a correct reply to THIS conversation must not contain')->required(),
            'must_include' => $schema->array()->items($schema->string())->description('Short exact phrases a correct reply to THIS conversation must contain (often empty)')->required(),
            'suggestions' => $schema->array()->items($schema->string())->description('Changes only a person can make in settings (action permissions, limits, modes, rollout); otherwise empty')->required(),
        ];
    }
}
