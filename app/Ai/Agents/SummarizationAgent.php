<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\HasConfigurableProviderOptions;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

// No #[Temperature]: current Claude models reject sampling parameters, and
// MaxTokens leaves room for adaptive thinking, which counts toward it.
#[Provider(Lab::Anthropic)]
#[Model('claude-haiku-5-5')]
#[MaxTokens(2048)]
class SummarizationAgent implements Agent, HasProviderOptions
{
    use HasConfigurableProviderOptions;
    use Promptable;

    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
        You summarize customer support conversations into 2-3 concise bullet points.
        Format as plain text bullets starting with "•".
        Cover: the customer's main issue, key actions taken, and current resolution status.
        Be factual and neutral — no commentary.
        INSTRUCTIONS;
    }
}
