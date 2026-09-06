<?php

namespace Modules\CommerceAssist\Agents;

use App\Ai\Concerns\HasConfigurableProviderOptions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

#[Provider(Lab::Anthropic)]
#[Model('claude-haiku-4-5-20251001')]
#[MaxTokens(512)]
#[Temperature(0)]
class ValidateFactsAgent implements Agent, HasStructuredOutput, HasProviderOptions
{
    use HasConfigurableProviderOptions;
    use Promptable;

    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
You are a fact checker for customer-support email drafts.

You receive a proposed reply and a block of verified information.

Return every factual claim in the reply that is not supported by the verified information.
Unsupported claims include invented dates, tracking numbers, order status, promises of refunds/replacements/shipping, and policy statements not present in the verified knowledge.

Greeting, empathy, and asking for information are not claims.
If the reply only uses verified facts, return an empty list.

Do not rewrite the reply. Only list unsupported claims as short quotes from the draft.
INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'unsupported_claims' => $schema->array()
                ->items($schema->string())
                ->description('Quotes from the draft that are not supported by verified information')
                ->required(),
        ];
    }
}
