# Agent HTTP API

Routes live in `routes/agent.php` (loaded by `App\Domains\Agent\AgentServiceProvider` inside the `web` middleware group, so session auth + CSRF apply). TypeScript types for every payload below are in `resources/js/types/agent.ts`.

Authorization:

| Routes | Who |
|---|---|
| `/agent/settings`, `/agent/voice*`, `/agent/playbooks*`, `/agent/knowledge*` | `manage-settings` gate (admin, super admin). Others get 403. Rows from another workspace are 404. |
| `GET /agent/conversations/{conversation}/run` | Anyone who passes `ConversationPolicy::view` (same workspace, mailbox access). |
| `POST /agent/conversations/{conversation}/regenerate` | `ConversationPolicy::update`. Throttled 10/min. |

Unauthenticated JSON requests get 401; unauthenticated page requests redirect to `/login`.

---

## JSON endpoints (for the conversation draft panel)

### `GET /agent/conversations/{conversation}/run`

Query: `purpose` = `live` (default) or `shadow`.

Returns the latest run of that purpose for the conversation.

```jsonc
{
  "agent_mode": "draft",            // off | shadow | draft | live (workspace setting)
  "run": null | {
    "id": 123,
    "purpose": "live",              // live | shadow | preview | simulation | test
    "status": "completed",          // running | completed | failed
    "decision": "draft",            // draft | handover | null (failed / running)
    "handover_reason": null,        // machine-readable, see "Handover reasons"
    "handover_label": null,         // human-readable sentence fragment for the reason
    "draft": "Hi Alex, ...",        // null when decision = handover (never a made-up answer)
    "internal_note": "Why: ...",    // for staff; on handover: why it stopped + what it found
    "ai_suggestion_id": 456,        // the AiSuggestion row the existing UI shows (live drafts only)
    "customer_thread_id": 789,      // the customer message this run answered
    "playbook": { "id": 1, "slug": "order_status", "name": "Order status", "version_id": 10, "version": 2 } | null,
    "triage": {
      "language": "en", "sentiment": "calm",       // calm | frustrated | angry | unknown
      "risk_flags": [],                             // chargeback | legal | abuse | prompt_injection | fraud | safety | triage_error
      "playbook": "order_status",                   // chosen slug or null
      "confidence": 0.9, "reason": "Asks where the order is.",
      "error": null,                                // set when triage failed
      "heuristics": []                              // code-detected injection patterns
    },
    "rules_used": [ { "id": "r-1a2b3c4d", "if": "always", "then": "Quote the status exactly." } ],
                                    // if/then are null when the id no longer exists in the version
    "claims": [
      {
        "text": "Order SD8421 is paid and not yet shipped",
        "sources": [
          { "id": "E5", "type": "evidence", "source": "shopify", "label": "Fulfilment status", "value": "UNFULFILLED" },
          { "id": "K12", "type": "knowledge", "kind": "policy", "title": "Dispatch times", "body": "...", "knowledge_item_id": 12 },
          { "id": "X", "type": "unknown" }
        ]
      }
    ],
    "proposed_actions": [
      { "action": "refund", "order_number": "SD8421", "reason": "...", "details": "...", "status": "proposed" }
                                    // status: proposed | rejected_not_allowed | rejected_unknown_action. Nothing is ever executed.
    ],
    "validator": {
      "passed": true,               // true | false | null (never ran)
      "error": false,               // the LLM validator threw (=> handover validator_error)
      "rewrites": 0,                // 0 or 1
      "deterministic": [            // one list of issues per validation round
        [ { "check": "money", "value": "$49.95", "problem": "The amount $49.95 does not appear ..." } ]
      ],                            // check: empty | unknown_source | link | link_domain | money | date | discount_code | identifier | voice | length
      "llm": { "verdict": "pass", "issues": [ { "quote": "...", "problem": "..." } ] } | { "error": "..." } | null,
      "rejected_draft": null        // the last draft when the run handed over (staff eyes only)
    },
    "evidence": {
      "facts": [ { "id": "E1", "source": "conversation", "key": "customer.name", "label": "Customer name (helpdesk)", "value": "Alex Buyer" } ],
                                    // source: conversation | customer (quoted, unverified) | shopify | tracking | supplied
      "notes": [ "No verified Shopify order. Reason: ..." ],
      "guidance": [ "The carrier last scanned this parcel 16 days ago ..." ],
      "meta": {                     // order_found, order_email_match, order_withheld, tracking_found,
                                    // shopify_snapshot_id, tracking_snapshot_id, source (live|recorded|supplied|none)
      }
    },
    "knowledge": [ { "id": "K12", "kind": "policy", "title": "...", "body": "...", "scope": { "playbooks": [], "products": [], "countries": [], "global": true } } ],
                                    // every knowledge item the writer saw (prefetched + searched)
    "related_conversations": [ { "id": 812, "status": "open", "waiting_on_us": true } ],
                                    // the same customer's other conversations the writer was shown ([] when none)
    "tokens": { "in": 4500, "out": 450, "cache_read": 0, "cache_write": 0, "reasoning": 0 },
    "cost_micro_usd": 17650,        // integer micro-dollars (config/agent.php prices)
    "cost_usd": 0.01765,
    "latency_ms": 8123,
    "models": { "triage": "claude-haiku-5-5", "writer": "claude-opus-5-5", "validator": "claude-sonnet-5-5" },
    "config_hash": "sha256...",
    "error": null,                  // set when status = failed
    "feedback": [ { "id": 1, "kind": "sent_edited", "percent_changed": 12, "thread_id": 99, "created_at": "..." } ],
    "created_at": "2026-11-26T10:00:00+00:00",
    "completed_at": "2026-11-26T10:00:08+00:00"
  }
}
```

### `POST /agent/conversations/{conversation}/regenerate`

No body. Queues a forced live run (`GenerateReplySuggestionJob` with `regenerate: true`) that ignores the "already ran for this message" idempotency and the "already answered" preflight.

- `202 {"queued": true}`
- `409 {"message": "...", "agent_mode": "off"}` when the agent mode is not `draft`/`live`, or the kill switch is on.

When the run finishes the existing events fire on `private-conversation.{id}`:

- draft: `ai.suggestion.ready` with `{conversation_id, suggestion_id, content, run_id}` (`run_id` is new; null for legacy suggestions)
- handover: `ai.suggestion.failed` with `{reason: "The AI agent handed this conversation to a human: ..."}`, plus `thread.created` for the internal note (a `note` thread whose `meta` has `agent_run_id` and `handover_reason`).

Then re-fetch `GET .../run`.

### `POST /ai/conversations/{conversation}/suggest-reply` ("AI Suggest")

`ConversationPolicy::update`. With the legacy drafter removed this always uses the agent pipeline:

- mode `draft` / `live`: the same as an automatic draft (the frontend calls `regenerate` instead).
- mode `off` / `shadow`: a **one-off manual live draft** (`GenerateReplySuggestionJob` with `manual: true`, `PipelineInput::$manual`). A person asked, so it runs although the mode does not draft on its own; it is marked requested by a human, so the gate never auto-sends it (`requested_by_human`), and nothing runs unattended outside `live`. The mode is not changed.
- `200 {"status": "queued"}`; the result arrives as `ai.suggestion.ready` / `ai.suggestion.failed` like any run.
- `409 {"message": "The AI agent kill switch is on, ..."}` when the kill switch is on (nothing is queued).

Automatic drafting (new mail, chat, webhooks) still follows the mode: `off` = nothing, `shadow` = a shadow run only.

### Sending a reply while the draft waits on a Shopify action

`POST /conversations/{conversation}/threads` (the composer) and `POST /api/conversations/{conversation}/reply` refuse a **message** while the conversation's current agent draft (latest live run that drafted and was not answered yet) has `ActionGate::blocksSending($run)` true: a reply-blocking action is waiting for approval, still running, or failed. The response is a validation error on `send_anyway` (422 JSON; Inertia `errors.send_anyway`). It is accepted only when the person **changed the text** and sends `send_anyway: true` after confirming; that send is logged (`agent-actions` activity log, event `reply_sent_despite_actions`). Notes are never checked. Code: `App\Domains\Commerce\Actions\ReplySendGuard`. The composer sends the flag after its "Send this reply anyway?" confirmation.

### Handover reasons

`kill_switch`, `triage_error`, `no_instruction` (no playbook fits), `risk:<flag>`, `always_human`, `playbook_off`, `order_email_mismatch` (order email is not the sender / Reply-To customer; order details withheld), `missing_evidence:<order|tracking|photos,...>`, `writer:<needs_human_decision|policy_unclear|missing_information|customer_requests_human|sensitive|other|writer_error|...>`, `validator_error`, `validation_failed`, `no_draft`. The same value is written to `conversations.handover_reason` (cleared when a later run drafts).

---

## Inertia pages (admin)

### Screen pages that share a URL with a JSON index

`GET /agent/proposals`, `/agent/simulations`, `/agent/mining`, `/agent/actions/policies` and `/agent/actions/audit` serve both (middleware `page:` = `App\Http\Middleware\RenderPageUnlessJson`): an Inertia visit (`X-Inertia` header) or a plain browser navigation gets the page component with no props; a request that wants JSON (axios sends `Accept: application/json, text/plain, */*` and `X-Requested-With`) gets the JSON index exactly as before; `?format=csv` always falls through (audit download). Admin-only pages check `manage-settings` before rendering (403 page).

| URL | Component |
|---|---|
| `/agent/proposals` | `Agent/Proposals/Index` |
| `/agent/simulations` | `Agent/Simulations/Index` |
| `/agent/mining` | `Agent/Mining/Index` |
| `/agent/manager` | `Agent/Manager/Index` |
| `/agent/qa` | `Agent/Qa/Index` |
| `/agent/reports` | `Agent/Reports/Index` |
| `/agent/shadow` | `Agent/Shadow/Index` |
| `/agent/actions` | `Agent/Actions/Index` (approvals queue; data from `GET /agent/actions/executions`, which checks the approver role) |
| `/agent/actions/policies` | `Agent/Actions/Policies` |
| `/agent/actions/audit` | `Agent/Actions/Audit` |

All but `/agent/actions` need `manage-settings`.

All forms post with Inertia (`router.post/patch`); success redirects back with `flash.success`; validation errors arrive in `errors`.

### Settings: `GET /agent/settings` -> `Agent/Settings`

Props: `settings {mode, kill_switch, live_voice_version_id, caps {auto_send_per_day, auto_send_per_customer_per_day, actions_per_day}, auto_send_delay_seconds, link_allowlist[]}`, `modes[]`, `default_link_domains[]` (Shopify shop domain + carrier domains, always allowed), `autonomy_enforced: true`, plus the auto-send props in [agent-autosend.md](agent-autosend.md).

`PATCH /agent/settings`: `mode` (required, off|shadow|draft|live; switching to live needs `confirm_live: true`), `kill_switch` (required bool), `auto_send_delay_seconds?`, `caps?`, `link_allowlist?` (domains), `auto_send?` (see agent-autosend.md).

### Voice: `GET /agent/voice` -> `Agent/Voice`

Props: `live: VoiceVersion|null`, `versions: (VoiceVersion & {is_live})[]` newest first. `VoiceVersion = {id, version, personality, do_phrases[], dont_phrases[], greetings[], sign_offs[], max_words, length_guidance, is_starter, change_note, created_by, restored_from_id, created_at}`; list items are `{id, text}`.

- `POST /agent/voice`: `personality` (required), `do_phrases[]`, `dont_phrases[]`, `greetings[]`, `sign_offs[]` (each `{id?, text}`; send the existing `id` to keep an item's identity), `max_words?` (20-2000), `length_guidance?`, `change_note?`. Creates version N+1 and makes it live.
- `POST /agent/voice/versions/{version}/restore`: creates a new version copied from `{version}` and makes it live.

`dont_phrases` are enforced in code: a draft containing one fails validation.

### Playbooks

- `GET /agent/playbooks` -> `Agent/Playbooks/Index`, props `playbooks: (PlaybookSummary & {when_text, rule_count})[]`.
- `GET /agent/playbooks/create` and `GET /agent/playbooks/{playbook}` -> `Agent/Playbooks/Edit`, props `playbook: PlaybookSummary|null`, `live: PlaybookVersion|null`, `versions: (PlaybookVersion & {is_live})[]`, `modes`, `auto_mode_available: true`, `auto_send` (show only: preconditions, breakers, history, rollout preview), `actions: {key: label}`, `evidence_kinds: [order, tracking, photos]`.
- `POST /agent/playbooks`: `name`, `mode` (off|draft), `slug?` (defaults to snake-cased name), optional settings, plus the content fields below.
- `PATCH /agent/playbooks/{playbook}`: settings, not versioned: `name?`, `mode?` (off|draft|auto; `auto` needs `confirm_auto: true` and the preconditions in agent-autosend.md), `rollout_percent?` (0-100), `always_human?`, `priority?`.
- `POST /agent/playbooks/{playbook}/versions`: content, creates version N+1 and makes it live: `when_text` (required: what triage matches on), `rules[] {id?, if?, then}` (send the existing `id` to keep it; missing `if` = always), `steps[] {id?, text}`, `allowed_actions[]` (keys of `config('agent.actions')`), `required_evidence[]` (order|tracking|photos: hand over when missing), `escalation?`, `change_note?`.
- `POST /agent/playbooks/{playbook}/versions/{version}/restore`.

`PlaybookSummary = {id, slug, name, mode, rollout_percent, always_human, priority, live_version_id, live_version, archived_at}`; `PlaybookVersion = {id, version, when_text, rules, steps, allowed_actions, required_evidence, escalation, change_note, created_by, restored_from_id, created_at}`.

### Knowledge

- `GET /agent/knowledge` -> `Agent/Knowledge/Index`. Query filters: `status` (active|needs_scope|draft|retired), `kind` (fact|policy|kb_chunk|snippet), `playbook` (slug in scope), `scope` (global|scoped), `q`. Props: `items` (Laravel paginator, 50/page, `needs_scope` first; `data: KnowledgeItem[]`), `filters`, `counts {status: n}`, `kinds`, `statuses`, `playbooks [{slug, name}]`. The needs-scope queue is `?status=needs_scope`.
- `GET /agent/knowledge/create`, `GET /agent/knowledge/{item}/edit` -> `Agent/Knowledge/Edit`, props `item: KnowledgeItem|null` + the option lists.
- `POST /agent/knowledge`, `PATCH /agent/knowledge/{item}`: `kind`, `title?`, `body`, `scope {global: bool (required), playbooks[], products[], countries[] (ISO-2)}`, `valid_from?`, `valid_until?`, `status?`, `supersedes_id?` (that item is retired when this one is active). A scope that is not global and names nothing is rejected. Saving a `needs_scope` item with a scope and no explicit `status` activates it.

`KnowledgeItem = {id, kind, title, body, scope, valid_from, valid_until, status, supersedes_id, source_document_id, chunk_index, source_type, has_embedding, created_at, updated_at}`. `kb_chunk` items mirror knowledge-base documents: editing the document re-chunks them (scope and status of an existing chunk are kept).

---

<!-- agent-UI section: overview, connections, conversation order panel -->
## Overview, connections and the order panel

Routes at the end of `routes/agent.php`. Controllers: `App\Domains\Agent\Http\Controllers\OverviewController`, `App\Domains\Commerce\Http\Controllers\{ConnectionsController, ConversationCommerceController}`.

### `GET /agent` -> `Agent/Overview` (admin)

Read-only aggregates over the last 7 days (today included): `settings {mode, kill_switch}`, `connections {shopify, tracking}`, `counts {playbooks_active, playbooks_total, knowledge_active, knowledge_total, knowledge_needs_scope}`, `runs {total, live, shadow, draft, handover, auto_send, failed, top_handover_reasons[{reason, label, count}] (top 5), daily[{date, draft, handover, auto_send}] (7 entries)}` (live + shadow runs only; `auto_send` = runs the gate decided to auto-send, cancelled ones included), `actions {proposed, approved, unattended, succeeded, failed, rejected, refunded_amount, refunded_currency}` (live Shopify action executions proposed in the window, `App\Domains\Commerce\Actions\ActionStats`; `refunded_amount` sums succeeded refund / cancel-order executions; `refunded_currency` is null, the currency, or `mixed`), `feedback {sent_as_is, sent_edited, gmail_reply, rejected, edit_rate (sent_edited / (sent_as_is + sent_edited), null when none), avg_percent_changed}`, `cost {total_usd, per_run_usd, by_purpose[{purpose, runs, usd}]}` (every purpose).

### `GET /agent/connections` -> `Agent/Connections` (admin)

Props: `providers {key: label}`, `settings {shopify_shop_domain, shopify_resolved_domain, shopify_client_id, has_shopify_client_secret, shopify_configured, shopify_token_expires_at, shopify_granted_scopes[], shopify_api_version, tracking_provider, has_tracking_api_key, tracking_configured, tracking_store_uuid, tracking_store_uuid_default, tracking_stale_days, preorder_tags}`. Secrets are never sent.

- `PATCH /agent/connections`: `shopify_shop_domain?`, `shopify_client_id?`, `shopify_client_secret?` (blank keeps the stored one), `shopify_api_version?` (`YYYY-MM` or `unstable`), `tracking_provider?` (omitted keeps the current one; `none` deletes the stored key), `tracking_api_key?` (blank keeps), `tracking_store_uuid?`, `tracking_stale_days` (required, 1-90), `preorder_tags?` (comma separated). A new secret, client id or shop domain clears the cached token and requests a new one; failure is flashed as `error`.
- `POST /agent/connections/test-shopify` -> `{ok, message, shop?, scopes?[], expires_at?}` (mints a new token; throttled 10/min; the page never calls it on load).
- `POST /agent/connections/test-tracking` -> `{ok, message, provider?}` (throttled 10/min).

### `GET /commerce/conversations/{conversation}` (ConversationPolicy::view)

Stored snapshots only, never calls Shopify: `{configured {shopify, tracking}, shopify: null | {found, has_order, reason, matched_by (order_number|email|null), order_email_matches, order_email, customer_name, order_number, order_date, order_age_days, payment_status, fulfilment_status, tracking_number, tracking_url, tracking_company, shipping_country, cancelled, refunded, products[{title, variant, quantity, preorder}], fetched_at, error}, tracking: null | TrackingSnapshot::toUiArray() + {stalled}, stale_days}`. `configured.shopify = false` means "render nothing".

- `POST .../refresh-shopify`, `POST .../refresh-tracking` (ConversationPolicy::update, throttled 20/min): look up again (tracking is forced past the freshness window) and return the same shape.
<!-- end agent-UI section -->

<!-- history mining -->
## History mining

`/agent/mining*` (JSON, `manage-settings`): estimate, start, follow, pause / resume / cancel a mining run that turns imported history into proposals. Payloads and stages: [docs/agent-mining.md](agent-mining.md).
<!-- end history mining -->

<!-- feedback loop -->
## Feedback loop, proposals, test cases and simulation

JSON only; full payloads, statuses and event names in [docs/agent-proposals.md](agent-proposals.md#http-api).

| Routes | Who |
|---|---|
| `POST /agent/conversations/{conversation}/feedback`, `GET /agent/conversations/{conversation}/feedback`, `GET /agent/feedback/{id}`, `POST /agent/feedback/{id}/scope`, `POST /agent/feedback/{id}/answer`, `POST /agent/suggestions/{suggestion}/dismiss` | `ConversationPolicy::view` |
| `GET /agent/playbooks/options` | any signed-in user (own workspace; id, slug, name, mode) |
| `GET /agent/proposals/{id}`, `POST /agent/proposals/{id}/preview` | `manage-settings`, or `view` of the proposal's conversation |
| `/agent/proposals*` (list, check, resolve-conflict, gate, exclude, exclusions/restore, approve, reject, rebase, revert), `/agent/test-cases*`, `POST /agent/playbooks/{playbook}/tests/run`, `/agent/simulations*`, `/agent/frozen-cases*` | `manage-settings` |

- Feedback on a draft: `POST /agent/conversations/{conversation}/feedback {run_id, text, selected_text?, scope_override?}` → 202; routing (manager model), proposal, conflict check and preview run on the `ai` queue. Listen on `private-workspace.{id}` for `agent.feedback.routed` and `agent.preview.ready`, then `GET /agent/feedback/{id}`.
- The inbox Dismiss button: `POST /agent/suggestions/{suggestion}/dismiss {reason?}` records a `rejected` feedback event (`meta.reason = "dismissed"`).
- After a reload: `GET /agent/conversations/{conversation}/feedback` → `{conversation_id, feedback: Feedback[], proposals: Proposal[]}` (latest first) restores the feedback box.
- Partial approval: `POST /agent/proposals/{id}/exclude {change_ids?, ops?: [{change_id, op_index}], note?, recheck?}` leaves changes / single ops out (recorded in `proposal.excluded` and `applied.excluded`); `POST /agent/proposals/{id}/exclusions/restore {ids?}` puts them back.
- Test cases carry `last_result {verdict, raw_verdict, human_override, simulation_id, result_id, at}`; `GET /agent/simulations?playbook_id=`; result rows carry `playbook` / `ai_playbook` `{id, slug, name}`.
- New run purposes in use: `preview` (feedback previews: never writes an AiSuggestion or touches the conversation) and `simulation` (frozen cases: no conversation at all).
<!-- end feedback loop -->

<!-- auto-send -->
## Auto-send and rollout

`/agent/auto-sends/{thread}/cancel` and `/wrong` (conversation access), `/agent/autosend/pause|resume|shadow-report` and `/agent/playbooks/{playbook}/autosend` (`manage-settings`); `run.decision` may now be `auto_send` and `run.auto_send` carries its lifecycle. Settings: mode `live` (with `confirm_live`), auto-send options, pause. Playbooks: `mode: auto` with `confirm_auto` and the preconditions. Full contract, gate conditions, breakers and the go-live checklist: [docs/agent-autosend.md](agent-autosend.md).
<!-- end auto-send -->

<!-- shopify actions -->
## Shopify actions (approval and unattended)

`/agent/actions/*` (routes in `routes/agent_actions.php`, JSON): catalogue with scope availability (any user), policies and approver settings (`manage-settings`), the approvals queue, execution detail, approve / reject / refresh (approver role per `agent_action_settings`), audit log + CSV (`manage-settings`), per-conversation executions and the reply-coupling state (`ConversationPolicy::view`). Event `agent.action.updated` on `private-workspace.{id}` and `private-conversation.{id}`. `run.proposed_actions[]` entries now carry `params`, `execution_id`, `execution_status` and the statuses `rejected_unavailable | rejected_disabled | rejected_invalid_params`. Full contract, state machine, policy rules and the dev-store test script: [docs/agent-actions.md](agent-actions.md).
<!-- end shopify actions -->

<!-- manager chat, QA, report -->
## Manager chat, QA reviews and the AI performance report

All `manage-settings`, JSON (one CSV). Routes in `routes/agent.php` (block "Manager chat, QA reviews, AI performance report").

- `/agent/manager/chats*`: list / create / show / patch (title, scope restrictions) / delete chats; `POST /agent/manager/chats/{chat}/messages {content}` → 202, the turn runs on `ai` and streams `agent.manager.started|step|finished` on `private-App.Models.User.{id}`. The manager only stages draft proposals (origin `manager`). Contract: [docs/agent-manager.md](agent-manager.md).
- `/agent/qa/reviews` (list, show, `POST …/{id}/verdict`), `POST /agent/qa/conversations/{conversation}/review`, `/agent/qa/capability-requests`, `/agent/qa/gaps`, `/agent/qa/aggregates`: QA reviews of closed conversations (AI and human replies); recurring gaps become one proposal (origin `qa`). Contract: [docs/agent-qa.md](agent-qa.md).
- `GET /agent/reports/performance` and `/agent/reports/performance.csv` (`from`, `to`, `group=day|week`, `playbook_id`): the AI performance report, metric definitions in [docs/agent-qa.md](agent-qa.md#report).
<!-- end manager chat, QA, report -->
