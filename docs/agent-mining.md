# History mining

Turns imported support history into **proposals** (origin `mining`): a brand voice, playbook drafts or field-level changes per situation, knowledge items, an inconsistencies summary and test cases. Nothing goes live: every result is a draft proposal in the review screen (docs/agent-proposals.md).

Code: `app/Domains/Agent/Mining`. Config: `config/agent_mining.php`. Migration: `database/migrations/2026_11_28_300000_create_agent_mining_tables.php`. Command: `php artisan agent:mine`. HTTP: "API" below.

## Tables

| Table | What |
|---|---|
| `agent_mining_runs` | One run: `options {since, until, max_conversations, budget_usd, sample_strategy}`, `status` (queued / running / paused / completed / failed / cancelled), `stage` (select / extract / cluster / synthesise / voice / emit / done), `counters`, `usage {stage: {calls, cache_hits, tokens_in, tokens_out, cost_micro_usd}}`, `tokens_in`, `tokens_out`, `cost_micro_usd`, `budget_micro_usd`, `pause_reason` (user / budget), `message`, `results` (estimate, clustering, voice, inconsistencies, proposals), `job_token`, `error_count`, `consecutive_errors`, `last_error`, heartbeat. At most one unfinished run per workspace (partial unique index). |
| `agent_mining_conversations` | The run's selection: `conversation_id`, `bucket` (YYYY-MM), `status` (pending / done / failed), `outcome` (new / reused / macro / no_human_reply), `extraction_id`, `attempts`, `error`. |
| `agent_mining_extractions` | Per conversation, shared by all runs, **unique (conversation_id, content_hash, extractor_version)**: `status` (ok / not_support / macro), `situation`, `language`, `outcome`, `data` (scrubbed structured extraction, no mail text), `macro_hash`, `macro_of_id`, `staff_keys`, dates, model, tokens, cost. |
| `agent_mining_situations` | The run's clusters: `key`, `name`, `description`, `playbook_id` (null = new situation), `labels {label: count}`, `extraction_ids`, `conversation_count`, `status` (pending / synthesised / failed / too_small / long_tail), `synthesis` (scrubbed), `attempts`, `error`. |
| `agent_mining_cache` | Structured output of merge / synthesis / voice calls keyed by `(workspace, kind, input_hash)`; the hash covers the input data and the prompt version, never the rendered prompt (which carries a random nonce). |

## Stages

A run is a chain of `RunMiningStepJob`s on `redis-long` / `ai-bulk` (tries 1, timeout 840 s), exactly like the mail backfill: each step works ~`queue.step_seconds` (120 s) in checkpointed slices, then queues the next one `throttle_seconds` later. Errors back off 30 s, 60 s ... 15 min into `last_error`; after `max_consecutive_errors` (8) the run is `failed` and **resume** carries on from the checkpoint. Pause takes effect after the current slice. Every resume gets a new `job_token`; stale chains exit. `agent:mine --recover` (scheduled every five minutes) re-queues runs without a heartbeat for `stall_minutes`.

**A. select** (one transaction). Closed conversations of the workspace (imported and live), not deleted, last activity inside the window, with a customer message and a human staff reply. A staff reply is not human when `origin = ai`, when it was sent as the AI drafted it (`agent_feedback_events.kind = sent_as_is`), or (checked per turn) when it is word for word an accepted legacy AI suggestion. Over the cap, `stratified` (default) splits the window by calendar month: `selection.floor_share` (30 %) of the cap is spread evenly over the months, the rest goes by month size times a weight rising linearly from 1 (oldest month) to 1 + `recency_boost` (2, newest). Inside a month the order is a stable pseudo-random one, so the same options select the same conversations again. `newest` takes the newest N.

**B. extract** (role `history_extraction`, Haiku), newest first, one conversation per slice:
1. an extraction with the same content hash (every turn's role, time, text, origin, human flag) and `extractor_version` exists: reused, no call;
2. the conversation has exactly one human reply and its normalised hash (`MacroDetector`: greeting line and sign-off dropped, case / digits / punctuation / spacing folded, 80+ characters) matches an extracted one: copied as `macro` with `macro_of_id`, no call;
3. otherwise **one call per conversation**. Batching several conversations per call would save only the shared system prompt (~1,100 tokens, about $0.0001 per conversation on Haiku) and costs quality: attribution errors between conversations, one bad conversation spoiling the batch, truncation of long outputs, and a larger retry unit. So it is not batched.

The transcript is rendered with identifiers scrubbed (`PiiScrubber`: customer names, emails, phones, addresses, order and tracking numbers become `[name]`, `[email]`...) and placed in a nonce-delimited untrusted block in the **user** message; the system prompt holds only the fixed instructions and the playbook catalogue (slug + when text). AI-sent turns are marked "not a human decision". Output: category (support / spam / vendor / marketing / personal / other: non-support is dropped and counted as `dropped.not_support_*`), situation label (an existing slug, or a new snake_case label), what the customer asked, decisions (`refund_full`, `replacement`, `asked_for_photos`, `gave_tracking`, `declined`, `escalated`...), policy / fact statements with dates (clamped to the conversation's dates), tone (greeting, sign-off, formality, humour, empathy, apology, emoji, phrases, measured words per reply), outcome (thanked / resolved_quietly / came_back_unhappy / unresolved / unclear), language, exemplary. Every text field is scrubbed and post-checked before it is stored.

A failing call is retried with the run's backoff; after `extract.max_attempts` (3) the conversation is `failed` and the run moves on.

**C. cluster** (one pass, recomputed if interrupted). Labels equal to a playbook slug (dots / underscores, word order and plurals ignored) map to that playbook; the remaining labels are grouped by a loose key, then merged semantically: with embeddings configured (`EmbeddingService`), cosine ≥ `cluster.similarity` (0.82) to a playbook's "slug: when text", else leader clustering; **with embeddings off**, one cheap `history_extraction` call (`MergeSituationsAgent`, cached) maps each label to an existing slug or a new situation, aiming at ≤ `max_situations` (30). If that call fails the loose groups stand (`clustering.method`: exact / embeddings / model / loose). Situations under `min_conversations` (3) are `too_small`; beyond the 30 largest, and `other` / `spam`, are `long_tail`. Neither is synthesised; both appear in coverage.

**D. synthesise** (role `manager`, Opus), one call per situation, cached by input. Sample (`synthesis.sample_size` 40): unusual cases first (customer came back unhappy or unresolved, decisions used in under 15 % of the situation) up to 30 %, then half from the newest third, 30 % from the middle, 20 % from the oldest, spread within each tier; a canned reply counts once with its number of uses. Every statement of the situation (deduplicated, newest first, up to 80) is included for inconsistencies. The current live playbook version (human-approved) is in the system prompt; the sample (scrubbed excerpts of the first customer message and the human reply, plus the extraction) is untrusted data in the user message. Checked in code:
- evidence ids must be conversations of this situation; a rule or step left without evidence is dropped (`dropped.rule_no_evidence`);
- `replace` / `retire` must name a rule / step id of the live version (unknown replace becomes add, unknown retire is dropped);
- knowledge scope: playbook slugs must exist or be this situation's; an empty scope becomes this situation's playbook; `last_seen` = newest human reply among the evidence;
- inconsistencies need two versions with evidence; versions are ordered by last seen, `most_recent` first;
- suggested actions are kept only as advice;
- 5-10 test cases from the sample (topped up with representative, resolved cases when the model gives fewer), `expected_actions` derived from the human's decisions (refund → `refund`, replacement / reship → `replacement`, discount → `discount_code`, cancelled → `cancel_order`, address change → `change_address`);
- every text is scrubbed and post-checked; rules, steps, knowledge, inconsistency statements and test-case points are **strict**: if the scrubber had to remove anything (a name, an order number...), the item is about one case and is dropped (`dropped.pii_<kind>`).

**E. voice** (role `manager`, one call, cached). ~`voice.sample_size` (150) human replies: every staff member gets at least min(their replies, 30 %), the rest by share, round-robin across situations, newest first, canned copies excluded. Each reply is scrubbed and cut to 900 characters, labelled with the staff member's name and the situation. Output: ops on the live voice version (add / replace / retire with item ids), personality, max words, length guidance, staff differences, up to 15 verbatim example phrases (scrubbed; do / don't items strict).

**F. emit** (one proposal per slice) through `ProposalService` (docs/agent-proposals.md):
- **situation** `situation:{key}`: existing playbook → `add_rule` / `replace_rule` / `remove_rule` / `add_step` / `replace_item` / `remove_item` / `set_field` (when_text, escalation, required_evidence) on its live version; new situation → `create_playbook` (draft, no actions) with the rules and steps. Plus `create_knowledge` per knowledge item and `create_test_case` per test case on a `FrozenCaseFactory::fromConversation(..., 'mining')` case cut at the customer message the first human reply answered (cases become active only on approval). Suggested actions go to `proposal.suggestions` as `{op: {op: "allow_action", action, playbook_slug, evidence}, reason}`: never enabled. Evidence: `conversation_ids`, `rules[{op, rule_id, then, conversation_ids}]`, `knowledge[{title, last_seen, conversation_ids}]`, `inconsistencies`, `suggested_actions`, `test_case_conversation_ids`, `coverage`, `mining {key, run_id, content_hash}`.
- **voice** `voice`: one voice change (`add_do`, `add_dont`, `add_greeting`, `add_sign_off`, `replace_item`, `remove_item`, `set_field`); evidence `quotes[]` (example phrases), `staff`, `staff_differences`, `sample_size`.
- **summary** `summary`: the inconsistencies of every situation; one `create_knowledge` op per disputed topic stating the most recent version (the disputed knowledge item is left out of the situation proposal so it is not proposed twice, unless only the new playbook of that proposal can scope it). Evidence `inconsistencies[{topic, summary, versions[{statement, evidence, last_seen}], most_recent, recommendation, situation, situation_name}]`, `conversation_ids`. No summary when nothing disagrees.
- Ops that fail validation are dropped (`dropped.invalid_op`) and the rest retried.

**Idempotent re-runs.** For each key the newest mining proposal of the workspace is looked up by `evidence.mining.key`: same changes hash → left alone (`unchanged` / `unchanged_reviewed`); open (draft / ready / stale) → `replaceChanges` plus new title, rationale, evidence (`updated`); `testing` (gate running) → left alone (`skipped_in_review`); applied / rejected / reverted are **never touched**, a different result becomes a new proposal (`created`). A re-run on unchanged data reuses every extraction and every cached model result: zero calls, zero cost, no new proposals.

## PII

`PiiScrubber::scrub()` → placeholders; `violations()` is a broader post-check (any `@` address, any run with 9+ digits that is not a date, `#123`, store prefix + digits, a token with 8+ digits, street / PO box / "NSW 2000" / UK postcode, the conversation's customer names); `clean()` = scrub + post-check, null when it still fails (item dropped, `dropped.pii_<email|phone|address|order_number|tracking_number|customer_name>`). Customer names come from the customers and the From names of the conversations involved; a name matches as written, Title Case or UPPER CASE, and names that are common words (Will, May, Post, Price...) are ignored. Order prefixes: `AGENT_MINING_ORDER_PREFIXES` (default `SD`). Frozen cases keep real content (they are the simulation's ground truth and have their own retention); mining's own tables and every proposal field are scrubbed.

## Cost control

- `POST /agent/mining/estimate` / `agent:mine --estimate`: selection, calls, tokens and USD per stage, nothing stored, no model called (assumptions in the response).
- Hard cap: before every call, spent + estimated call cost (prompt / 4 + expected output tokens, or the stage's real average so far if higher) must fit the budget; otherwise the run pauses with `pause_reason: budget` and a message. A call can still cost more than its estimate, so the cap can be overshot by at most one call. Resume with a higher `budget_usd` continues.
- Tokens and cost per stage in `usage`, totals on the run. Prices: `config/agent.php` → `prices`.

## API

Routes in `routes/agent.php` (block "History mining"), `web` + `auth`; every endpoint requires the `manage-settings` gate (403 otherwise, 401 for guests); a run of another workspace is 404. All JSON.

### `GET /agent/mining`

```jsonc
{
  "runs": [RunSummary],              // newest first, up to 20
  "active_run_id": 12 | null,
  "defaults": {"months": 18, "max_conversations": 3000, "budget_usd": 25, "sample_strategy": "stratified"},
  "limits": {"max_conversations": 20000, "budget_usd": 500, "months": 120},
  "strategies": ["stratified", "newest"]
}
```

`RunSummary = {id, status, stage, pause_reason, message, options {since, until, max_conversations, budget_usd, sample_strategy}, selected, cost_usd, budget_usd, created_by, created_at, started_at, finished_at}`.

### `POST /agent/mining/estimate` (throttled 20/min)

Body (all optional): `months` (1-120, default 18) or `since` (date), `until` (date, default now), `max_conversations` (1-20000), `budget_usd` (0.01-500), `sample_strategy` (stratified | newest). 422 with `errors` on bad input.

```jsonc
{"estimate": {
  "options": {...},
  "eligible": 5230, "selected": 3000,
  "selected_by_month": {"2025-06": 98, "...": 0},
  "already_extracted": 0, "to_extract": 3000,
  "situations_estimated": 30, "embeddings": false,
  "stages": {
    "extract":    {"calls": 3000, "tokens_in": 5700000, "tokens_out": 3600000, "model": "claude-haiku-5-5", "cost_micro_usd": 2370000, "cost_usd": 2.37},
    "cluster":    {...}, "synthesise": {...}, "voice": {...}
  },
  "total": {"calls": 3032, "tokens_in": 0, "tokens_out": 0, "cost_micro_usd": 0, "cost_usd": 0.0},
  "budget_usd": 25.0, "within_budget": true,
  "assumptions": {"chars_per_token": 4, "extract_system_tokens": 1100, "avg_transcript_tokens": 620, "extract_output_tokens": 1200,
                  "synthesis_sample": 40, "synthesis_output_tokens": 6000, "voice_replies": 150, "avg_reply_tokens": 140, "macro_copies_predicted": 0}
}}
```

### `POST /agent/mining`

Same body. `201 {"run": RunDetail}` (queued; the estimate is stored in `run.estimate`). `409 {"message"}` when the workspace already has a queued / running / paused run.

### `GET /agent/mining/{run}` → `{"run": RunDetail}` (poll every few seconds while `status` is queued or running)

```jsonc
RunDetail = RunSummary + {
  "stages": ["select", "extract", "cluster", "synthesise", "voice", "emit", "done"], "stage_index": 1,
  "last_error": "[extract] RuntimeException: ..." | null, "last_error_at": "...", "error_count": 0, "heartbeat_at": "...",
  "cost_micro_usd": 1234, "remaining_budget_usd": 23.1,
  "tokens": {"in": 0, "out": 0},
  "usage": {"extract": {"calls": 0, "cache_hits": 0, "tokens_in": 0, "tokens_out": 0, "cost_usd": 0.0}, "cluster": {...}, "synthesise": {...}, "voice": {...}},
  "progress": {"eligible": 0, "selected": 0, "pending": 0, "done": 0, "failed": 0, "extracted_new": 0, "reused": 0, "macro_copies": 0,
               "situations": 0, "situations_pending": 0, "situations_synthesised": 0, "situations_failed": 0, "proposals_emitted": 0},
  "selected_by_month": {"2026-10": 120},
  "dropped": {"not_support_vendor": 12, "not_support_spam": 40, "no_human_reply": 1, "pii_customer_name": 3, "pii_email": 1,
              "rule_no_evidence": 2, "knowledge_unscoped": 0, "inconsistency_unsupported": 0, "test_case_unfreezable": 0, "invalid_op": 0},
  "counters": {...},                 // raw counters (extract_new, extract_reused, extract_macro_copies, extract_failed, situations...)
  "clustering": {"method": "model", "labels": 41, "new_labels": 17} | [],
  "coverage": [{
    "key": "refund_request", "name": "Refund request", "description": "...", "playbook_id": 7, "playbook_slug": "refund_request",
    "is_new": false, "conversation_count": 312, "share": 0.104, "status": "synthesised",   // pending | synthesised | failed | too_small | long_tail
    "labels": {"refund_request": 300, "refund_change_of_mind": 12}, "error": null,
    "synthesis": {"rules": 4, "steps": 2, "knowledge": 3, "inconsistencies": 1, "test_cases": 7, "suggested_actions": ["refund"]} | null,
    "proposal": {"action": "created", "proposal_id": 55, "status": "draft", "reason": null, "errors": []} | null
  }],
  "inconsistencies": [{"topic": "refund_window", "summary": "...", "most_recent": "...", "recommendation": "...", "situation": "refund_request",
                       "situation_name": "Refund request", "versions": [{"statement": "...", "evidence": [101, 230], "last_seen": "2026-10-02"}]}],
  "voice": {"sample_size": 150, "staff": [{"label": "Craig", "replies": 96}], "staff_differences": [{"topic": "length", "note": "..."}],
            "example_phrases": [{"text": "No worries at all", "staff": "Craig"}], "proposal": {...} | null} | null,
  "proposals": {"situation:refund_request": {...}, "voice": {...}, "summary": {...}},   // action: created | updated | unchanged | unchanged_reviewed | skipped_in_review | skipped | failed
  "estimate": {...}                  // the estimate taken at start
}
```

### `POST /agent/mining/{run}/{action}`, action = `pause` | `resume` | `cancel`

`resume` accepts `budget_usd` (new cap; needed after a budget pause). `200 {"run": RunDetail}`; `409 {"message", "run": RunSummary}` when the transition does not apply (e.g. pausing a completed run). Paused (`pause_reason` user or budget) and failed runs can be resumed; queued / running / paused / failed can be cancelled.

## Command

```
php artisan agent:mine 1 --estimate --months=2 --max=150 --budget=2   # estimate only
php artisan agent:mine 1 --months=2 --max=150 --budget=2              # queue on ai-bulk
php artisan agent:mine 1 --sync ...                                   # run in this process
php artisan agent:mine --status=5 | --pause=5 | --resume=5 [--budget=10] | --cancel=5
php artisan agent:mine --recover                                      # watchdog (scheduled)
```

The workspace argument may be left out when there is only one workspace.
