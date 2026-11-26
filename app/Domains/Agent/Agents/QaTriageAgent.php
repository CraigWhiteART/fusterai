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
 * QA first pass (`triage` role, Haiku): one quick 1-5 score of a staff
 * reply and which playbook the case falls under. Low scores go to the full
 * review (QaReviewAgent).
 */
// No #[Temperature]: current Claude models reject sampling parameters.
#[Provider(Lab::Anthropic)]
#[Model('claude-haiku-5-5')]
#[MaxTokens(4096)]
#[Timeout(60)]
class QaTriageAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use HasConfigurableProviderOptions;
    use Promptable;

    /** @param  list<string>  $playbooks */
    public function __construct(
        private readonly string $system,
        private readonly array $playbooks,
    ) {}

    public function instructions(): string
    {
        return $this->system;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'score' => $schema->integer()->description('Overall quality of the reviewed reply, integer 1-5 (5 = a careful senior agent would send it unchanged)')->required(),
            'concerns' => $schema->array()->items($schema->string())->description('Short problems noticed (empty when none)')->required(),
            'playbook' => $schema->string()->enum([...$this->playbooks, 'none'])->description('The playbook this case falls under, or none')->required(),
            'reason' => $schema->string()->description('One sentence')->required(),
        ];
    }
}
