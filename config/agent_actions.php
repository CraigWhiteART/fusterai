<?php

/*
 * Shopify actions with approval and unattended actions (Phases 9-10).
 * Read by app/Domains/Commerce/Actions. See docs/agent-actions.md.
 *
 * Nothing here enables anything: every action's policy defaults to `never`
 * until an admin changes it through the API.
 */
return [
    // Queue for ExecuteActionJob / ReconcileActionJob (tries = 1, never retried).
    'queue' => env('AGENT_ACTIONS_QUEUE', 'default'),

    // Unknown outcome (timeout / 5xx / connection error): re-query the order
    // this many seconds later, up to `reconcile_max_attempts` times, then hand
    // over to a human. A mutation is never re-sent.
    'reconcile_delay_seconds' => 60,
    'reconcile_max_attempts' => 5,

    // Defaults for a new agent_action_settings row.
    'defaults' => [
        'approver_role_money' => 'admin',
        'approver_role_other' => 'agent',
        'approval_expiry_hours' => 48,
        'refund_hard_ceiling' => 150.00,
        'unattended_order_age_minutes' => 60,
        'audit_note' => true,
    ],

    // Code limits that no policy can raise.
    'max_refund_ceiling' => 5000.00,
    'max_discount_percentage' => 50,
    'max_discount_expiry_days' => 90,

    // Prefix of the single-use discount codes the agent creates.
    'discount_code_prefix' => 'SD',

    // Name used in the order note / staff note audit line.
    'helpdesk_name' => env('AGENT_ACTIONS_HELPDESK_NAME', 'fusterai'),
];
