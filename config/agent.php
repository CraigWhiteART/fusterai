<?php

return [
    // Bump when the fixed instructions / message layout in PromptBuilder
    // change: it is part of every run's config_hash.
    'prompt_template_version' => 'agent-v3',

    // Transcript budget (approximate tokens, 4 chars ~ 1 token). The most
    // recent turns are kept verbatim; older turns are summarised or dropped.
    'transcript' => [
        'max_tokens' => 6000,
        'verbatim_turns' => 6,
        'turn_max_chars' => 4000,
    ],

    // Writer tool loop. `tools` = bounded tool loop finished by submit_reply;
    // `structured` = one structured call, everything prefetched, no tools.
    'writer' => [
        'mode' => env('AGENT_WRITER_MODE', 'tools'),
        'max_steps' => 6,
    ],

    'knowledge' => [
        'prefetch_limit' => 5,
        'search_limit' => 5,
        'min_similarity' => 0.6,
        // KB chunking (characters).
        'chunk_size' => 1800,
        'chunk_overlap' => 200,
    ],

    'examples' => [
        'limit' => 3,
    ],

    // Undo window before an auto-sent reply goes out (Phase 8). 0 = immediately.
    'auto_send_delay_seconds' => 120,

    // Action keys the writer may *propose* (nothing executes them until the
    // Phase 9 executor exists). A playbook's allowed_actions must be a subset.
    'actions' => [
        'add_order_note' => 'Add a note to the order',
        'tag_order' => 'Tag the order',
        'change_address' => 'Change the shipping address',
        'cancel_order' => 'Cancel the order',
        'refund' => 'Refund (full or partial)',
        'discount_code' => 'Issue a discount code',
        'replacement' => 'Send a replacement',
        'fulfilment_hold' => 'Hold fulfilment',
    ],

    // Domains a draft may link to in addition to the Shopify shop domain and
    // the workspace's own allowlist. Subdomains match.
    'carrier_domains' => [
        '4px.com',
        'track123.com',
        '17track.net',
        'aftership.com',
        'auspost.com.au',
        'startrack.com.au',
        'couriersplease.com.au',
        'aramex.com.au',
        'sendle.com',
        'dhl.com',
        'fedex.com',
        'ups.com',
        'usps.com',
        'royalmail.com',
        'canadapost-postescanada.ca',
        'nzpost.co.nz',
    ],

    // USD per million tokens, used for agent_runs.cost_micro_usd. From
    // platform.claude.com/docs/en/about-claude/pricing (cache_write = 5 minute
    // writes; Haiku = prompts up to 100k tokens). Unknown models fall back to
    // `default` (priced like Opus so cost is never under-reported).
    'prices' => [
        'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00, 'cache_read' => 0.20, 'cache_write' => 5.00],
        'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cache_read' => 0.10, 'cache_write' => 2.50],
        'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50, 'cache_read' => 0.01, 'cache_write' => 0.125],
        // OpenRouter list prices (openrouter.ai/api/v1/models).
        'gpt-5.6-luna' => ['input' => 0.20, 'output' => 1.20, 'cache_read' => 0.02, 'cache_write' => 0.20],
        'gpt-5.6-terra' => ['input' => 2.00, 'output' => 12.00, 'cache_read' => 0.20, 'cache_write' => 2.00],
        'gpt-5.6-sol' => ['input' => 4.00, 'output' => 20.00, 'cache_read' => 0.40, 'cache_write' => 4.00],
        'default' => ['input' => 4.00, 'output' => 20.00, 'cache_read' => 0.20, 'cache_write' => 5.00],
    ],

    // ── Feedback loop and simulation (Phase 5, docs/agent-proposals.md) ──────

    'feedback' => [
        // Start the regression gate automatically after a feedback proposal is
        // previewed, when it has affected test cases.
        'auto_gate' => (bool) env('AGENT_FEEDBACK_AUTO_GATE', true),
    ],

    'simulation' => [
        'gate_cap' => 40,
        'max_batch_cases' => 500,
        // Gates / playbook tests: interactive `ai` queue (330s supervisor).
        'gate_connection' => env('AGENT_GATE_QUEUE_CONNECTION'),
        'gate_queue' => env('AGENT_GATE_QUEUE', 'ai'),
        'step_seconds' => 200,
        // Batch simulations and case preparation: long queue (900s, tries 1).
        'batch_connection' => env('AGENT_SIMULATION_QUEUE_CONNECTION', 'redis-long'),
        'batch_queue' => env('AGENT_SIMULATION_QUEUE', 'ai-bulk'),
        'batch_step_seconds' => 600,
        // Cost estimate fallbacks (micro-USD) when the workspace has no recent runs.
        'run_cost_micro_usd' => 60000,
        'judge_cost_micro_usd' => 8000,
        // "Prepare cases" Shopify / carrier lookups per minute per workspace.
        'prepare_per_minute' => 30,
    ],

    // ── Auto-send and rollout (Phase 8, docs/agent-autosend.md) ──────────────
    'auto_send' => [
        // Defaults for a workspace that has not saved its own values
        // (agent_settings.auto_send / caps). Everything is off until the
        // workspace mode is `live` AND a playbook is switched to `auto`.
        'defaults' => [
            'allowed_languages' => ['en'],
            // Conversation channels auto-send may answer. Chat / WhatsApp
            // only when explicitly added.
            'channels' => ['email'],
            // Optional line appended to every auto-sent reply (off when empty).
            'disclosure_line' => '',
            // Conversation status after an auto-send: pending | open (never closed).
            'status_after_send' => 'pending',
            'per_day' => 20,
            'per_customer_per_day' => 3,
            // Max auto-replies in a row on one conversation without a human
            // message in between (stops loops with other bots).
            'consecutive_per_conversation' => 2,
        ],
        // Enabling `auto` on a playbook needs this many active test cases and
        // a passing playbook test run on its current live version.
        'min_test_cases' => (int) env('AGENT_AUTO_MIN_TEST_CASES', 5),
        'min_test_pass_rate' => (float) env('AGENT_AUTO_MIN_PASS_RATE', 0.9),
        // Global breaker: more than this many auto-sends in one hour pauses
        // all auto-sending for `pause_hours`.
        'max_per_hour' => (int) env('AGENT_AUTO_MAX_PER_HOUR', 10),
        'pause_hours' => 24,
        // A reply that depends on an unattended Shopify action is held after
        // its undo window until the action succeeds: re-checked every
        // `action_poll_seconds`, and turned back into a draft when the action
        // fails / needs approval or after `action_wait_seconds`.
        'action_poll_seconds' => 30,
        'action_wait_seconds' => 900,
        // Per-playbook breakers over a rolling window. A breaker only ever
        // demotes auto -> draft; nothing promotes a playbook back.
        'breakers' => [
            'window_hours' => 72,
            'min_sample' => 10,
            'validator_failure_rate' => 0.3,
            'negative_signal_rate' => 0.25,
            'wrong_marks' => 2,
            'qa_min_overall' => 3.0,
            'qa_min_sample' => 5,
        ],
        // Shadow mode: compare what the agent would have sent with what the
        // human sent (simulation judge). Costs one judge call per sample.
        'shadow' => [
            'sample_rate' => (float) env('AGENT_SHADOW_SAMPLE_RATE', 1.0),
            'daily_cap' => (int) env('AGENT_SHADOW_DAILY_CAP', 50),
        ],
    ],
];
