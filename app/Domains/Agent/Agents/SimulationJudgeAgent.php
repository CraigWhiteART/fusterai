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
 * `simulation_judge` role (a different model from the writer): compares the
 * agent's reply to a past case with what the human actually sent.
 */
// No #[Temperature]: current Claude models reject sampling parameters.
#[Provider(Lab::Anthropic)]
#[Model('claude-sonnet-5-5')]
#[MaxTokens(8192)]
#[Timeout(90)]
class SimulationJudgeAgent implements Agent, HasProviderOptions, HasStructuredOutput
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
        // No min/max keywords: structured output rejects numeric constraints; CaseScorer clamps to 1-5.
        $score = fn (string $what) => $schema->integer()->description($what.'. Integer 1-5 (1 = very poor, 5 = excellent)')->required();

        return [
            'outcome_match' => $score('Same outcome / decision as the human (answer vs hand over, refund vs not, ask for info...)'),
            'policy_consistency' => $score('Consistent with the instructions and with what the human allowed or refused'),
            'factual_grounding' => $score('Facts stated are supported by the evidence; 3 when facts cannot be judged'),
            'tone_match' => $score('Tone and voice match the brand voice and the human reply'),
            'would_send' => $schema->boolean()->description('Would a careful human send this reply (with at most trivial edits)?')->required(),
            'reason' => $schema->string()->description('One or two sentences: the main difference from the human reply')->required(),
        ];
    }
}
