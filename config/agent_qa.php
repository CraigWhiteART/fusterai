<?php

/*
 * QA reviews of closed conversations and the AI performance report
 * (docs/agent-qa.md).
 */
return [
    // Master switch. Reviews also need the workspace's agent mode != off and
    // an AI key.
    'enabled' => (bool) env('AGENT_QA_ENABLED', true),

    // Bump when the reviewer prompts change: part of the uniqueness key, so a
    // new version reviews the same reply again.
    'prompt_version' => 'qa-v1',

    // conversation.closed -> review after this delay (lets a quick reopen cancel it).
    'delay_minutes' => (int) env('AGENT_QA_DELAY_MINUTES', 10),

    // Share of closed conversations reviewed (0-1). Forced reviews ignore it.
    'sample_rate' => (float) env('AGENT_QA_SAMPLE_RATE', 1.0),

    // Reviews started per workspace per day (closed trigger). Forced reviews ignore it.
    'daily_cap' => (int) env('AGENT_QA_DAILY_CAP', 60),

    'first_pass' => [
        // Role `triage` (Haiku). A score below this is flagged for the full review.
        'threshold' => 4,
    ],

    // Share of NOT-flagged reviews that still get the full review (role
    // `simulation_judge`): an unbiased sample for the AI-vs-human averages.
    'full_review_sample_rate' => (float) env('AGENT_QA_FULL_SAMPLE_RATE', 0.15),

    // Characters of each transcript turn shown to the reviewers.
    'turn_chars' => 3000,
    'max_turns' => 12,

    'gaps' => [
        // Reviews (distinct conversations, not dismissed) in one gap group
        // before the manager drafts ONE proposal (origin qa).
        'proposal_threshold' => (int) env('AGENT_QA_GAP_THRESHOLD', 3),
        // Jaccard similarity of normalised gap terms to join an existing group.
        'similarity' => 0.34,
        // Gap excerpts sent to the manager when drafting.
        'max_examples' => 6,
    ],

    // A customer coming back unhappy within this many days of an AI-drafted
    // reply (next run's triage sentiment frustrated / angry) forces a review.
    'unhappy_return' => [
        'days' => 14,
        'sentiments' => ['frustrated', 'angry'],
    ],

    'backfill' => [
        'connection' => env('AGENT_QA_BACKFILL_CONNECTION', 'redis-long'),
        'queue' => env('AGENT_QA_BACKFILL_QUEUE', 'ai-bulk'),
        'max' => 500,
    ],

    'queue' => env('AGENT_QA_QUEUE', 'ai'),
];
