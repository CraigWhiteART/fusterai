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
 * Stage E: a stratified sample of human replies in, changes to the current
 * voice version out. Runs on the `manager` role.
 */
// No #[Temperature]: current Claude models reject sampling parameters.
#[Provider(Lab::Anthropic)]
#[Model('claude-opus-5-5')]
#[MaxTokens(24000)]
#[Timeout(600)]
class VoiceProfileAgent implements Agent, HasProviderOptions, HasStructuredOutput
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
        $ops = fn (string $what) => $schema->array()->items($schema->object([
            'op' => $schema->string()->enum(['add', 'replace', 'retire'])->required(),
            'id' => $schema->string()->nullable()->description('Existing item id for replace/retire; null for add')->required(),
            'text' => $schema->string()->required(),
        ])->withoutAdditionalProperties())->description("Changes to {$what}; only what the replies justify")->required();

        return [
            'personality' => $schema->string()->nullable()->description('New personality text, or null to keep the current one')->required(),
            'do_phrases' => $ops('the do list'),
            'dont_phrases' => $ops('the don\'t list'),
            'greetings' => $ops('greetings'),
            'sign_offs' => $ops('sign-offs'),
            'max_words' => $schema->integer()->nullable()->description('Typical upper length in words, or null to keep')->required(),
            'length_guidance' => $schema->string()->nullable()->required(),
            'staff_differences' => $schema->array()->items($schema->object([
                'topic' => $schema->string()->required(),
                'note' => $schema->string()->required(),
            ])->withoutAdditionalProperties())->required(),
            'example_phrases' => $schema->array()->items($schema->object([
                'text' => $schema->string()->description('Short verbatim phrase from a reply')->required(),
                'staff' => $schema->string()->description('Staff label as given')->required(),
            ])->withoutAdditionalProperties())->required(),
            'rationale' => $schema->string()->required(),
        ];
    }
}
