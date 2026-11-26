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
 * Stage C without embeddings: map new situation labels onto existing
 * playbook slugs and merge near-duplicates. One cheap call per run.
 */
// No #[Temperature]: current Claude models reject sampling parameters.
#[Provider(Lab::Anthropic)]
#[Model('claude-haiku-5-5')]
#[MaxTokens(12000)]
#[Timeout(180)]
class MergeSituationsAgent implements Agent, HasProviderOptions, HasStructuredOutput
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
            'mappings' => $schema->array()->items($schema->object([
                'label' => $schema->string()->description('A label exactly as given')->required(),
                'target' => $schema->string()->description('An existing slug, or the key of one of new_situations')->required(),
            ])->withoutAdditionalProperties())->description('One entry per given label')->required(),
            'new_situations' => $schema->array()->items($schema->object([
                'key' => $schema->string()->description('snake_case key')->required(),
                'name' => $schema->string()->description('Short human name')->required(),
                'description' => $schema->string()->description('One sentence describing when this situation applies')->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }
}
