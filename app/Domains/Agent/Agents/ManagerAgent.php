<?php

namespace App\Domains\Agent\Agents;

use App\Ai\Concerns\HasConfigurableProviderOptions;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;

/**
 * `manager` role in the manager chat: a bounded tool loop over read tools
 * plus stage_proposal. A text agent (its answer is the final text): Prism's
 * Anthropic structured handler drops custom tools, and the answer is prose.
 * The chat history (earlier requests and answers, text only) comes in as
 * messages; the system prompt holds only fixed, human-approved content.
 *
 * MaxSteps is the hard ceiling; the configurable step cap
 * (agent_manager.max_steps) is enforced by the tools themselves.
 */
// No #[Temperature]: current Claude models reject sampling parameters.
#[Provider(Lab::Anthropic)]
#[Model('claude-opus-5-5')]
#[MaxTokens(16000)]
#[MaxSteps(16)]
#[Timeout(120)]
class ManagerAgent implements Agent, Conversational, HasProviderOptions, HasTools
{
    use HasConfigurableProviderOptions;
    use Promptable;

    /**
     * @param  list<Tool>  $tools
     * @param  list<Message>  $history
     */
    public function __construct(
        private readonly string $system,
        private readonly array $tools,
        private readonly array $history = [],
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

    /** @return list<Message> */
    public function messages(): iterable
    {
        return $this->history;
    }
}
