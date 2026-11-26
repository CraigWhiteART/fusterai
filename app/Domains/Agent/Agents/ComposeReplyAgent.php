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
 * Single structured writer call with every piece of evidence prefetched and
 * no tools. Used when writer.mode = structured, for the one rewrite after a
 * validation failure, and when the tool loop ends without submit_reply.
 */
// No #[Temperature]: current Claude models reject sampling parameters.
// MaxTokens leaves room for adaptive thinking, which counts toward it.
#[Provider(Lab::Anthropic)]
#[Model('claude-opus-5-5')]
#[MaxTokens(16000)]
#[Timeout(120)]
class ComposeReplyAgent implements Agent, HasProviderOptions, HasStructuredOutput
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
        return self::replySchema($schema);
    }

    /**
     * Shared by the submit_reply tool so both paths return the same shape.
     *
     * @return array<string, mixed>
     */
    public static function replySchema(JsonSchema $schema): array
    {
        return [
            'reply' => $schema->string()
                ->description('The customer-facing email body. Empty when handing over.')
                ->required(),
            'claims' => $schema->array()->items($schema->object([
                'text' => $schema->string()->description('A factual statement made in the reply')->required(),
                'sources' => $schema->array()->items($schema->string())
                    ->description('Evidence ids (E1...) or knowledge ids (K12...) that support it')->required(),
            ])->withoutAdditionalProperties())->description('Every factual statement in the reply with its sources')->required(),
            'rules_used' => $schema->array()->items($schema->string())
                ->description('Playbook rule ids that shaped the reply')->required(),
            'handover_reason' => $schema->string()->nullable()
                ->description('Set (and leave reply empty) when a human must take over; null otherwise')->required(),
            'internal_note' => $schema->string()
                ->description('Short note for the support agent: what you found and anything they should check')->required(),
        ];
    }
}
