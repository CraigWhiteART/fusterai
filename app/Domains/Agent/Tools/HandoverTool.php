<?php

namespace App\Domains\Agent\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;

class HandoverTool extends WriterTool
{
    public const REASONS = ['needs_human_decision', 'policy_unclear', 'missing_information', 'customer_requests_human', 'sensitive', 'other'];

    public function name(): string
    {
        return 'handover';
    }

    public function description(): string
    {
        return 'Hand this conversation to a human instead of drafting a reply. Use when the playbook says to escalate, the answer is not in the evidence or knowledge, or the case needs a judgement call.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'reason' => $schema->string()->enum(self::REASONS)->required(),
            'detail' => $schema->string()->description('One or two sentences for the support agent: what you found and why you stopped')->required(),
        ];
    }

    protected function run(array $arguments): string
    {
        $reason = (string) ($arguments['reason'] ?? 'other');
        $this->session->handover = [
            'reason' => in_array($reason, self::REASONS, true) ? $reason : 'other',
            'detail' => mb_substr(trim((string) ($arguments['detail'] ?? '')), 0, 1000),
        ];

        return 'Handover recorded. Stop now; do not write a customer reply.';
    }
}
