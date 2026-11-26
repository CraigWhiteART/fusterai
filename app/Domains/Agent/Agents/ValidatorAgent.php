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

// Runs on the `validator` role, a different model from the writer.
// No #[Temperature]: current Claude models reject sampling parameters.
#[Provider(Lab::Anthropic)]
#[Model('claude-sonnet-5-5')]
#[MaxTokens(8192)]
#[Timeout(90)]
class ValidatorAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use HasConfigurableProviderOptions;
    use Promptable;

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
            'verdict' => $schema->string()->enum(['pass', 'fail'])->required(),
            'issues' => $schema->array()->items($schema->object([
                'quote' => $schema->string()->description('Exact words from the draft')->required(),
                'problem' => $schema->string()->description('Why it is unsupported or against the rules')->required(),
            ])->withoutAdditionalProperties())->description('Empty when the verdict is pass')->required(),
        ];
    }
}
