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
 * `manager` role: pairwise check of a proposed instruction against the
 * shortlisted existing instructions / knowledge / example replies.
 */
// No #[Temperature]: current Claude models reject sampling parameters.
#[Provider(Lab::Anthropic)]
#[Model('claude-opus-5-5')]
#[MaxTokens(12000)]
#[Timeout(120)]
class ConflictJudgeAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use HasConfigurableProviderOptions;
    use Promptable;

    public const RELATIONS = ['contradiction', 'duplicate', 'subsumes', 'violates', 'none'];

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
            'pairs' => $schema->array()->items($schema->object([
                'pair_id' => $schema->string()->description('The pair id exactly as given')->required(),
                'relation' => $schema->string()->enum(self::RELATIONS)->required(),
                'explanation' => $schema->string()->description('One sentence; empty when none')->required(),
            ])->withoutAdditionalProperties())->description('One entry per pair given')->required(),
        ];
    }
}
