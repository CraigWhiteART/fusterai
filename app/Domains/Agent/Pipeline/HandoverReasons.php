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
        'writer' => 'the writer asked for a human',
        'validation_failed' => 'the draft failed validation after one rewrite',
        'validator_error' => 'the validator could not run',
        'triage_error' => 'the email could not be classified',
        'writer_error' => 'the writer failed',
        'no_draft' => 'the writer produced no reply',
        'timeout' => 'the run ran out of time',
    ];

    public static function label(string $reason): string
    {
        [$base, $detail] = array_pad(explode(':', $reason, 2), 2, null);
        $label = self::LABELS[$base] ?? $base;

        return $detail !== null && $detail !== '' ? "{$label} ({$detail})" : $label;
    }
}
