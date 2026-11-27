<?php

namespace App\Domains\Agent\Pipeline;

/**
 * Machine-readable handover reasons (agent_runs.handover_reason and
 * conversations.handover_reason). Prefixed reasons carry a detail after the
 * colon: risk:<flag>, writer:<tool reason>, missing_evidence:<list>.
 */
final class HandoverReasons
{
    public const LABELS = [
        'kill_switch' => 'the agent kill switch is on',
        'no_instruction' => 'no playbook covers this situation',
        'always_human' => 'this playbook is always handled by a human',
        'playbook_off' => 'the matching playbook is switched off',
        'order_email_mismatch' => 'the order email does not match the sender, so order details were withheld',
        'missing_evidence' => 'evidence the playbook requires is missing',
        'risk' => 'a risk flag was raised',
        'writer' => 'the agent asked for a person',
        'validation_failed' => 'the draft failed validation after one rewrite',
        'validator_error' => 'the validator could not run',
        'triage_error' => 'the email could not be classified',
        'writer_error' => 'the agent could not write a reply because of a technical problem',
        'no_draft' => 'the agent produced no reply',
        'timeout' => 'the run ran out of time',
    ];

    /** Why the writer itself handed over: writer:<HandoverTool reason>, or writer:writer_error when it failed. */
    public const WRITER_LABELS = [
        'needs_human_decision' => 'a person needs to make a decision',
        'policy_unclear' => 'the instructions do not say what to do here',
        'missing_information' => 'information needed for the answer is missing',
        'customer_requests_human' => 'the customer asked for a person',
        'sensitive' => 'the subject is sensitive',
        'other' => 'the agent asked for a person',
        'writer_error' => 'the agent could not write a reply because of a technical problem',
        'no_draft' => 'the agent produced no reply',
    ];

    /** A sentence fragment in plain words; never shows a raw code. */
    public static function label(string $reason): string
    {
        [$base, $detail] = array_pad(explode(':', $reason, 2), 2, null);
        $words = fn (string $code): string => trim(str_replace(['_', ':'], [' ', ', '], $code));
        $label = self::LABELS[$base] ?? $words($base);
        if ($detail === null || $detail === '') {
            return $label;
        }
        if ($base === 'writer' && isset(self::WRITER_LABELS[$detail])) {
            return self::WRITER_LABELS[$detail];
        }

        return "{$label} ({$words($detail)})";
    }
}
