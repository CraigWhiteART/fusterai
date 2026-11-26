<?php

namespace App\Domains\Agent\Agents;

use App\Ai\Concerns\HasConfigurableProviderOptions;
use App\Domains\Agent\Qa\Models\QaReview;
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
 * QA full review (`simulation_judge` role, Sonnet: a different model from
 * the writer): scores a staff reply on a closed conversation and classifies
 * what was missing.
 */
// No #[Temperature]: current Claude models reject sampling parameters.
#[Provider(Lab::Anthropic)]
#[Model('claude-sonnet-5-5')]
#[MaxTokens(8192)]
#[Timeout(90)]
class QaReviewAgent implements Agent, HasProviderOptions, HasStructuredOutput
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
        // No min/max keywords: structured output rejects numeric constraints; QaReviewer clamps to 1-5.
        $score = fn (string $what) => $schema->integer()->description($what.'. Integer 1-5 (1 = very poor, 5 = excellent)')->required();

        return [
            'resolved' => $score('Answered or resolved what the customer actually asked'),
            'policy_consistency' => $score('Consistent with the playbook and brand rules that apply'),
            'factual_grounding' => $score('Facts stated are supported by the evidence (order, tracking, knowledge); use 3 when there is no evidence to judge against'),
            'grounding_judgeable' => $schema->boolean()->description('False when there was no order / tracking evidence to check facts against')->required(),
            'tone_match' => $score('Matches the brand voice'),
            'clarity' => $score('Clear, appropriately short, easy to act on'),
            'failure_class' => $schema->string()->enum(QaReview::FAILURE_CLASSES)->description('Main reason it fell short, or none')->required(),
            'gap' => $schema->string()->description('For missing_*: what instruction, knowledge or capability was missing, stated generally (no customer names, order numbers or other personal data). Otherwise empty.')->required(),
            'gap_topic' => $schema->string()->description('For missing_*: a short snake_case topic, e.g. warranty_cosmetic_damage. Otherwise empty.')->required(),
            'suggested_fix' => $schema->string()->description('The smallest instruction / knowledge / capability change that would fix it, or empty')->required(),
            'summary' => $schema->string()->description('One or two sentences for the owner')->required(),
        ];
    }
}
