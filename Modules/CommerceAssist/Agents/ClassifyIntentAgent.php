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
#[MaxTokens(256)]
#[Temperature(0.1)]
class ClassifyIntentAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use HasConfigurableProviderOptions;
    use Promptable;

    /** @param  list<string>  $slugs */
    public function __construct(
        private readonly array $slugs,
    ) {}

    public function instructions(): string
    {
        $allowed = implode(', ', $this->slugs);

        return <<<INSTRUCTIONS
You classify a customer support email for a commerce helpdesk.

Return:
- intent: one of the parent slugs (before any dot), or a listed slug without a subtype
- subtype: a more specific slug when one fits, otherwise the same as intent
- sentiment: calm, frustrated, angry, or unknown
- country: ISO country name or code if the customer mentions a market, otherwise null

Allowed slugs:
{$allowed}

Rules:
- Prefer a specific subtype when the message is clear (e.g. tracking.no_updates).
- Use spam only for obvious unsolicited mail.
- Use other when nothing else fits.
- Do not invent an order number or country.
INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'intent' => $schema->string()->description('Primary intent slug')->required(),
            'subtype' => $schema->string()->description('More specific intent slug')->required(),
            'sentiment' => $schema->string()->enum(['calm', 'frustrated', 'angry', 'unknown'])->required(),
            'country' => $schema->string()->nullable()->description('Mentioned country or market'),
        ];
    }
}
