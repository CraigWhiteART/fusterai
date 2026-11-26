# Agent proposals, frozen cases, test cases and simulation

Phase 5 contract. Producers of instruction changes (feedback loop, manager chat, QA, history mining) all emit the same **proposal** objects; nothing in a proposal is live until a human approves it. Simulations replay **frozen cases** (past conversations cut at a customer message) against a candidate configuration.

Code: `app/Domains/Agent/Proposals`, `app/Domains/Agent/Simulation`, `app/Domains/Agent/Feedback`. Migration: `database/migrations/2026_11_28_100000_create_agent_feedback_loop_tables.php`. HTTP API: see "HTTP API" below (also linked from `docs/agent-api.md`).

The PHP signatures in "Service API" are stable: other domains (e.g. `app/Domains/Agent/Mining`) call them.

---

## Tables

### `agent_proposals` (`App\Domains\Agent\Models\Proposal`)

| column | notes |
|---|---|
| `workspace_id` | |
| `origin` | `feedback` \| `manager` \| `qa` \| `mining` |
| `status` | `draft` \| `testing` \| `ready` \| `approved` \| `rejected` \| `applied` \| `stale` \| `reverted` (see "Lifecycle") |
| `title`, `rationale` | human-readable |
| `evidence` | jsonb, free-form provenance. Conventional keys: `conversation_ids[]`, `thread_ids[]`, `run_id`, `quotes[]`, `comment`, `selected_text`, `test_expectation` (expectation for the auto-created feedback test case) |
| `base_config_hash` | `ConfigFingerprint::forWorkspace()` when written; mismatch at approval = stale |
| `gate_simulation_id` | latest regression gate (`agent_simulations`) |
| `scope` | feedback routing scope that produced it: `voice` \| `playbook` \| `knowledge` \| `example` \| null |
| `conversation_id`, `run_id`, `feedback_event_id` | links (feedback origin) |
| `suggestions` | jsonb list of ops the code refused, shown as plain advice: `[{target_type, target_id, op, reason}]` |
| `conflicts` | ConflictChecker report (see "Conflicts"); `conflicts_checked_at` |
| `preview` | latest preview `{status, run_id, baseline_run_id, error, ...}` |
| `applied` | what approval wrote (used by revert), see "Approve / revert" |
| `excluded` | jsonb list of what a reviewer left out before approving (partial approval, see "Excluding changes"); copied into `applied.excluded` |
| `created_by`, `reviewed_by`, `reviewed_at`, `review_note`, `applied_at`, `reverted_by`, `reverted_at` | |

### `agent_proposal_changes` (`ProposalChange`)

One row per target: `position`, `target_type` (`voice` \| `playbook` \| `knowledge` \| `example` \| `test_case`), `target_id` (playbook / knowledge item / example id; **null** for voice and for creates), `base_version_id` (live voice / playbook version the ops were validated against), `ops` (normalised ops, new item ids filled in), `before` (target content before), `after` (content after the ops), `diff` (human-readable entries, see below).

### `agent_frozen_cases` (`FrozenCase`)

`workspace_id`, `conversation_id`, `cut_thread_id` (customer message the transcript ends at, inclusive), `human_thread_id` (first staff reply after the cut, not AI), `subject`, `customer_name`, `customer_email`, `reply_to[]`, `transcript` (list of `TranscriptTurn::toArray()`: `{role: customer|staff, text, at, author, origin, thread_id, has_images, auto_submitted}`), `cut_at` (= "now" for the simulated run), `evidence` (see below), `evidence_quality` (`recorded` \| `reconstructed` \| `none`), `evidence_hash`, `evidence_prepared_at`, `evidence_error`, `human_reply` (text), `human_reply_by` (name), `human_reply_user_id`, `human_reply_at`, `human_reply_origin` (`fusterai` \| `gmail` \| `import`), `source` (`feedback` \| `mining` \| `manual` \| `qa`), `playbook_slug` (playbook a live run chose for the cut message, if any), `meta`, `content_hash` (**unique per workspace**: same conversation + cut + transcript + human reply = same row).

`evidence` json: `{source: recorded|reconstructed, shopify: <ShopifySnapshot payload>|null, tracking: <TrackingSnapshot payload>|null, shopify_snapshot_id, tracking_snapshot_id, as_of, notes: []}`. Null when quality is `none`.

### `agent_test_cases` (`AgentTestCase`)

`workspace_id`, `playbook_id` (nullable: null = core / cross-playbook), `frozen_case_id`, `name`, `expectation`, `pinned` (core set run for voice changes), `source` (`feedback` \| `mining` \| `manual` \| `qa`), `status` (`draft` \| `active` \| `archived`; only `active` cases are run), `proposal_id`, `feedback_event_id`, `created_by`.

`expectation` (always normalised to this shape):

```json
{
  "must_include": ["Australia Post"],
  "must_not_include": ["guaranteed delivery"],
  "expected_outcome": "draft",            // draft | handover | null (don't care)
  "expected_handover_reason": null,       // e.g. "missing_evidence:order"; prefix match
  "expected_actions": ["refund"],         // action keys the run must propose
  "notes": "Never promise delivery dates."
}
```

### `agent_simulations` / `agent_simulation_results`

See "Simulation".

---

## Ops format

A proposal is a list of **changes**; each change is `{target_type, target_id, ops: [...]}`. Ops are field-level operations against **stable item ids** (voice phrases, playbook rules and steps keep their id across versions). Never send a whole document.

New items get an id (`r-xxxxxxxx`, `do-…`, `dont-…`, `greet-…`, `sign-…`, `step-…`) assigned during validation and written back into the stored op, so re-validating the same ops (rebase) keeps the same ids. You may pass your own `id` (`[A-Za-z0-9._-]{1,64}`, not already used).

### voice (`target_id` null; applies to the live voice version)

| op | fields |
|---|---|
| `add_do` / `add_dont` / `add_greeting` / `add_sign_off` | `text` (≤ 500), `id?` |
| `replace_item` | `field` (`do_phrases` \| `dont_phrases` \| `greetings` \| `sign_offs`), `id`, `text` |
| `remove_item` | `field`, `id` |
| `set_field` | `field` (`personality` \| `max_words` \| `length_guidance`), `value` (`max_words`: 20-2000 or null) |

### playbook (`target_id` = playbook id; applies to its live version)

| op | fields |
|---|---|
| `add_rule` | `then` (≤ 1000), `if?` (default `always`), `id?` |
| `replace_rule` | `id`, `then`, `if?` (kept when omitted) |
| `remove_rule` | `id` |
| `add_step` | `text`, `id?` |
| `replace_item` / `remove_item` | `field: "steps"`, `id`, `text` (replace) |
| `set_field` | `field` (`when_text` \| `escalation` \| `required_evidence`), `value` (`required_evidence`: subset of `order`, `tracking`, `photos`) |
| `create_playbook` | **`target_id` null, first op of its change**: `slug`, `name`, `when_text`, `rules?[] {if?, then, id?}`, `steps?[] (string or {text, id?})`, `required_evidence?[]`, `escalation?`. Later ops in the same change (e.g. `add_rule`) apply to the new playbook. On approval it is created in `draft` mode, rollout 0, no allowed actions. |

### knowledge (`target_id` = knowledge item id, or null for create)

| op | fields |
|---|---|
| `create_knowledge` | `target_id` null: `kind` (`fact` \| `policy` \| `snippet`), `title?`, `body`, `scope {global: bool, playbooks[], products[], countries[]}` (required: global or naming something; playbook slugs must exist or be created by the same proposal), `valid_from?`, `valid_until?` (ISO 8601) |
| `set_field` | `field` (`kind` \| `title` \| `body` \| `scope` \| `valid_from` \| `valid_until`), `value`. On approval an edit creates a **new** item that supersedes the old one (old is retired), so revert can restore it. |
| `retire_knowledge` | — |

### example (`target_id` = agent_examples id)

| op | fields |
|---|---|
| `retire_example` | `reason?` |
| `replace_example` | `reply`, `customer_message?` (approval creates a new example and retires the old one) |

### test_case (`target_id` null)

| op | fields |
|---|---|
| `create_test_case` | `frozen_case_id` (same workspace), `name`, `expectation` (shape above), `playbook_id?` or `playbook_slug?` (may name a playbook created by the same proposal), `pinned?` |

### Forbidden ops (never AI-proposed)

Permissions, limits, modes and rollout are changed by a person in settings. Any op named `set_mode`, `set_rollout`, `set_rollout_percent`, `allow_action`, `disallow_action`, `add_allowed_action`, `remove_allowed_action`, `set_allowed_actions`, `set_action_policy`, `set_policy`, `set_caps`, `set_cap`, `set_limit`, `set_kill_switch`, `set_always_human`, `set_priority`, `set_auto_send`, or a `set_field` on `allowed_actions`, `mode`, `rollout_percent`, `always_human`, `priority`, `caps`, `kill_switch`, `auto_send_delay_seconds`, `link_allowlist`, `action_policies`, `action_policy`, `policy`, `limits`, `status`, is **removed** and stored in `proposal.suggestions` as `{target_type, target_id, op, reason}`. `allowed_actions` / `mode` / `rollout_percent` / `always_human` / `priority` keys on `create_playbook` are stripped the same way. Approval also re-reads the live `allowed_actions` instead of the proposal's.

### Example

```json
[
  {"target_type": "voice", "ops": [
    {"op": "add_dont", "text": "guaranteed delivery"},
    {"op": "replace_item", "field": "sign_offs", "id": "sign-ab12cd34", "text": "Warm regards, the Soul Dial team"}
  ]},
  {"target_type": "playbook", "target_id": 3, "ops": [
    {"op": "add_rule", "if": "the customer asks when it will arrive", "then": "Give the carrier's estimate only; never promise a date."},
    {"op": "remove_rule", "id": "r-9f8e7d6c"}
  ]},
  {"target_type": "playbook", "ops": [
    {"op": "create_playbook", "slug": "warranty_claim", "name": "Warranty claim", "when_text": "The customer reports a fault and asks for a repair or replacement.",
     "rules": [{"then": "Ask for a photo of the fault."}], "required_evidence": ["order"]}
  ]},
  {"target_type": "knowledge", "ops": [
    {"op": "create_knowledge", "kind": "policy", "title": "Warranty", "body": "Warranty is 12 months from delivery.", "scope": {"global": false, "playbooks": ["warranty_claim"]}}
  ]},
  {"target_type": "knowledge", "target_id": 41, "ops": [{"op": "retire_knowledge"}]},
  {"target_type": "example", "target_id": 17, "ops": [{"op": "retire_example", "reason": "Promises a delivery date"}]},
  {"target_type": "test_case", "ops": [
    {"op": "create_test_case", "frozen_case_id": 88, "name": "Faulty dial, asks for replacement", "playbook_slug": "warranty_claim",
     "expectation": {"must_include": ["photo"], "expected_outcome": "draft"}}
  ]}
]
```

### Diff entries (`change.diff`, also in API payloads)

```json
{"op": "replace_rule", "field": "rules", "item_id": "r-9f8e7d6c", "label": "Change rule",
 "before": "When the customer asks for a date: Say 3-5 days.", "after": "When the customer asks for a date: Give the carrier's estimate only."}
```

`before` is null for additions, `after` null for removals. Rules render as `then` (when `if` is `always`) or `When {if}: {then}`; lists / scopes render as JSON.

---

## Service API (stable)

### `App\Domains\Agent\Proposals\ProposalService` (resolve from the container)

```php
/**
 * @param  list<array{target_type: string, target_id?: int|null, ops: list<array<string, mixed>>}>  $changes
 * @param  array<string, mixed>  $evidence    provenance (see table)
 * @param  array<string, mixed>  $attributes  optional: scope, conversation_id, run_id, feedback_event_id, suggestions
 * @throws InvalidProposalException  ops do not apply, or nothing applicable left (->errors, ->suggestions)
 * @throws InvalidArgumentException  unknown origin
 */
public function createProposal(int $workspaceId, string $origin, string $title, ?string $rationale,
    array $changes, array $evidence = [], ?int $createdBy = null, array $attributes = []): Proposal;

/** Dry run, nothing stored. @return array{changes: list<array>, suggestions: list<array>} @throws InvalidProposalException */
public function validateChanges(int $workspaceId, array $changes): array;

/** Re-validate and replace a proposal's changes (resets status to draft, clears conflicts + gate). */
public function replaceChanges(Proposal $proposal, array $changes, bool $keepConflicts = false): Proposal;

public function isStale(Proposal $proposal): bool;
public function rebase(Proposal $proposal): Proposal;                 // @throws InvalidProposalException (stays stale)
public function overlay(Proposal $proposal): array;                    // InstructionSet overlay for preview / simulation
public static function overlayFor(array $changes): array;              // same, from change arrays
public function approve(Proposal $proposal, int $userId, ?string $note = null): Proposal; // @throws ProposalStateException
public function reject(Proposal $proposal, int $userId, ?string $note = null): Proposal;
public function revert(Proposal $proposal, int $userId): Proposal;     // @throws ProposalStateException

/** Partial approval: leave changes / single ops out (re-validates what is left, like a rebase). */
public function exclude(Proposal $proposal, array $changeIds, array $ops, int $userId, ?string $note = null): Proposal; // $ops: [{change_id, op_index}]
public function restoreExclusions(Proposal $proposal, array $ids = []): Proposal; // [] = restore all
```

Behaviour:

- Each change is validated against the **current live** content: voice = `agent_settings.live_voice_version_id`; playbook = its `live_version_id` (must be in the workspace, not archived); knowledge / example = the row in this workspace. Two changes to the same existing target are merged into one.
- `InvalidProposalException::$errors` are strings like `changes.1.ops.0: no rule with id "r-x" in the live version.`; nothing is stored when it is thrown.
- `ProposalStateException::$reason`: `not_open`, `stale`, `gate_running`, `unchecked` (conflict check never ran), `blocking_conflicts`, `not_applied`, `moved` (revert: the live pointer changed after this proposal).
- Mining-style producers typically call `createProposal($ws, 'mining', $title, $why, $changes, ['conversation_ids' => [...], 'thread_ids' => [...]], null)`. The proposal starts as `draft`; a human runs the conflict check, optional preview / gate, then approves.

### `App\Domains\Agent\Simulation\FrozenCaseFactory`

```php
/**
 * Freeze a conversation at a customer message (the last message the agent sees).
 * @param  string  $source  feedback | mining | manual | qa
 * @throws InvalidArgumentException  not a customer message of this conversation
 */
public function fromConversation(Conversation $conversation, Thread $cutThread, string $source = 'manual', array $meta = []): FrozenCase;

/** One case per customer message answered by a human (the message right before a staff reply), newest first. */
public function allFromConversation(Conversation $conversation, string $source = 'mining', int $limit = 10): array; // list<FrozenCase>
```

- Idempotent on `content_hash`: calling again returns the same row (and upgrades its evidence to `recorded` if a snapshot has since appeared).
- Never calls Shopify or a carrier. Evidence is `recorded` when the live run that answered the cut message stored snapshot ids, or a snapshot of this conversation was fetched between 7 days before the cut and the human reply (or 6 hours after the cut if nobody replied): that is the state the human saw when answering. Otherwise `none` until the explicit "prepare" step (`EvidenceReconstructor`) reconstructs it.
- Works on imported conversations (`conversations.imported = true`, `threads.origin = import`, real `sent_at`).

### `App\Domains\Agent\Simulation\TestCaseService`

```php
/**
 * @param  array<string, mixed>  $expectation  normalised to the shape above
 * @param  string  $source  feedback | mining | manual | qa
 * @param  string  $status  draft | active | archived  (only active cases run)
 * @throws InvalidArgumentException  playbook from another workspace, unknown source / status
 */
public function create(FrozenCase $case, string $name, array $expectation = [], ?int $playbookId = null,
    bool $pinned = false, string $source = 'manual', string $status = 'active', ?int $createdBy = null,
    ?int $proposalId = null, ?int $feedbackEventId = null): AgentTestCase;

public function update(AgentTestCase $testCase, array $data): AgentTestCase;   // name?, expectation?, pinned?, status?, playbook_id?
public function archive(AgentTestCase $testCase): AgentTestCase;
```

Mining should create its test cases as `create_test_case` ops inside the proposal (they become `active` on approval), or directly with `status: 'draft'`.

---

## Lifecycle

```
draft ──check conflicts──▶ draft (conflicts recorded)
  │ start gate               ▲ resolve-conflict / rebase (ops re-validated)
  ▼                          │
testing ──gate finished──▶ ready ──approve──▶ applied ──revert──▶ reverted
  │                                   │
  └──────── reject (any open) ──▶ rejected
any open status ──approve while live config moved──▶ stale ──rebase──▶ draft
```

- Open statuses (`draft`, `testing`, `ready`, `stale`) can be rejected; `draft` / `ready` can be approved (also `testing` once its gate has finished or is paused).
- Approve requires: conflict check run (`conflicts_checked_at`), no unresolved **blocking** conflict, gate not running, and `base_config_hash` = current fingerprint (else the proposal becomes `stale`, HTTP 409 `reason: stale`).
- `approved` is reserved (approval applies immediately, so a stored proposal goes straight to `applied`).
- `reverted` is an addition to the planned statuses: an applied proposal whose changes were undone (`POST .../revert`).

### Excluding changes (partial approval)

A reviewer can leave out whole changes, or single ops inside a change, before approving (large mining proposals are no longer all-or-nothing):

- `POST /agent/proposals/{id}/exclude` removes them. What is left is re-validated against the **current** live versions (exactly like a rebase, so a stale proposal is rebased at the same time); the proposal goes back to `draft`, the gate is detached, the preview is cleared and `conflicts_checked_at` is reset (earlier conflict resolutions are kept and carried over when the same conflict is found again). Unless `recheck: false`, the endpoint then re-runs the conflict check, re-queues the preview when there was one, and restarts the gate when there was one, all for what remains. A preview still running for the old changes is discarded when it finishes (`status: superseded`, not stored).
- Every excluded item is recorded in `proposal.excluded` and, on approval, copied to `applied.excluded`, so the applied summary shows what was left out (nothing of it is written).
- `POST /agent/proposals/{id}/exclusions/restore` puts items back (some or all). Restored ops are appended to their target's change (ops of a new playbook go back into its `create_playbook` change).
- Refused: leaving nothing (`422 reason: invalid_remainder`: reject the proposal instead), ids that are not part of the proposal (422), a remainder that no longer validates, e.g. excluding a `create_playbook` that knowledge in the same proposal is scoped to (`422 reason: invalid_remainder, errors.ops`), a running gate (`409 gate_running`: wait or pause it), a closed proposal (`409 not_open`).

---

## Feedback loop (how it works)

1. A human comments on a draft (optionally selecting text): `POST /agent/conversations/{id}/feedback`. A `comment` row is written to `agent_feedback_events` and `RouteFeedbackJob` runs on the `ai` queue.
2. The **manager** (`manager` role, `FeedbackManagerAgent`, native structured output) sees exactly what the draft was built from: the voice version it used (items with ids, and whether still live), the playbook version (rules with ids, marked when the draft cited them), the other playbooks' `when` texts, the knowledge the draft saw (`K<id>`), the example replies it was shown (`X<id>`), the customer message, the draft, the selected text and the comment. Customer mail, draft and comment are delimited untrusted data. It returns a scope and minimal ops.
   - `unclear` → ONE clarifying question (`status: question`); `POST /feedback/{id}/answer` re-routes with the answer (`unclear` is not offered the second time).
   - `one_off` → nothing is saved: the instruction is overlaid as an extra rule of the draft's playbook ("For this reply only: …"; a voice "do" when the draft had no playbook) and this conversation is re-drafted with purpose `preview`. If the human then sends it, the normal sent-reply capture records the usual feedback event.
   - `voice` / `playbook` / `knowledge` / `example` → ops are mapped (by op / field) to `ProposalService::createProposal(origin: feedback)`. Invalid ops go back to the manager once with the validation errors. Ops touching permissions / limits / modes / rollout are refused in code and kept as `suggestions`; when nothing else is left the feedback ends as `suggestions_only` (no proposal).
3. The proposal gets the conflict check (below), then a **preview**: this conversation re-run with purpose `preview` against `InstructionSet::live($ws)->withOverlay(ProposalService::overlay($proposal))`, cut at the customer message the original draft answered, with the evidence that run recorded. No Shopify / carrier / mail call, no `AiSuggestion`, no broadcast on the conversation channel, no conversation change; only the preview `agent_runs` row is written.
4. When the proposal has affected test cases (and `agent.feedback.auto_gate` is on), the **regression gate** starts (below).
5. The human can switch the scope chip at any time: `POST /feedback/{id}/scope` re-routes without re-asking (the earlier open proposal is rejected as superseded). Choosing `one_off` makes no manager call.
6. Approve (manage-settings) writes everything in one transaction and saves this conversation as a test case (frozen at the message the draft answered; `must_not_include` = the manager's phrases, else the selected text (≤ 120 chars), plus every new don't phrase; `expected_outcome: draft`; `notes` = the comment).

### Conflicts (`proposal.conflicts`)

```jsonc
{
  "checked_at": "2026-11-28T10:00:00+00:00",
  "llm": {"status": "ok", "error": null, "pairs": 6, "cost_micro_usd": 4200},   // ok | skipped (no pairs) | failed
  "items": [{
    "id": "c-3f2a9b1c0d4e",
    "type": "contradiction",        // contradiction | duplicate | subsumes | do_dont | action_never | action_not_allowed | check_failed
    "severity": "blocking",         // blocking: contradiction, do_dont, action_never, check_failed; warning: the rest
    "change_index": 0,              // index into proposal.changes
    "new": {"target_type": "playbook", "target_id": 3, "playbook_slug": "order_status", "field": "rules", "item_id": "r-1a2b3c4d", "op": "add_rule", "text": "...", "label": "Playbook order_status (rules)"},
    "existing": {"target_type": "knowledge", "target_id": 12, "field": "body", "item_id": null, "text": "...", "label": "Knowledge #12"},
                                    // existing.target_type: voice | playbook | knowledge | example | action_policy
    "existing_precedence": 2, "new_precedence": 3,   // index in `precedence` (lower wins)
    "explanation": "One sentence.",
    "options": ["keep_old", "replace_old", "scope_new"],
    "resolution": null, "resolved_by": null, "resolved_at": null, "note": null
  }],
  "example_flags": [{
    "id": "p-17-1a2b3c4d", "example_id": 17, "reply_excerpt": "...", "reason": "...", "phrase": "guaranteed",
    "options": ["retire", "keep"], "resolution": null, "resolved_by": null, "resolved_at": null
  }],
  "precedence": ["code_limit", "action_policy", "knowledge", "playbook", "voice", "example"],
  "summary": {"blocking": 1, "warnings": 0, "examples_flagged": 1}
}
```

- Deterministic: the same phrase in do and don't (`do_dont`); exact duplicate text anywhere (`duplicate`); a new rule / step that offers an action (refund, cancel, discount code, replacement, address change, fulfilment hold; negated wording such as "never refund" is not an offer) whose effective policy for that playbook (`agent_action_policies` through `Commerce\Actions\Policy\PolicyResolver`; a missing row means `never`) is `never` (`action_never`: **warning**, "this action is currently switched off, so the agent will hand over"; options `keep_old` / `acknowledge`. Every action defaults to `never` on install and the agent hands over whenever it would need an action it may not take, so the rule is harmless until a person turns the action on) or that the playbook's `allowed_actions` does not include (`action_not_allowed`: warning).
- Retrieval shortlist per new text: up to 5 voice items / playbook rules (this and other playbooks) by term overlap, up to 4 knowledge items and 3 example replies via `HybridRetriever` (Postgres full-text; pgvector only when embeddings exist, so it works with embeddings off), plus the examples the draft was shown. At most 24 pairs go to one `ConflictJudgeAgent` call (`manager` role) → `contradiction | duplicate | subsumes | violates | none`.
- Examples are flagged when their reply contains a new don't phrase / the objected phrase, or the judge says the reply `violates` the new instruction (few-shot examples outvote instructions, so retire them).
- Resolutions (`POST /proposals/{id}/resolve-conflict`): `keep_old` (drop the proposed item), `replace_old` (also remove / retire the existing voice item, playbook rule or knowledge item), `scope_new` (`condition` = the new rule's `if`, or comma-separated playbook slugs for knowledge), `keep_both` / `acknowledge` (accept a warning or a failed AI check), `retire` / `keep` for example flags (`retire` adds a `retire_example` change). Ops-changing resolutions re-validate the proposal (status back to `draft`, gate cleared). Re-running the check keeps the resolution of the same pair.
- Precedence is stated to the manager and the conflict judge and enforced in code: a proposal can never change an action policy (such ops are set aside as suggestions, and the live policy decides at run time), and every contradiction blocks approval until resolved.

### Regression gate and simulations

- `agent_simulations.kind`: `batch` (human-selected past cases; `redis-long` / `ai-bulk`), `gate` (a proposal: live vs live + overlay; `ai` queue), `playbook_tests` (a playbook's test cases against live; `ai`).
- `status`: `pending` → `running` → `completed`; or `paused` (resumable), `cancelled`, `budget_exhausted`, `failed`.
- Gate case selection (`CaseSelector::gate`; cap `agent.simulation.gate_cap` = 40; pinned first; active test cases only): playbook change → that playbook's test cases; voice change → pinned core set + a sample stratified by playbook; knowledge change → frozen cases whose runs cited the item (simulated runs, or the live run for the cut message) + test cases of the playbooks in its scope; example change → test cases of the example's playbook. New playbooks have no cases yet.
- Per case the candidate run (purpose `simulation`, frozen transcript, "now" = cut, stored evidence only, no conversation) is scored: deterministic expectations first (`must_include`, `must_not_include`, `expected_outcome`, `expected_handover_reason` prefix, `expected_actions`), then `SimulationJudgeAgent` (`simulation_judge` role) against the human's actual reply: `outcome_match`, `policy_consistency`, `factual_grounding`, `tone_match` (1-5), `would_send`, `reason`. With evidence below `recorded` the judge is told not to penalise factual differences and `factual_grounding` is stored as null. `passed` = expectations met and `would_send` and outcome ≥ 3 and policy ≥ 3 (and facts ≥ 3 when recorded); a failed judge call = `error` (unless an expectation already failed).
- Gates also score the baseline (live). Results are cached by (`SimulationConfig::hash` = pipeline config hash + knowledge/example fingerprint + judge model + scorer version, `FrozenCase::caseKey()` = case content + evidence), so only changed configs re-run (`result.cached`). When candidate and baseline disagree the candidate is re-run once: if the re-run agrees with the baseline the verdict is `changed` (sampling noise: temperature cannot be pinned), otherwise `regressed` / `improved`.
- Verdicts: `pass`, `fail`, `regressed`, `improved`, `changed`, `error`; a human can override to `pass` / `fail` (`effective_verdict`).
- Budget: before each case, if `cost + estimated per-case cost > budget` the simulation stops as `budget_exhausted`. Estimate = average cost of this workspace's live / shadow / simulation runs in the last 30 days (fallback `agent.simulation.run_cost_micro_usd` = 60,000) + `judge_cost_micro_usd` (8,000) per uncached run; gates add the baseline (unless cached) and 15% for re-runs.
- Each job runs cases until `step_seconds` (200s, `ai`) / `batch_step_seconds` (600s, `ai-bulk`), then re-dispatches itself; pause / cancel / budget take effect between cases. A step killed mid-case leaves that case without a result.

### Frozen-case evidence

- `recorded`: see FrozenCaseFactory above.
- `reconstructed`: the explicit, rate-limited **prepare** step (`PrepareFrozenCasesJob` on `ai-bulk`, `agent.simulation.prepare_per_minute` = 30 per workspace). The order is fetched from Shopify now (by order number from earlier snapshots or the transcript, else by customer email with `created_at <= cut`) and rebuilt as of the cut: orders created after the cut are ignored; fulfilment status from fulfilments with `createdAt <= cut` (none → `UNFULFILLED`, some → `PARTIALLY_FULFILLED`, all → Shopify's status); refunds / cancellation only when dated `<= cut` (a later refund reads as `PAID`); tracking only from fulfilments before the cut; order notes are dropped; carrier events are filtered to `<= cut` with status, last event and `days_since_last_scan` recomputed.
- `none`: neither; `evidence_error` says why. Re-prepare specific cases with `POST /agent/frozen-cases/prepare {frozen_case_ids}`.
- A simulation never calls Shopify, a carrier or mail: the run has no conversation and its evidence is supplied from the case.

---

## HTTP API

All routes are in `routes/agent.php` (web middleware: session auth + CSRF), JSON only. Unauthenticated → 401. Rows of another workspace → 404. Validation → 422 `{message, errors}`. State conflicts → 409 `{message, reason, context, proposal?}` with `reason` from `ProposalStateException` (`not_open`, `stale`, `gate_running`, `unchecked`, `blocking_conflicts`, `not_applied`, `moved`).

| Route | Who |
|---|---|
| `POST /agent/conversations/{conversation}/feedback`, `GET /agent/conversations/{conversation}/feedback`, `GET /agent/feedback/{id}`, `POST /agent/feedback/{id}/scope`, `POST /agent/feedback/{id}/answer`, `POST /agent/suggestions/{suggestion}/dismiss` | `ConversationPolicy::view` of the conversation |
| `GET /agent/proposals/{id}`, `POST /agent/proposals/{id}/preview` | `manage-settings`, or `view` of the proposal's conversation |
| every other route below | `manage-settings` (403 otherwise) |

### Feedback

- **`POST /agent/conversations/{conversation}/feedback`** (throttle 20/min) body `{run_id, text, selected_text?, scope_override?: one_off|voice|playbook|knowledge|example, playbook_id?, knowledge_id?, example_id?}` → `202 {feedback: Feedback}`. `run_id` must be a run of this conversation (404 otherwise).
- **`GET /agent/conversations/{conversation}/feedback`** query `kind?` (`sent_as_is` \| `sent_edited` \| `gmail_reply` \| `rejected` \| `comment` \| `auto_send_wrong`), `run_id?`, `limit?` (1-100, default 50) → `{conversation_id, feedback: Feedback[], proposals: Proposal[]}`, both latest first. `proposals` = every proposal from this conversation (`conversation_id`, any origin) or from one of the listed feedback events. Lets the inbox restore the feedback box after a reload (no sessionStorage needed).
- **`GET /agent/feedback/{id}`** → `{feedback: Feedback}`.
- **`POST /agent/feedback/{id}/scope`** body `{scope: one_off|voice|playbook|knowledge|example, playbook_id?, knowledge_id?, example_id?}` → `202 {feedback}`.
- **`POST /agent/feedback/{id}/answer`** body `{answer}` → `202 {feedback}`; `409` when it is not waiting for an answer.
- **`POST /agent/suggestions/{suggestion}/dismiss`** body `{reason?}` → `{ok: true, feedback_id}`: records a `rejected` feedback event (`meta.reason = "dismissed"`) on the suggestion's run and sets `accepted = false`.

```jsonc
Feedback = {
  "id": 51, "conversation_id": 9, "run_id": 123, "user_id": 4, "kind": "comment",
  "text": "Don't say that, we never promise delivery dates.", "selected_text": "guaranteed to arrive Friday",
  "status": "proposal",          // pending | question | one_off | proposal | suggestions_only | failed
  "scope": "voice",              // one_off | voice | playbook | knowledge | example | unclear | null (pending)
  "scope_overridden": false,
  "reason": "Brand-wide wording.",
  "question": null,              // set when status = question
  "answer": null,
  "one_off_instruction": null,   // set when status = one_off
  "target": {"playbook_id": 3, "knowledge_id": null, "example_id": null} | null,
  "suggestions": [{"target_type": "playbook", "target_id": 3, "op": {}, "reason": "...", "source": "manager"}],   // source only on manager advice
  "error": null,                 // set when status = failed
  "scopes": ["one_off", "voice", "playbook", "knowledge", "example"],
  "proposal": Proposal | null,
  "preview": Preview | null,
  "created_at": "..."
}

Preview = {
  "status": "ready",             // pending | running | ready | failed | skipped
  "error": null,
  "baseline_run_id": 123, "run_id": 130,
  "old_draft": "...", "old_decision": "draft",
  "new_draft": "...", "new_decision": "draft",   // new_draft null when the preview handed over
  "new_handover_reason": null, "new_handover_label": null,
  "instruction_diff": [DiffEntry],                // proposal.diff; empty for one_off
  "new_run": Run,                                 // same shape as GET /agent/conversations/{id}/run (docs/agent-api.md)
  "at": "..."
}
```

### Proposals

- **`GET /agent/proposals`** query `status?`, `origin?`, `open?` (bool: draft/testing/ready/stale), `per_page?` → `{data: Proposal[], meta: {current_page, last_page, per_page, total}, counts: {status: n}}`.
- **`GET /agent/proposals/{id}`** → `{proposal: Proposal}` (`preview` presented as `Preview`).
- **`POST /agent/proposals/{id}/check`** (throttle 20/min) → `{proposal}`; re-runs the conflict check; 409 if not open.
- **`POST /agent/proposals/{id}/resolve-conflict`** body `{conflict_id, resolution: keep_old|replace_old|scope_new|keep_both|acknowledge|retire|keep, condition?, note?}` → `{proposal}`; 422 when the option is not offered for that conflict, `scope_new` lacks `condition`, or keeping the old would leave the proposal empty.
- **`POST /agent/proposals/{id}/preview`** (throttle 10/min) → `202 {proposal}`; runs on `ai`; listen for `agent.preview.ready`. 422 when the proposal has no draft run.
- **`POST /agent/proposals/{id}/gate`** (throttle 10/min) → `202 {simulation: Simulation, proposal}`; proposal → `testing` (straight to `ready` when no case is affected). A still-running earlier gate is cancelled.
- **`POST /agent/proposals/{id}/exclude`** (throttle 30/min) body `{change_ids?: int[], ops?: [{change_id, op_index}], note?, recheck?: bool (default true)}` (at least one item; `op_index` = index into `changes[].ops`) → `{proposal}` (`Preview` on `preview`). See "Excluding changes". To batch several toggles send `recheck: false` and finish with `recheck: true` (or `POST .../check`).
- **`POST /agent/proposals/{id}/exclusions/restore`** (throttle 30/min) body `{ids?: string[] (excluded[].id; omitted = all), recheck?: bool}` → `{proposal}`.
- **`POST /agent/proposals/{id}/approve`** body `{note?}` → `{proposal}` (`status: applied`). Runs the conflict check first if it never ran. 409 reasons: `stale` (proposal is now `stale`: rebase), `blocking_conflicts` (`context.conflicts`), `gate_running`, `not_open`.
- **`POST /agent/proposals/{id}/reject`** body `{note?}` → `{proposal}` (`rejected`; the row is kept).
- **`POST /agent/proposals/{id}/rebase`** → `{proposal}` (`draft`; ops re-validated against the current live versions; conflicts and gate cleared). `422 {reason: "rebase_failed", errors: {ops: [...]}, proposal}` when an op no longer applies (it stays `stale`).
- **`POST /agent/proposals/{id}/revert`** → `{proposal}` (`reverted`: prior voice / playbook versions restored as new versions, created playbooks archived, created knowledge retired, superseded / retired knowledge and retired examples reactivated, created examples retired, created test cases archived). `409 reason: moved` when a target changed again after the apply (restore from the Voice / Playbook page instead).

```jsonc
Proposal = {
  "id": 7, "origin": "feedback", "status": "draft", "title": "...", "rationale": "...", "scope": "voice",
  "evidence": {}, "conversation_id": 9, "run_id": 123, "feedback_id": 51, "base_config_hash": "sha256",
  "changes": [{
    "id": 11, "position": 0, "target_type": "voice", "target_id": null,
    "target_label": "Brand voice",   // "Brand voice" | "Playbook: Order status" | "Playbook: Warranty (new)" | "Knowledge: Warranty" | "New knowledge: Duties" | "Example X17" | "Test case"
    "base_version_id": 4, "ops": [], "diff": [DiffEntry], "before": {} | null, "after": {} | null
  }],
  "diff": [DiffEntry + {"change_id", "target_type", "target_id", "target_label"}],   // flattened for the side-by-side view
  "suggestions": [],
  "conflicts": Conflicts | null,     // null = never checked
  "blocking_conflicts": 0,
  "can_approve": true,               // draft/ready, checked, nothing blocking, gate not running
  "gate": Simulation | null,
  "preview": Preview | {"status", "run_id", "baseline_run_id", "error", "at"} | null,   // Preview on show / preview
  "applied": {} | null,
  "excluded": [Excluded],            // [] when nothing was left out
  "review_note": null, "created_by": 4, "reviewed_by": null, "reviewed_at": null,
  "applied_at": null, "reverted_at": null, "created_at": "...", "updated_at": "..."
}
DiffEntry = {"op": "add_dont", "field": "dont_phrases", "item_id": "dont-1a2b3c4d", "label": "Add don't phrase", "before": null, "after": "guaranteed"}
Excluded = {
  "id": "x-k3j9a0b1c2",              // pass to /exclusions/restore
  "kind": "change",                  // change (a whole change) | op (one op of it)
  "target_type": "playbook", "target_id": 3, "target_label": "Playbook: Order status",
  "anchor": null,                    // slug of a new playbook (target_id null), used to restore its ops
  "op_index": 1,                     // kind op only: index at the time it was excluded
  "ops": [{"op": "add_rule", "then": "..."}],
  "excluded_by": 4, "excluded_at": "...", "note": null
}
```

`applied` after approval: `{voice: {from_version_id, to_version_id} | null, playbooks: [{playbook_id, from_version_id, to_version_id}], playbooks_created: [id], knowledge_created: [id], knowledge_superseded: [{old_id, new_id}], knowledge_retired: [id], examples_retired: [id], examples_created: [id], test_cases_created: [id], excluded: [Excluded]}`, plus `restored: {voice, playbooks}` after a revert.

After an approve or revert, every playbook in `auto` mode that the change can affect is held (`auto_hold = retesting`) and its tests re-run: playbooks with a new version, **all** auto playbooks for a voice change, and auto playbooks in scope of knowledge / examples that were created, superseded or retired (knowledge scoped to playbook slugs = those; global or product / country-only scope = all; an example = the playbook of its `playbook_slug`, or all when it has none). Direct edits on the Voice and Knowledge screens, and KB document re-syncs, do the same. See docs/agent-autosend.md.

### Test cases

- `GET /agent/test-cases` query `playbook_id?`, `status?` (draft|active|archived), `pinned?`, `source?`, `per_page?` → `{data: TestCase[], meta}`.
- `POST /agent/test-cases` body `{frozen_case_id}` **or** `{conversation_id, cut_thread_id}` (frozen now), plus `{name, playbook_id?, pinned?, status?, expectation?}` → `201 {test_case}`.
- `GET /agent/test-cases/{id}` → `{test_case}` (with `frozen_case` incl. `transcript`).
- `PATCH /agent/test-cases/{id}` body any of `{name, playbook_id, pinned, status, expectation}` → `{test_case}`.
- `DELETE /agent/test-cases/{id}` → `{test_case}` (archived, never hard-deleted).
- `POST /agent/playbooks/{playbook}/tests/run` (throttle 10/min) → `202 {simulation}` (kind `playbook_tests`).

`TestCase = {id, name, playbook_id, playbook: {id, slug, name} | null, frozen_case_id, frozen_case: FrozenCase | null, expectation, pinned, source, status, proposal_id, feedback_event_id, last_result, created_at}`.

`last_result` = the most recent simulation result for the test case (batch, gate or playbook tests), or null when it never ran: `{verdict (effective: a human override wins), raw_verdict, human_override, simulation_id, result_id, at}`.

- `GET /agent/playbooks/options` query `include_archived?` → `{data: [{id, slug, name, mode, archived}]}` sorted by name: a small list for filters and pickers. Any signed-in user (names only; own workspace).

### Simulations

- `POST /agent/simulations/estimate` body (filters) `{playbook?, date_from?, date_to?, only_pinned?, min_evidence?: recorded|reconstructed|none, sample?: latest|random|stratified (default), limit? (1-500, default 50)}` → `{estimate: {cases, cached_cases, per_case_micro_usd, estimated_micro_usd, estimated_usd, basis}, frozen_case_ids: []}`. Candidates are frozen cases with a human reply (`only_pinned`: pinned active test cases).
- `POST /agent/simulations` body filters + `{budget_usd?}` → `202 {simulation}`; 422 when nothing matches.
- `GET /agent/simulations` query `kind?`, `proposal_id?`, `playbook_id?` (the simulation's own `playbook_id`, i.e. playbook test runs), `per_page?` → `{data: Simulation[], meta}`.
- `GET /agent/simulations/{id}` → `{simulation}`.
- `GET /agent/simulations/{id}/results` query `verdict?` (matches the effective verdict), `per_page?` → `{data: Result[], meta}`.
- `POST /agent/simulations/{id}/pause` → `{simulation}`; `/resume` → `202 {simulation}` (409 unless paused); `/cancel` → `{simulation}`.
- `PATCH /agent/simulations/{id}/results/{result}` body `{verdict: pass|fail|null, note?}` → `{result}`.

```jsonc
Simulation = {
  "id": 3, "kind": "gate", "status": "running", "proposal_id": 7, "playbook_id": null,
  "candidate_config_hash": "...", "baseline_config_hash": "..." | null, "compares_baseline": true,
  "selection": {},
  "totals": {"cases": 12, "done": 5, "pass": 3, "fail": 0, "regressed": 1, "improved": 0, "changed": 1, "error": 0, "cached": 2},
  "progress": 0.417,
  "cost_micro_usd": 410000, "cost_usd": 0.41, "estimated_cost_micro_usd": 900000, "estimated_cost_usd": 0.9,
  "budget_micro_usd": null, "budget_usd": null, "error": null,
  "started_at": "...", "finished_at": null, "created_at": "..."
}
Result = {
  "id": 40, "simulation_id": 3, "frozen_case_id": 88, "test_case_id": 12, "test_case_name": "...",
  "playbook": {"id": 3, "slug": "order_status", "name": "Order status"},      // or null: the test case's playbook, else the one the agent chose
  "ai_playbook": {"id": 3, "slug": "order_status", "name": "Order status"},   // or null: the playbook the agent's run chose
  "conversation_id": 9, "subject": "...", "evidence_quality": "recorded", "customer_message": "...", "human_reply": "...",
  "agent_run_id": 501, "baseline_run_id": 502, "rerun_run_id": null,
  "ai_reply": "...", "ai_decision": "draft", "ai_handover_reason": null, "baseline_reply": "...", "baseline_decision": "draft",
  "scores": {
    "judge": {"status": "ok", "model": "...", "outcome_match": 5, "policy_consistency": 4, "factual_grounding": 4, "tone_match": 4,
              "would_send": true, "reason": "...", "cost_micro_usd": 300},   // or {"status": "failed", "error": "..."}; factual_grounding null when not recorded
    "deterministic": {"checked": true, "passed": false, "failures": [{"check": "must_not_include", "detail": "..."}]},
    "facts_scored": true,
    "rerun": {"passed": true, "judge": {}, "deterministic": {}} | null
  },
  "baseline_scores": {"judge": {}, "deterministic": {}} | null,
  "passed": false, "baseline_passed": true,
  "verdict": "regressed", "effective_verdict": "regressed",
  "judge_notes": "...", "cached": false,
  "human_override": null, "human_override_note": null, "human_override_by": null,
  "cost_micro_usd": 52000, "error": null, "created_at": "..."
}
```

### Frozen cases

- `GET /agent/frozen-cases` query `evidence_quality?`, `source?`, `playbook?`, `unprepared?`, `per_page?` → `{data: FrozenCase[], meta, counts: {quality: n}, unprepared: n}`.
- `GET /agent/frozen-cases/{id}` → `{frozen_case}` (with `transcript` and `evidence`).
- `POST /agent/frozen-cases/build` (throttle 5/min) body `{date_from?, date_to?, imported_only? (default true), limit? (conversations, default 200), per_conversation? (default 3)}` → `202 {queued: true}`; database only.
- `POST /agent/frozen-cases/prepare` (throttle 5/min) body `{frozen_case_ids?: int[]}` (omit = every unprepared case) → `202 {queued: true, cases, per_minute}`: the rate-limited Shopify / carrier reconstruction.

`FrozenCase = {id, conversation_id, cut_thread_id, human_thread_id, subject, customer_name, cut_at, customer_message, turns, transcript (show only, else null), evidence_quality, evidence_prepared_at, evidence_error, human_reply, human_reply_by, human_reply_at, source, playbook_slug, created_at}`.

### Events (private channel `workspace.{workspace_id}`)

All from `App\Domains\Agent\Events\AgentLoopUpdated` (`ShouldBroadcastNow`). Payloads are small: re-fetch the named resource. With Echo, listen with a leading dot: `.listen('.agent.feedback.routed', ...)`.

| `broadcastAs` | payload | when |
|---|---|---|
| `agent.feedback.routed` | `{feedback_id, conversation_id, run_id, status, scope, proposal_id, question}` | routing reached question / one_off / proposal / suggestions_only / failed |
| `agent.preview.ready` | `{feedback_id, conversation_id, proposal_id, status, run_id}` | a preview finished (`ready` / `failed` / `skipped`) |
| `agent.proposal.updated` | `{proposal_id, status, origin, conversation_id, feedback_id}` | gate started, conflict resolved, approve, reject, rebase, revert, became stale |
| `agent.simulation.progress` | `{simulation_id, kind, status, proposal_id, playbook_id, totals, cost_micro_usd, result_id}` | after every case |
| `agent.simulation.finished` | same, without `result_id` | completed / cancelled / budget_exhausted / failed (a gate's proposal then moves `testing` → `ready`) |
| `agent.cases.prepared` | `{finished, reconstructed?, none?, recorded?, conversations?, cases?}` | a prepare / build step ended |
