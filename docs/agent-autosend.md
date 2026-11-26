# Agent auto-send and rollout (Phase 8)

Until Phase 8 the agent only drafted. Auto-send lets it email a customer without a person, **only** for playbooks an admin switches to `auto`, inside a staged rollout, after an undo window, and behind several kill switches. When in doubt the reply is a draft for a human.

Code: `app/Domains/Agent/AutoSend/*`, `app/Domains/Agent/Pipeline/PolicyGate.php`, `RunEffects.php`, `app/Domains/Conversation/Jobs/SendReplyJob.php` (pre-send guard), `app/Domains/Agent/Http/Controllers/AutoSendController.php`. Migration: `database/migrations/2026_11_30_800000_add_agent_autosend.php`. Tests: `tests/Feature/Agent/AutoSend/*`.

---

## Go-live checklist for the owner

1. **Shadow first.** Agent settings → mode **Shadow**. The agent reads every email and records what it would have done; nobody sees anything. Leave it on for at least a week of normal mail.
2. **Read the shadow report** (screen: **Agent → Shadow report**, `/agent/shadow`; API `GET /agent/autosend/shadow-report`). Look at `would_auto_send`, the agreement rate per playbook (especially `would_auto_send_agreement_rate`) and every entry in `worst_disagreements`. Fix what they show (feedback on drafts, playbook rules, knowledge) and keep going until the playbook you want to automate agrees with your team almost every time.
3. **Pick one low-risk playbook** (e.g. order status / tracking). Give it at least 5 active test cases and run its tests until they pass (90%+) on the current version.
4. **Switch the workspace to Draft, then Live** (confirm the dialog). Set a **long wait before sending** (e.g. 1800 s), a low daily cap (e.g. 10), keep allowed languages to the ones you can read.
5. **Turn that one playbook to Auto at 10%** (the confirmation shows roughly how many recent conversations that is).
6. **Watch.** Every auto-reply appears in its conversation with a countdown, **Cancel** and **Edit instead**; mark anything off with **This was wrong**. Check the playbook page (safety switches, history) and the activity log daily.
7. **Raise slowly** (10% → 25% → 50% → 100%), shorten the wait, raise the cap. Repeat 3-7 per playbook.
8. **Emergency:** "Pause AI agent" (top of Agent settings) stops all automatic sending immediately and turns waiting replies back into drafts. The kill switch stops the agent altogether.

---

## When a reply is auto-sent (PolicyGate)

The gate first applies every handover rule (kill switch, triage failure, no playbook, risk flags, always_human, playbook off, order email mismatch, missing evidence, writer handover, validator error / failure, empty draft). A reply that survives is a **draft** unless **every** condition below holds; then it is `auto_send`. Each failing condition is recorded as a *blocker* on the run (`agent_runs.auto_send.blockers`, `trace.gate.auto_send_blockers`). A missing input blocks (fail closed); an exception in the checks = `gate_error` = draft.

| Blocker | Condition that must hold |
|---|---|
| `kill_switch` | kill switch off |
| `agent_mode` | workspace mode is `live` |
| `not_live` | the run is a live run of a real conversation (preview / simulation / test / shadow runs never auto-send; they get no context: `no_context`) |
| `paused` | auto-sending is not paused (`agent_settings.paused_until`) |
| `playbook_mode` | playbook mode is `auto` |
| `always_human` | playbook is not always_human (also a handover) |
| `playbook_hold` | playbook is not on a re-test hold (`auto_hold = retesting`) |
| `rollout` | `rolloutBucket(conversation_id) < rollout_percent`; bucket = `crc32('agent-rollout:'.id) % 100`, so a conversation never changes bucket and raising the percentage only adds conversations |
| `validator` | validator passed |
| `rewritten` | **the first draft passed with no rewrite** (decision: a draft that needed the validator's one rewrite is never auto-sent; it had a problem the validator caught, and the rewrite is a single structured call checked only once more) |
| `risk_flags` | triage raised no risk flag (also a handover) |
| `claims_ungrounded` | every claim cites at least one source and every source is in the evidence ledger or the knowledge the writer saw |
| `actions_pending` | no proposed action, or every proposed action can run unattended: its effective policy is `always` and the action type allows unattended runs (`auto_send.actions_unattended`, computed in `AutoSendContext`; missing = blocked). See below |
| `language` | triage language is in `auto_send.allowed_languages` (default `en`; `unknown` never matches) |
| `channel` | conversation channel is in `auto_send.channels` (default `email` only; chat / WhatsApp only when added) |
| `sentiment` | customer is not `angry` or `frustrated` |
| `human_replied` | nobody (human or an earlier auto-reply) replied since the latest customer message |
| `customer_blocked` | the customer is not blocked |
| `inbound_automated` | the customer message is not an auto-reply / bulk / list / notification (ledger classification, `Auto-Submitted`, `X-Autoreply`, `Precedence: bulk|list|junk|auto_reply`, `List-Id`, `List-Unsubscribe`, auto-reply subjects); the preflight also skips obvious auto-replies entirely |
| `requested_by_human` | the run was not a manual "regenerate" (a person is present: they get a draft) |
| `daily_cap` | auto-send decisions today < `caps.auto_send_per_day` (default 20) |
| `customer_cap` | today's auto-sends to this customer < `caps.auto_send_per_customer_per_day` (default 3) |
| `consecutive_cap` | auto-replies on this conversation since the last human message < `caps.auto_send_consecutive_per_conversation` (default 2; stops loops with other bots) |
| `hourly_breaker` | auto-send decisions in the last hour < `agent.auto_send.max_per_hour` (default 10); hitting it trips the global breaker |
| `context_error` | the auto-send context could be computed |

Caps count auto-send *decisions* (a cancelled one still counts).

### Replies that depend on an action

An auto-send may only go out when **every action the reply depends on has succeeded on the unattended path**:

1. **Gate.** Proposed actions block auto-send (`actions_pending`) unless each one's resolved policy is `always` and the action type may run unattended (`replacement` never does).
2. **After recording** (`RunEffects`, `ActionGate::autoSendVerdict`): if any action went to the approval route (a limit, cap, sender mismatch or the 4PX guard), failed, was cancelled, or could not be recorded, the run is **downgraded to a draft** (`decision = draft`, `auto_send.status = downgraded`, blocker `action_needs_approval` / `action_failed` / `action_unrecorded`, event `auto_send_downgraded`). The approval card / "needs rewrite" shows in the inbox as for any draft. A run with any action that needs a human is never auto-sent.
3. **Held.** Otherwise the reply is scheduled as usual. While an unattended reply-blocking action is still `approved` / `executing`, the thread carries `meta.auto_send.awaiting_actions = true` and `wait_until` (send time + `agent.auto_send.action_wait_seconds`, 900). When the send is due the pre-send guard answers "wait": nothing is sent or cancelled, and `SendReplyJob` is queued again after `action_poll_seconds` (30).
4. **Pre-send guard** (`AutoSendGuard::allowsSend`, every attempt) re-checks the actions after all its other checks: `autoSendVerdict` cancel -> cancel with that reason; still running or `ActionGate::blocksSending($run)` -> wait; past `wait_until` (or no `wait_until` while still blocked) -> cancel `action_timeout`.
5. **Failure cancels at once.** When an execution of that run moves to `failed`, `rejected`, `expired`, `cancelled`, `unknown` or back to `pending_approval`, `ActionNotifier` cancels the pending auto-reply (`AutoSendService::cancelForRun`, reason `action_failed` / `action_needs_approval`): it becomes a draft again, and for failed / rejected actions the existing rewrite (regenerate with the outcome in evidence) is requested.

The executor checks `AutoSendGuard::autonomyBlockedReason()` immediately before every unattended mutation (after its fresh Shopify reads): kill switch -> cancelled; mode not `live` or auto-sending paused -> back to the approval queue (`agent_mode_not_live` / `autonomy_paused`). Human-approved executions stop only for the kill switch. See [agent-actions.md](agent-actions.md#executor).

---

## Sending, the undo window and cancellation

1. **Schedule** (`AutoSendService::schedule`, from `RunEffects` for a live `auto_send` run): a message thread with `origin = ai`, `user_id = null`, `customer_id = null`, `meta.from_name = "AI agent"`, `meta.sent_by = "ai_agent"`, `meta.agent_run_id`, `meta.auto_send = {status: scheduled, run_id, playbook_id, scheduled_for}`, `send_at = now + auto_send_delay_seconds` (default 120; 0 allowed). The body is the draft (+ the optional disclosure line). `SendReplyJob` is queued on `email-outbound` with that delay (the existing scheduled-send mechanism). Any failure to schedule = a normal draft (`auto_send.status = schedule_failed`).
2. **Pre-send guard** (`AutoSendGuard::allowsSend`, first thing in `SendReplyJob::handle` for `origin = ai`, on every attempt including retries; row-locked): the thread must still be `scheduled`/`sending` with a `send_at`; then kill switch off, workspace `live`, not paused, the run exists and decided `auto_send`, playbook still `auto` (not archived, not always_human, no hold), the conversation still in the rollout, no newer customer message and no newer staff reply (app or Gmail) than the message the run answered, customer not blocked and has an email, conversation not closed / spam. If any check fails the send is cancelled with that reason. An exception = not sent.
3. **Send**: mailbox signature only (never a staff member's: the thread has no user), real `Message-ID`, `In-Reply-To` / `References` from the customer's last ledger message (`ReplyHeaders`), `X-FusterAI-Thread`. Then `markSent`: `meta.auto_send.status = sent`, `sent_at`, conversation status `auto_send.status_after_send` (`pending` default, or `open`; never `closed`).
4. **Cancel** (`AutoSendService::cancel`): the thread becomes an internal **note** ("AI auto-reply cancelled: <reason>. It was not sent." + the text), `send_at` cleared (so the queued job is a no-op even without the guard), `meta.auto_send.status = cancelled`, `cancel_reason`, `cancelled_by`. The text comes back as a normal draft (`AiSuggestion` with the run id), except when the customer wrote again (`new_customer_message`; the pipeline re-runs for the new message) or a human replied (`staff_replied`).

Where cancellation happens:

| When | How | Reason |
|---|---|---|
| A person clicks Cancel | `POST /agent/auto-sends/{thread}/cancel` (also the old `DELETE /conversations/{c}/threads/{t}/schedule`) | `cancelled_by_user` |
| Edit instead | same with `{edit: true}`; returns the text in `draft`: **the client loads it into the reply composer** (see "HTTP API") | `edit_instead` |
| Kill switch on / mode leaves live (settings save) | `AgentSettingsController::update` cancels all pending | `kill_switch` / `agent_mode` |
| Pause (button or hourly breaker) | all pending | `paused` |
| Playbook demoted / switched off / re-test hold | that playbook's pending (hold: at send time) | `breaker` / `playbook_mode` / `playbook_hold` |
| New customer message, staff reply in fusterai or ingested from Gmail | `thread.created` hook (AgentServiceProvider) before the pipeline re-runs | `new_customer_message` / `staff_replied` |
| Anything that changed and slipped past the above | the pre-send guard | its reason |

---

## Turning a playbook to auto (preconditions)

`PATCH /agent/playbooks/{id}` with `mode: auto` requires `confirm_auto: true` and (checked server-side by `AutoModeGuard`, shown as a checklist on the playbook page):

- a live version, not archived, not always_human;
- at least `agent.auto_send.min_test_cases` (5) active test cases;
- its most recent playbook test run (`kind = playbook_tests`; the run now stores `selection.playbook_version_id`) is on the **current** live version, completed, and passed at `agent.auto_send.min_test_pass_rate` (0.9) or better (effective verdicts: human overrides count; `pass`/`improved` pass).

New playbooks cannot be created as `auto`. Workspace mode `live` is shown but not required (nothing is sent until it is live).

**When an auto playbook's instructions change** (approved proposal, proposal revert, a saved version, a restore): `auto_hold = retesting` (auto-send waits; pending replies are cancelled at send time), its tests re-run, and when they finish (`SimulationRunner::finish` hook): pass → hold lifted; anything else → demoted to `draft` (notice on the playbook page, activity log `playbook_demoted` with `breaker = retest`, admin notification). `ProposalService::approve` / `revert` behave exactly as before; this is a post-step (`AutoModeGuard::afterProposalApplied`) that never throws. Voice, knowledge and example changes trigger the same hold and re-test: a voice change (proposal, save or restore on the Voice page) holds **every** auto playbook; knowledge created / edited / retired (proposal, the Knowledge screen, or a KB document re-sync) holds the auto playbooks in its scope (named playbook slugs; global or product / country-only scope = all auto playbooks; both the old and the new scope on an edit); examples created or retired by a proposal hold the playbook of their `playbook_slug` (all when they have none). Only the re-test the hold points at (`auto_hold_simulation_id`) decides: a newer change cancels the re-test still running for the previous one.

---

## Circuit breakers (only ever demote)

Per playbook, over `breakers.window_hours` (72), evaluated after each live run of the playbook, on every "This was wrong" mark, and every 15 minutes (`agent:autosend-breakers`, routes/console.php). The first one over its limit switches the playbook `auto → draft`, cancels its pending replies (drafts), writes `playbook_demoted` to the activity log and notifies admins (`AgentAutoSendNotification`, database + mail + broadcast). Nothing switches a playbook back to auto except a person, through the preconditions.

| Breaker | Trips when | Default |
|---|---|---|
| `validator_failures` | failed validations / validated live runs, sample ≥ `min_sample` | ≥ 30% of ≥ 10 |
| `negative_signal` | auto-replies followed by an unhappy next customer message (triage `angry`/`frustrated`, a risk flag, or `writer:customer_requests_human`) or a reopen (activity log: status `closed → open` after the send) | ≥ 25% of ≥ 10 |
| `wrong_marks` | "This was wrong" marks on its auto-replies | ≥ 2 |
| `qa_score` | average `agent_qa_reviews.overall` (status `reviewed`, no human verdict) of its auto-replies; read defensively (`QaSignal`, no table / columns = no signal) | < 3.0 over ≥ 5 |

Global: when a run would have been an auto-send but `max_per_hour` (10) auto-sends already happened in the last hour, all auto-sending is paused for `pause_hours` (24) (`paused_until`, `paused_reason`), pending replies are cancelled, admins are notified. Only "Resume" (an admin) lifts it early.

---

## Shadow mode

In `shadow` the pipeline runs with purpose `shadow` and writes only the run (no suggestion, no thread, no broadcast, no mail). Each shadow run also records `auto_send.would_auto_send` (the gate's only blockers were "not live") and `eligible_if_auto` (also ignoring the playbook's mode / rollout / hold).

When staff answer that message (in fusterai or Gmail), `CompareShadowRunJob` (`ai` queue) has the simulation judge (`CaseScorer`, `simulation_judge` role) compare the agent's draft with the human reply, on an in-memory (never saved) frozen case. It writes one `agent_shadow_comparisons` row and nothing else. Sampled by `agent.auto_send.shadow.sample_rate` (1.0, stable per run) and capped by `shadow.daily_cap` judge calls per day (50); skipped ones are stored as `skipped` (`sampled_out` / `daily_cap`).

---

## HTTP API

| Route | Who | Response |
|---|---|---|
| `POST /agent/auto-sends/{thread}/cancel` `{edit?: bool}` | `ConversationPolicy::update` | `{ok, draft, suggestion_id, edit}`; 409 when no longer scheduled; 404 when not an AI thread. With `edit: true` nothing else is needed server-side: the reply was cancelled, `draft` is its plain text and the same text is also back as the AI draft (`suggestion_id`). The client must put `draft` into the composer itself (convert plain text to the composer's HTML) so the person can edit and send it; the server never opens the composer. |
| `POST /agent/auto-sends/{thread}/wrong` `{reason}` (3-2000 chars) | `ConversationPolicy::view` | 201 `{ok, feedback_id, already_flagged, playbook_demoted}`; records an `auto_send_wrong` feedback event (`meta: {auto_thread_id, playbook_id}`); 404 unless a sent auto-reply |
| `POST /agent/autosend/pause` `{hours?, reason?}` | manage-settings | `{paused_until, paused_reason, cancelled}` (default: until resumed) |
| `POST /agent/autosend/resume` | manage-settings | `{paused_until: null}` |
| `GET /agent/autosend/shadow-report?days=30&worst=10` | manage-settings | see below |
| `GET /agent/playbooks/{playbook}/autosend` | manage-settings (404 other workspace) | `{preconditions, breakers, history, rollout_preview, auto_hold, demoted_at, demotion_reason}` (also the `auto_send` prop of `Agent/Playbooks/Edit`) |

`PATCH /agent/settings` adds `confirm_live` (required when switching to `live`), `caps.auto_send_consecutive_per_conversation`, `auto_send {allowed_languages[], channels[] (email|chat|whatsapp), disclosure_line, status_after_send (pending|open)}`. `Agent/Settings` props add `settings.auto_send`, `settings.paused_until`, `settings.paused_reason`, `auto_send_status {auto_playbooks[], sent_today, last_hour, pending, max_per_hour, pause_hours}`, `language_options`, `channel_options`; `autonomy_enforced` is now `true`.

`GET /agent/conversations/{id}/run` → `run.decision` may be `auto_send`; `run.auto_send = {blockers[], would_auto_send, eligible_if_auto, status (decided|scheduled|sending|sent|cancelled|schedule_failed), thread_id?, scheduled_for?, delay_seconds?, sent_at?, cancel_reason?, cancelled_by?, restored_draft?, events: [{event, at, ...}]}`.

Shadow report:

```jsonc
{
  "window_days": 30, "since": "...",
  "totals": {"shadow_runs": 120, "would_auto_send": 41, "eligible_if_auto": 66, "compared": 80, "agreed": 71,
             "agreement_rate": 0.888, "would_auto_send_compared": 30, "would_auto_send_agreed": 29,
             "would_auto_send_agreement_rate": 0.967, "skipped": 3, "failed": 0, "cost_micro_usd": 640000, "cost_usd": 0.64},
  "playbooks": [{"playbook": {"id", "slug", "name", "mode", "rollout_percent"} | null,
                 "runs", "drafts", "handovers", "would_auto_send", "eligible_if_auto", "compared", "agreed",
                 "agreement_rate", "avg_score", "would_auto_send_compared", "would_auto_send_agreed", "would_auto_send_agreement_rate"}],
  "worst_disagreements": [{"comparison_id", "run_id", "conversation_id", "playbook_id", "would_auto_send", "score",
                           "judge": {"outcome_match", "policy_consistency", "factual_grounding", "tone_match", "would_send", "reason"},
                           "ai_decision", "ai_handover_reason", "ai_reply", "human_reply", "created_at"}],
                          // disagreements only; the ones that would have auto-sent first, lowest score first
  "sampling": {"sample_rate": 1.0, "daily_cap": 50}
}
```

## Audit

Every decision and state change is in the activity log, log name `agent_autosend`, with `properties.run_id` where there is a run; the same events are appended to `agent_runs.auto_send.events`. Events: `auto_send_decision`, `auto_send_scheduled`, `auto_send_sent`, `auto_send_cancelled` (causer = the user when a person cancelled), `auto_send_failed`, `auto_send_flagged_wrong`, `playbook_auto_enabled`, `playbook_auto_disabled`, `playbook_rollout_changed`, `playbook_retest_started`, `playbook_retest_passed`, `playbook_demoted` (`properties.breaker`), `autosend_paused`, `autosend_resumed`, `agent_settings_changed`.

## Config (`config/agent.php`)

`auto_send_delay_seconds` (120, new workspaces) and `auto_send`: `defaults {allowed_languages [en], channels [email], disclosure_line '', status_after_send pending, per_day 20, per_customer_per_day 3, consecutive_per_conversation 2}`, `min_test_cases` (5, `AGENT_AUTO_MIN_TEST_CASES`), `min_test_pass_rate` (0.9), `max_per_hour` (10, `AGENT_AUTO_MAX_PER_HOUR`), `pause_hours` (24), `breakers {window_hours 72, min_sample 10, validator_failure_rate 0.3, negative_signal_rate 0.25, wrong_marks 2, qa_min_overall 3.0, qa_min_sample 5}`, `shadow {sample_rate 1.0 (AGENT_SHADOW_SAMPLE_RATE), daily_cap 50 (AGENT_SHADOW_DAILY_CAP)}`. Existing workspaces keep the caps they saved (a stored 0 means "no auto-sends").

## Frontend

- `Pages/Agent/Settings.tsx`: Live mode (with a confirmation), "Pause AI agent" / Resume, a plain-English "What will be sent automatically", delay, caps, languages, channels, disclosure line, status after send.
- `Pages/Agent/Playbooks/Edit.tsx` settings section + `PlaybookAutoSend.tsx`: Auto offered once the checklist passes, confirmation dialog, rollout preview ("about N of the last 100 matching conversations"), safety switches, history, demotion / re-test notices.
- `Components/conversation/AutoSendThread.tsx` (wired in `ThreadItem.tsx`): countdown with Cancel / Edit instead on a pending auto-reply, "Sent by AI agent" badge, "This was wrong". Edit instead cancels (the text returns as the AI draft in the draft panel) and dispatches `window` event `fusterai:load-reply` `{conversationId, text}`; `ConversationView` listens for it and loads the text into the composer.
- `Pages/Agent/Shadow/Index.tsx` (`/agent/shadow`): the shadow report screen (totals, per-playbook agreement, worst disagreements).
