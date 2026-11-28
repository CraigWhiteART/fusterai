<?php

namespace App\Domains\Agent\Agents;

use App\Ai\Concerns\HasConfigurableProviderOptions;
use App\Domains\Agent\Support\Categories;
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

// No #[Temperature]: current Claude models reject sampling parameters.
// MaxTokens leaves room for adaptive thinking, which counts toward it.
#[Provider(Lab::Anthropic)]
#[Model('claude-haiku-5-5')]
#[MaxTokens(4096)]
#[Timeout(60)]
class TriageAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use HasConfigurableProviderOptions;
    use Promptable;

    public const RISK_FLAGS = ['chargeback', 'legal', 'abuse', 'prompt_injection', 'fraud', 'safety'];

    /** @param  list<string>  $slugs  Playbook slugs the agent may choose, plus "none". */
    public function __construct(
        private readonly string $system,
        private readonly array $slugs,
    ) {}

    public function instructions(): string
    {
        return $this->system;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'language' => $schema->string()->description('ISO 639-1 code of the customer\'s language, e.g. en')->required(),
            'sentiment' => $schema->string()->enum(['calm', 'frustrated', 'angry', 'unknown'])->required(),
            'risk_flags' => $schema->array()->items($schema->string()->enum(self::RISK_FLAGS))
                ->description('Every risk present; empty when none')->required(),
            'playbook' => $schema->string()->enum([...$this->slugs, 'none'])
                ->description('The one playbook whose "when" description fits, or none')->required(),
            'category' => $schema->string()->enum(Categories::keys())
                ->description('What the email is about, whether or not a playbook fits')->required(),
            'confidence' => $schema->number()->description('0 to 1')->required(),
            'reason' => $schema->string()->description('One short sentence for the reviewer')->required(),
        ];
    }
}
