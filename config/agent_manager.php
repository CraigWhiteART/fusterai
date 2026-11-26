<?php

/*
 * Manager chat ("Ask the manager", docs/agent-manager.md). Role `manager`.
 */
return [
    // Soft step cap per turn: tool calls beyond it are refused and the
    // manager is told to answer with what it has. The hard ceiling is the
    // #[MaxSteps] attribute on ManagerAgent (16).
    'max_steps' => (int) env('AGENT_MANAGER_MAX_STEPS', 10),

    // Estimated input-token budget per turn (4 chars ~ 1 token, summed over
    // every model call of the tool loop). Lookups past it are refused.
    'max_input_tokens' => (int) env('AGENT_MANAGER_MAX_INPUT_TOKENS', 200000),

    // Wall-clock budget per turn (seconds); the `ai` supervisor kills at 330s.
    'turn_seconds' => 240,

    // Daily spend cap per workspace for manager turns (USD). A turn is not
    // started once today's manager spend reaches it.
    'daily_cost_cap_usd' => (float) env('AGENT_MANAGER_DAILY_COST_CAP_USD', 5.0),

    // Proposals one turn may stage.
    'max_proposals_per_turn' => 3,

    // Earlier messages of the chat replayed to the model (text only).
    'history_messages' => 20,
    'history_chars' => 24000,

    // Characters of one tool result sent back to the model.
    'tool_result_chars' => 12000,

    // A pending / running turn older than this is considered dead.
    'stale_minutes' => 8,

    'queue' => env('AGENT_MANAGER_QUEUE', 'ai'),
];
