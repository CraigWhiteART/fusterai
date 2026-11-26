<?php

/*
 * History mining (app/Domains/Agent/Mining, docs/agent-mining.md): turns
 * closed support conversations into reviewable proposals (voice, playbooks,
 * knowledge, inconsistencies, test cases). Nothing here goes live by itself.
 */
return [
    // Bump a version when the matching prompt or output schema changes:
    // extractions are keyed by (conversation, content hash, extractor
    // version), and the cluster / synthesis / voice results are cached by
    // their input hash plus their version, so a re-run with unchanged data
    // and versions makes no model calls.
    'extractor_version' => 'extract-v1',
    'merge_version' => 'merge-v1',
    'synthesis_version' => 'synth-v1',
    'voice_version' => 'voice-v1',

    // Options a run starts with when the request leaves them out.
    'defaults' => [
        'months' => (int) env('AGENT_MINING_MONTHS', 18),
        'max_conversations' => (int) env('AGENT_MINING_MAX_CONVERSATIONS', 3000),
        'budget_usd' => (float) env('AGENT_MINING_BUDGET_USD', 25),
        // stratified (by month, newest weighted) | newest
        'sample_strategy' => 'stratified',
    ],

    'limits' => [
        'max_conversations' => 20000,
        'budget_usd' => 500,
    ],

    // Stage A. Each calendar month of the window is a stratum. floor_share of
    // the cap is spread evenly over the months first (old periods are always
    // represented); the rest goes by size x weight, the newest month weighted
    // (1 + recency_boost) times the oldest.
    'selection' => [
        'floor_share' => 0.3,
        'recency_boost' => 1.0,
    ],

    // Stage B (role history_extraction). One conversation per call.
    'extract' => [
        'transcript_max_tokens' => 5000,
        'verbatim_turns' => 12,
        'turn_max_chars' => 3000,
        'max_attempts' => 3,
        // Used by the estimate and the pre-call budget check (visible JSON +
        // adaptive thinking).
        'expected_output_tokens' => 1200,
    ],

    // Stage C.
    'cluster' => [
        'min_conversations' => 3,
        'max_situations' => 30,
        // Cosine similarity for merging labels when embeddings are configured.
        'similarity' => 0.82,
        'expected_output_tokens' => 4000,
    ],

    // Stage D (role manager). One call per situation.
    'synthesis' => [
        'sample_size' => 40,
        'outlier_share' => 0.3,
        'statement_limit' => 80,
        'reply_max_chars' => 800,
        'customer_max_chars' => 500,
        'test_cases_min' => 5,
        'test_cases_max' => 10,
        'max_attempts' => 3,
        'expected_output_tokens' => 6000,
    ],

    // Stage E (role manager). One call.
    'voice' => [
        'sample_size' => 150,
        'reply_max_chars' => 900,
        'expected_output_tokens' => 6000,
    ],

    'pii' => [
        // Order-number prefixes the store uses (besides "#1234" and
        // "order 1234"). Matched as PREFIX + 4 or more digits.
        'order_prefixes' => array_values(array_filter(array_map('trim', explode(',', (string) env('AGENT_MINING_ORDER_PREFIXES', 'SD'))))),
    ],

    // Job chain: same shape as the mail backfill (docs/mail-sync.md).
    'queue' => [
        'connection' => env('AGENT_MINING_QUEUE_CONNECTION', 'redis-long'),
        'queue' => env('AGENT_MINING_QUEUE', 'ai-bulk'),
        'step_seconds' => (int) env('AGENT_MINING_STEP_SECONDS', 120),
        'throttle_seconds' => (int) env('AGENT_MINING_THROTTLE_SECONDS', 2),
        'max_slices_per_step' => 200,
        'max_consecutive_errors' => 8,
        'stall_minutes' => 20,
    ],
];
