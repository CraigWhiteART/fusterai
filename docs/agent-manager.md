# Manager chat ("Ask the manager")

Phase 6. The owner asks in plain English ("We changed our warranty policy … update whatever needs updating", "Why did the agent hand over conversation 1234?", "Make the tone a bit less formal, but don't touch refund rules"). The `manager` model (Opus by default) reads this workspace's own data through tools and may **stage** draft proposals. It never applies anything: every change is an ordinary proposal (origin `manager`) that a person reviews, conflict-checks, optionally previews / gates, and approves in the proposal screen (docs/agent-proposals.md).

Code: `app/Domains/Agent/Manager` (service, session, scope guard, citations, tools), `app/Domains/Agent/Agents/ManagerAgent.php`, `app/Domains/Agent/Jobs/ManagerTurnJob.php`, `app/Domains/Agent/Http/Controllers/ManagerChatController.php`, `app/Domains/Agent/Events/ManagerChatUpdated.php`. Config: `config/agent_manager.php`. Migration: `database/migrations/2026_11_29_400000_create_agent_manager_tables.php`. Tests: `tests/Feature/Agent/ManagerChatTest.php`.

laravel/ai's own `agent_conversations` tables / `RemembersConversations` are **not** used: they are keyed by user only (no workspace), have no columns for cost, citations, proposals or tool summaries, and are slated for deletion (plan Phase 11). Chats live in their own tables; earlier turns are replayed to the model as plain user / assistant messages (`Conversational::messages()`).

---

## Tables

### `agent_manager_chats` (`Manager\Models\ManagerChat`)

`workspace_id`, `user_id` (owner; chats are private to the admin who created them), `title` (first request's first 80 chars unless given), `restrictions` (see "Scope restrictions"), `message_count`, `tokens_in`, `tokens_out`, `cost_micro_usd` (running totals), `last_message_at`.

### `agent_manager_messages` (`Manager\Models\ManagerMessage`)

| column | notes |
|---|---|
| `chat_id`, `workspace_id`, `user_id` | |
| `role` | `user` \| `assistant` |
| `status` | user rows: `completed`. Assistant rows: `pending` → `running` → `completed` \| `failed` \| `capped` (daily budget spent; no model call) |
| `content` | user text / the manager's answer (prose, with citation tags, see "Citations") |
| `reply_to_id` | assistant row → the user row it answers |
| `tool_calls` | `[{step, tool, arguments, summary, ok, refused}]` in call order (`summary` is a human label like "Reading run #12"; `refused` = blocked by the step cap / token budget / deadline) |
| `citations` | `[{type, id, label, source}]`, see "Citations" |
| `proposal_ids` | proposals staged in this turn |
| `suggestions` | changes it was **not** allowed to stage: `{type: "forbidden", target_type, target_id, op, reason, proposal_id?}` (ProposalService set aside a permission / limit / mode / rollout op) and `{type: "scope", reason}` (refused by a scope restriction) |
| `restrictions` | user rows: what the parser found in that request (`sources` included); assistant rows: restrictions in force at the end of the turn |
| `limits` | `{tool_calls, step_cap, step_cap_reached, estimated_input_tokens, token_budget, token_budget_reached, deadline_reached, unknown_citations[], prompt_version}` |
| `model`, `error`, `tokens_in/out/cache_read/cache_write/reasoning`, `cost_micro_usd`, `latency_ms`, `started_at`, `completed_at` | |

Deleting a chat deletes its messages. Proposals it staged are kept (they are independent rows; their `evidence.manager_chat_id` then points nowhere).

---

## How a turn runs

1. `POST …/messages` stores the user row, runs the **restriction parser** on the text (merged into the chat), stores a `pending` assistant row and queues `ManagerTurnJob` on `agent_manager.queue` (`ai`; tries 1, timeout 320s, below the 330s supervisor).
2. The job re-checks the **daily cap** (→ `capped`), marks the row `running`, broadcasts `agent.manager.started`.
3. One `ManagerAgent` call (`manager` role via `AiSettingsService::withWorkspaceCredentials`): a bounded tool loop. System prompt = fixed instructions + the approved playbook catalogue (`#id slug "name" (mode)`) + the restrictions in force + the untrusted-data preamble. **No customer text is ever in the system prompt**: customer mail, drafts and subjects reach the model only inside tool results, wrapped in `UntrustedData` nonce blocks. History = earlier turns of this chat (text only, last `history_messages` = 20 within `history_chars` = 24,000, alternating, starting with a user turn).
4. Each tool call broadcasts `agent.manager.step`. Lookups beyond the caps are refused with a message telling the model to answer now.
5. The final text is the answer. Citation tags are validated; the row is completed with tool summary, citations, proposals, suggestions, usage; chat totals are updated; `agent.manager.finished` is broadcast. An empty answer (step limit hit mid-call, or the AI call threw) is replaced by a plain fallback that lists what it looked at and any staged proposals. A thrown call with nothing staged ends `failed` with `error`.

### Limits (config `agent_manager.*`)

| key | default | meaning |
|---|---|---|
| `max_steps` (`AGENT_MANAGER_MAX_STEPS`) | 10 | tool calls per turn; call N+1 onward is refused (stage_proposal / add_restriction are exempt so a finished investigation can still be staged). Hard ceiling: `#[MaxSteps(16)]` on `ManagerAgent` (model round trips). |
| `max_input_tokens` (`AGENT_MANAGER_MAX_INPUT_TOKENS`) | 200,000 | **estimated** input tokens summed over the loop (chars / 4; every call re-sends the context, so each tool result is counted again on every later step). Lookups past it are refused. Actual usage is recorded after the call. |
| `turn_seconds` | 240 | wall-clock budget; every tool (including staging) is refused after it |
| `daily_cost_cap_usd` (`AGENT_MANAGER_DAILY_COST_CAP_USD`) | 5.00 | per workspace per calendar day, sum of assistant rows' `cost_micro_usd`. Checked before queueing (HTTP 409) and again in the job (`capped`). A running turn can overshoot by its own cost. |
| `max_proposals_per_turn` | 3 | stage_proposal calls that may create a proposal |
| `tool_result_chars` | 12,000 | one tool result is cut to this |
| `history_messages`, `history_chars` | 20, 24,000 | replayed history |
| `stale_minutes` | 8 | a pending / running turn older than this is marked `failed` ("worker stopped") when the chat is read or written |

---

## Tools

Every read tool only reads rows of the chat's workspace. Results are JSON inside untrusted blocks.

| tool | arguments | returns / cites |
|---|---|---|
| `list_playbooks` | `include_archived?` | id, slug, name, mode, rollout, always_human, live version, rule count, when text |
| `get_playbook` | `playbook` (id or slug), `version?` | rules / steps with ids, required evidence, escalation, allowed actions (read-only), last 10 versions, 30-day live runs by decision and QA failure classes. Cites `playbook` |
| `get_voice` | — | live voice items with ids, recent versions. Cites `voice` |
| `search_knowledge` | `query`, `status?` (active \| any), `playbook?` | knowledge items (id, kind, status, title, body ≤ 900, scope, validity). Postgres full-text; vectors only when embeddings exist |
| `list_examples` | `playbook?`, `contains?`, `include_retired?` | example replies with ids |
| `list_runs` | `playbook?`, `decision?` (draft \| handover), `handover_reason?` (prefix), `feedback?` (sent_as_is \| sent_edited \| gmail_reply \| rejected \| comment), `subject_contains?`, `conversation_id?`, `since?`, `until?`, `purpose?` (live \| shadow), `limit?` (≤ 50) | run id, conversation id, subject, playbook, decision, handover reason, feedback, QA overall / class |
| `get_run` | `run_id` | customer message, triage, playbook version, rules cited (with text), evidence facts and notes, knowledge seen, draft, internal note, claims, proposed actions, validator, what staff did, the reply actually sent, QA review, cost. Cites `run` + `conversation` |
| `get_conversation` | `conversation_id` | subject, status, imported, last 12 messages (role, author, origin, time, text ≤ 1,500), runs, QA reviews. Cites `conversation` |
| `feedback_stats` | `since?` (default 30 days), `until?`, `playbook?` | per playbook: sent as-is / edited (+avg % changed) / Gmail / rejected / comments, edit rate; latest 10 reviewer comments |
| `list_qa_reviews` | `failure_class?`, `author_type?`, `playbook?`, `max_overall?`, `since?`, `until?`, `limit?` | full QA reviews (not dismissed) with scores, gap, suggested fix |
| `simulation_results` | `simulation_id?`, `proposal_id?` | latest simulations with totals, or one simulation's non-passing cases with the judge's reason. Cites `simulation` |
| `proposals` | `proposal_id?`, `status?`, `origin?` | open proposals (or a status), or one proposal's diff / suggestions / blocking conflicts. Cites `proposal` |
| `add_restriction` | `exclude_playbooks?[]` (slugs / ids), `exclude_targets?[]`, `only_targets?[]`, `reason` | records a restriction the user stated (tighten only) |
| `stage_proposal` | `title`, `rationale`, `changes[{target_type: voice\|playbook\|knowledge\|example, target_id (0 = voice / create), ops[]}]`, `conversation_ids?[]`, `run_ids?[]` | the **only write**. See below |

### `stage_proposal` guardrails (all in code)

1. At most `max_proposals_per_turn` per turn; identical changes twice in one turn return the first proposal.
2. Ops are reshaped by `ManagerOps` (e.g. `text` → rule `then`, `values` → `required_evidence`, `scope` objects) but **no op or field is dropped**, so forbidden ones still reach ProposalService.
3. **Scope restrictions** (`ScopeGuard::violations`) are checked first; any violation refuses the whole call ("Not staged: the request's scope restrictions forbid …") and is stored in `suggestions` (`type: scope`) with a plain-English `reason` such as "Left out the change to the playbook “Refunds”: you asked not to touch the playbook “Refunds”." (playbook names, not slugs or change indexes). The model is told to stage only what is in scope and to tell the user what it left out.
4. `ProposalService::createProposal($ws, 'manager', …)` validates against the live versions. Permission / limit / mode / rollout / auto-send ops are set aside by ProposalService as `proposal.suggestions`; when nothing else is left no proposal is created. Either way the tool result tells the model to say so plainly, and the message's `suggestions` lists them (`type: forbidden`). Validation errors go back to the model to fix and retry (one step each).
5. Evidence stored on the proposal: `{conversation_ids, run_ids (only ids of this workspace), manager_chat_id, manager_message_id, request (the owner's text), restrictions}`; `created_by` = the chat owner; `scope` = the single target type when there is one.
6. The result lists the diff (target / label: before → after). Status is always `draft`.

### Scope restrictions

`chat.restrictions = {exclude_playbook_ids: int[], exclude_targets: (voice|playbook|knowledge|example)[], only_playbook_ids: int[]|null, only_targets: (...)[]|null, sources: [{from: parser|user|model, kind: exclude|only|set, text, matched, playbook_ids, targets, at}]}`.

- **Parser** (every request, deterministic): `don't / do not / never / without / no` + `touch | change | modify | edit | update | alter | mess with | affect | rewrite | adjust` + phrase; `leave X alone / as is / unchanged / untouched`; `keep X the same / as is / unchanged`; `only change X` / `change only X`. The phrase (cut at "but / and then / however …") is matched to playbooks by slug, name, or a distinctive word stem (e.g. "refund rules" → `refund_request`, `refund.change_of_mind`; generic words like order / status / customer never match alone), and to areas: voice (voice, tone, wording, greeting, sign-off, phrasing, personality), knowledge (knowledge, facts, KB), examples, playbook ("rules" / "playbooks" with no specific match). Unmatched phrases are kept in `sources` with `matched: false` so the UI can show "I could not tell what 'X' refers to". Policy wording such as "but not cosmetic damage" is not a restriction.
- **Model**: `add_restriction` can only tighten (union of exclusions, intersection of "only" lists).
- **Human**: `POST /chats` / `PATCH /chats/{id}` `restrictions` replaces them (the only way to loosen).
- Enforcement: a change is refused when its target type is excluded / not in `only_targets`; a playbook change when the playbook is excluded / not in `only_playbook_ids` (a new playbook is refused when `only_playbook_ids` is set); a knowledge change when the item's scope (existing, or the new / changed `scope`) names an excluded playbook (or, with `only_playbook_ids`, names none of them); an example change when the example's playbook is excluded. Global knowledge is only restricted by `exclude_targets: [knowledge]`. Restrictions also appear in the system prompt.

### Citations

`message.citations[]`: `{type, id, label, source}` (run citations also carry `conversation_id`, the conversation the run answered, or null) with `type` ∈ `conversation | run | playbook | proposal | knowledge | example | qa | simulation | voice` and `source`:

- `read`: a detail tool returned it (get_playbook, get_run (run + its conversation), get_conversation, get_voice, proposals with id, simulation_results with id);
- `staged`: a staged proposal and the conversations / runs it cites;
- `answer`: a tag in the answer text.

The answer may contain tags `[conversation:ID]`, `[run:ID]`, `[playbook:ID]`, `[proposal:ID]`, `[knowledge:ID]`, `[example:ID]`, `[qa:ID]`, `[simulation:ID]`. Each is checked against the workspace: valid tags stay in `content` (render them as links; `qa` = QA review id) and are in `citations`; unknown ones are rewritten to plain text (`run 999`) and listed in `limits.unknown_citations`.

---

## HTTP API

Routes in `routes/agent.php` (block "Manager chat, QA reviews, AI performance report"), `web` + `auth`, JSON. Every endpoint requires `manage-settings` (403 otherwise; 401 for guests). A chat of another user or workspace is 404.

### `GET /agent/manager/chats`

Query `per_page?` (≤ 100, default 30). Newest activity first.

```jsonc
{
  "data": [Chat],
  "meta": {"current_page": 1, "last_page": 1, "per_page": 30, "total": 2},
  "limits": {"daily_cost_cap_usd": 5.0, "spent_today_usd": 0.4213, "cap_reached": false, "max_steps": 10, "max_input_tokens": 200000, "max_proposals_per_turn": 3}
}
Chat = {
  "id": 3, "title": "Warranty change",
  "restrictions": {
    "exclude_playbook_ids": [7], "exclude_targets": [], "only_playbook_ids": null, "only_targets": null,
    "sources": [{"from": "parser", "kind": "exclude", "text": "refund rules", "matched": true, "playbook_ids": [7], "targets": [], "at": "..."}],
    "exclude_playbooks": [{"id": 7, "slug": "refund_request"}], "only_playbooks": null
  },
  "message_count": 4, "running": false,
  "tokens": {"in": 52000, "out": 3100}, "cost_usd": 0.2700,
  "last_message_at": "...", "created_at": "...", "updated_at": "..."
}
```

### `POST /agent/manager/chats` (throttle 20/min)

Body `{title?, restrictions?: {exclude_playbook_ids?: int[], exclude_targets?: string[], only_playbook_ids?: int[]|null, only_targets?: string[]|null}}` → `201 {chat: Chat, messages: []}`. Unknown playbook ids are dropped.

### `GET /agent/manager/chats/{chat}`

→ `{chat: Chat, messages: Message[] (oldest first), limits}`. Poll this (or listen for events) while `chat.running`.

```jsonc
Message = {
  "id": 12, "chat_id": 3, "role": "assistant", "status": "completed",   // pending | running | completed | failed | capped
  "content": "I updated the warranty rule in [playbook:4] and staged [proposal:31] …",
  "reply_to_id": 11,
  "tool_calls": [{"step": 1, "tool": "get_playbook", "summary": "Reading playbook damaged_product", "ok": true, "refused": false, "arguments": {"playbook": "damaged_product"}}],
  "citations": [{"type": "playbook", "id": 4, "label": "Damaged product", "source": "read"}, {"type": "proposal", "id": 31, "label": "Warranty: genuine faults only", "source": "staged"}],
  "proposal_ids": [31],
  "suggestions": [{"type": "forbidden", "target_type": "playbook", "target_id": 4, "op": {"op": "set_field", "field": "mode", "value": "auto"}, "reason": "…", "proposal_id": 31}],
  "restrictions": {...} | null,
  "limits": {"tool_calls": 6, "step_cap": 10, "step_cap_reached": false, "estimated_input_tokens": 41000, "token_budget": 200000,
             "token_budget_reached": false, "deadline_reached": false, "unknown_citations": [], "prompt_version": "manager-chat-v1"} | null,
  "error": null, "model": "claude-opus-5-5",
  "tokens": {"in": 41000, "out": 2100, "cache_read": 0, "cache_write": 0, "reasoning": 0},
  "cost_micro_usd": 206000, "cost_usd": 0.206, "latency_ms": 48000,
  "created_at": "...", "completed_at": "..."
}
```

User messages have `role: "user"`, `status: "completed"`, `restrictions` = what the parser found in that request (null when nothing), and no tool / cost fields filled.

### `POST /agent/manager/chats/{chat}/messages` (throttle 10/min)

Body `{content}` (2-8,000 chars) → `202 {message: Message (user), reply: Message (assistant, pending), chat: Chat}`.

- `409 {message, reason: "turn_running", reply}`: the previous turn is still pending / running.
- `409 {message, reason: "daily_cap", limits}`: today's manager spend reached the cap.
- `422` validation.

### `PATCH /agent/manager/chats/{chat}`

Body `{title?, restrictions?}` → `{chat}`. `restrictions` **replaces** the chat's restrictions (human edit; the only way to loosen them).

### `DELETE /agent/manager/chats/{chat}`

→ `{deleted: true}`.

### Events (private channel `App.Models.User.{owner id}`)

`App\Domains\Agent\Events\ManagerChatUpdated` (`ShouldBroadcastNow`). Echo: `Echo.private('App.Models.User.' + id).listen('.agent.manager.step', …)`.

| `broadcastAs` | payload | when |
|---|---|---|
| `agent.manager.started` | `{chat_id, message_id}` | the turn starts running |
| `agent.manager.step` | `{chat_id, message_id, step, tool, summary, ok, refused}` | after every tool call (progress line, e.g. "Reading run #12") |
| `agent.manager.finished` | `{chat_id, message_id, status, proposal_ids}` | completed / failed / capped. Re-fetch `GET /chats/{id}` |

The answer is not streamed token by token: the tool loop runs as one non-streaming call (the faked-HTTP tests depend on it, and laravel/ai streaming with custom tools was not verified against Anthropic). Progress is streamed per tool call.

---

## Unverified (no live model in tests)

- Answer and proposal **quality** (finding every playbook / knowledge item a policy change touches, minimal ops, sensible citations) is unverified by construction; tests fake the model at the HTTP layer and only prove the guardrails.
- The token budget is an estimate (chars / 4); the real count is recorded after the call.
- The parser is heuristic: it can miss a restriction phrased differently (the model is told to record it with `add_restriction`) and can over-restrict (a human can edit the chips).
