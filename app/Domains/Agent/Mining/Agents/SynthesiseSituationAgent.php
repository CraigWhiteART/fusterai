<?php

namespace App\Domains\Agent\Mining\Agents;

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
 * Stage D: one situation's extractions in, a playbook draft (or minimal
 * changes to the existing playbook), knowledge, inconsistencies and test-case
 * picks out. Runs on the `manager` role.
 */
// No #[Temperature]: current Claude models reject sampling parameters.
// MaxTokens leaves room for adaptive thinking, which counts toward it.
#[Provider(Lab::Anthropic)]
#[Model('claude-opus-5-5')]
#[MaxTokens(32000)]
#[Timeout(600)]
class SynthesiseSituationAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use HasConfigurableProviderOptions;
    use Promptable;

    /** @param  list<string>  $actions  Action keys the human may enable later. */
    public function __construct(
        private readonly string $system,
        private readonly array $actions,
    ) {}

    public function instructions(): string
    {
        return $this->system;
    }

    public function schema(JsonSchema $schema): array
    {
        $evidence = fn (string $what) => $schema->array()->items($schema->integer())
            ->description("Conversation ids (from the data) that show {$what}")->required();
        $op = fn () => $schema->string()->enum(['add', 'replace', 'retire'])->required();

        return [
            'name' => $schema->string()->description('Short human name of the situation')->required(),
            'when_text' => $schema->string()->description('Plain-English description of when this playbook applies')->required(),
            'when_text_changed' => $schema->boolean()->description('False when the existing "when" text is fine as is')->required(),
            'rules' => $schema->array()->items($schema->object([
                'op' => $op(),
                'rule_id' => $schema->string()->nullable()->description('Existing rule id for replace/retire; null for add')->required(),
                'if' => $schema->string()->description('Condition, or "always"')->required(),
                'then' => $schema->string()->description('What to do')->required(),
                'evidence' => $evidence('this rule'),
                'rationale' => $schema->string()->description('Why, in one sentence')->required(),
            ])->withoutAdditionalProperties())->description('Only the changes needed. Never restate an existing rule unchanged.')->required(),
            'steps' => $schema->array()->items($schema->object([
                'op' => $op(),
                'step_id' => $schema->string()->nullable()->required(),
                'text' => $schema->string()->required(),
                'evidence' => $evidence('this step'),
            ])->withoutAdditionalProperties())->required(),
            'escalation' => $schema->string()->nullable()->description('When to hand to a human; null keeps the existing text')->required(),
            'escalation_evidence' => $evidence('escalation'),
            'required_evidence' => $schema->array()->items($schema->string()->enum(['order', 'tracking', 'photos']))->required(),
            'suggested_actions' => $schema->array()->items($schema->object([
                'action' => $schema->string()->enum($this->actions === [] ? ['none'] : $this->actions)->required(),
                'when' => $schema->string()->description('When the humans took this action')->required(),
                'evidence' => $evidence('the action'),
            ])->withoutAdditionalProperties())->description('Actions the humans took in this situation. Listed for review only.')->required(),
            'knowledge' => $schema->array()->items($schema->object([
                'kind' => $schema->string()->enum(['policy', 'fact'])->required(),
                'topic' => $schema->string()->description('snake_case topic; the same topic as the inconsistency when the history disagrees about it')->required(),
                'title' => $schema->string()->required(),
                'body' => $schema->string()->description('The policy or fact, generalised, current version')->required(),
                'scope' => $schema->object([
                    'global' => $schema->boolean()->required(),
                    'playbooks' => $schema->array()->items($schema->string())->required(),
                    'products' => $schema->array()->items($schema->string())->required(),
                    'countries' => $schema->array()->items($schema->string())->description('ISO-2 codes')->required(),
                ])->withoutAdditionalProperties()->required(),
                'evidence' => $evidence('the statement'),
            ])->withoutAdditionalProperties())->required(),
            'inconsistencies' => $schema->array()->items($schema->object([
                'topic' => $schema->string()->required(),
                'summary' => $schema->string()->required(),
                'versions' => $schema->array()->items($schema->object([
                    'statement' => $schema->string()->required(),
                    'evidence' => $evidence('this version'),
                ])->withoutAdditionalProperties())->required(),
                'recommendation' => $schema->string()->description('Prefer the most recent version unless the data says otherwise')->required(),
            ])->withoutAdditionalProperties())->description('Where the history contradicts itself')->required(),
            'test_cases' => $schema->array()->items($schema->object([
                'conversation_id' => $schema->integer()->required(),
                'kind' => $schema->string()->enum(['representative', 'edge'])->required(),
                'why' => $schema->string()->required(),
                'must_include' => $schema->array()->items($schema->string())->description('Short points a good reply must contain')->required(),
                'must_not_include' => $schema->array()->items($schema->string())->required(),
                'expected_outcome' => $schema->string()->enum(['reply', 'handover'])->required(),
            ])->withoutAdditionalProperties())->required(),
            'rationale' => $schema->string()->description('Two or three sentences for the reviewer')->required(),
        ];
    }
}
