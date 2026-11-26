<?php

namespace App\Domains\Agent\Agents;

use App\Ai\Concerns\HasConfigurableProviderOptions;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * The writer's bounded tool loop. A text agent (not HasStructuredOutput):
 * Prism's Anthropic structured handler drops custom tools when native
 * output_config.format is used, so the structured result is delivered
 * through the submit_reply tool's arguments instead.
 */
// No #[Temperature]: current Claude models reject sampling parameters.
// MaxTokens leaves room for adaptive thinking, which counts toward it.
#[Provider(Lab::Anthropic)]
#[Model('claude-opus-5-5')]
#[MaxTokens(16000)]
#[MaxSteps(6)]
#[Timeout(120)]
class WriterAgent implements Agent, HasProviderOptions, HasTools
{
    use HasConfigurableProviderOptions;
    use Promptable;

    /** @param  list<Tool>  $tools */
    public function __construct(
        private readonly string $system,
        private readonly array $tools,
    ) {}

    public function instructions(): string
    {
        return $this->system;
    }

    /** @return list<Tool> */
    public function tools(): iterable
    {
        return $this->tools;
    }
}
