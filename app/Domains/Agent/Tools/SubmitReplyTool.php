<?php

namespace App\Domains\Agent\Tools;

use App\Domains\Agent\Agents\ComposeReplyAgent;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * The writer's final output. Its arguments are the structured result (reply,
 * claims with sources, rules used, handover reason, internal note); taking it
 * through a tool keeps the tool loop and structured output in one call.
 */
class SubmitReplyTool extends WriterTool
{
    public function name(): string
    {
        return 'submit_reply';
    }

    public function description(): string
    {
        return 'Submit your final answer: the reply, every factual claim with the ids that support it, and the playbook rule ids used. Call exactly once, last.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ComposeReplyAgent::replySchema($schema);
    }

    protected function run(array $arguments): string
    {
        $this->session->submission = $arguments;

        return 'Received. You are finished; reply with the single word DONE.';
    }
}
