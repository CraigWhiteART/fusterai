# QA reviews and the AI performance report

Phase 7. QA reviews **closed** conversations, both AI-drafted and fully human ones, scores the staff reply, classifies what was missing, and feeds recurring gaps back as proposals. The report aggregates drafts, handovers, automation, edits, QA scores and cost.

Code: `app/Domains/Agent/Qa` (`QaService` triggers, `QaCaseBuilder`, `QaReviewer` two-pass review, `GapGrouper`, `QaProposalDrafter`, `QaAggregates`, `QaHooks`, `Console/QaBackfillCommand`), agents `QaTriageAgent` / `QaReviewAgent` / `QaProposalAgent`, jobs `ReviewConversationJob` / `DraftQaProposalJob`, `app/Domains/Agent/Reporting/PerformanceReport.php`, controllers `QaController`, `ReportController`. Config: `config/agent_qa.php`. Migration: `database/migrations/2026_11_29_400001_create_agent_qa_tables.php`. Tests: `tests/Feature/Agent/QaReviewTest.php`, `tests/Feature/Agent/PerformanceReportTest.php`.

---

## Tables

### `agent_qa_reviews` (`Qa\Models\QaReview`)

| column | notes |
|---|---|
| `conversation_id`, `reviewed_thread_id` | the staff reply judged: the newest staff message that answers a customer message |
| `customer_thread_id` | the customer message it answered |
| `author_type` | `ai` (sent by the AI, or the draft sent as-is), `ai_edited` (draft edited by a person), `human` (anything else, incl. Gmail replies and rejected drafts) |
| `author_user_id`, `run_id` | run = the run whose draft was sent (feedback event), else the latest completed live / shadow run for that customer message |
| `playbook_id`, `playbook_slug` | the run's playbook, else the first pass's pick from the catalogue |
| `status` | `pending` → `triaged` (first pass only) \| `reviewed` (full review) \| `skipped` (`skip_reason`) \| `failed` (`error`) |
| `skip_reason` | `auto_closed_without_reply`, `not_support`, `not_sampled`, `daily_cap`, `reopened`, `spam`, `no_staff_reply` (closes with no reply at all store no row) |
| `trigger` | `closed` \| `backfill` \| `unhappy_return` \| `manual` |
| `sampled` | full review because of the random sample, not a low first pass |
| `forced` | full review forced (manual, unhappy return); first pass skipped |
| `triage`, `triage_score` | first pass `{score 1-5, flagged, concerns[], playbook, reason, model, cost_micro_usd, unhappy_return?: {run_id, sentiment, customer_thread_id}}` |
| `scores` | `{resolved, policy_consistency, factual_grounding, tone_match, clarity}` (1-5; `factual_grounding` null when there was no evidence to judge) |
| `overall` | mean of the non-null scores (2 decimals) |
| `failure_class` | `none` \| `missing_instruction` \| `missing_knowledge` \| `missing_tool` \| `needed_human` \| `agent_error` \| `human_error` |
| `summary`, `gap`, `gap_topic`, `suggested_fix` | reviewer text, PII-scrubbed (`PiiScrubber`); `gap_topic` snake_case |
| `gap_id`, `proposal_id` | the gap group, and its proposal once drafted |
| `evidence_source` | `run_ledger` (the run's recorded evidence ledger) \| `recorded_snapshot` (stored Shopify / tracking snapshots of this conversation fetched between 7 days before the customer message and 1 hour after the reply) \| `none` |
| `reviewer_model`, `reviewer_prompt_version` (`agent_qa.prompt_version`) | |
| `tokens_in/out`, `triage_cost_micro_usd`, `review_cost_micro_usd`, `cost_micro_usd` | |
| `human_verdict` (`dismissed` \| `incorrect`), `human_note`, `human_by`, `human_at` | human disagreement: excluded from every aggregate and gap count |
| `replied_at` | when the reviewed reply was sent; reports bucket QA by it |

**Unique** `(conversation_id, reviewed_thread_id, reviewer_prompt_version)`: closing, reopening and closing again without a new reply never reviews the same reply twice; a new prompt version reviews it afresh.

### `agent_qa_gaps` (`Qa\Models\QaGap`)

`failure_class` (`missing_instruction` \| `missing_knowledge` \| `missing_tool`), `playbook_id`, `topic` (first review's gap topic), `label` (first gap text), `terms` (normalised word stems), `review_count` (**distinct conversations**, not dismissed), `conversation_ids`, `status` (`open` → `drafting` → `proposed` \| `failed`; `closed` once its proposal is no longer open), `proposal_id`, `cost_micro_usd`, `error`, `last_seen_at`, `proposed_at`.

---

## Triggers

All need `agent_qa.enabled`, the workspace's agent mode ≠ `off` and an AI key (`QaService::disabledReason` → `disabled` \| `agent_off` \| `ai_not_configured`). QA never calls Shopify, a carrier or the mailbox.

- **`conversation.closed`** (hook registered by `QaHooks` from `AgentServiceProvider`): `QaService::onClosed`. Imported conversations and conversations with no staff reply store nothing. Otherwise one row: `skipped` for spam (status spam / blocked customer: no row), `not_support` (the answered message was bulk / notification / auto-submitted mail), `auto_closed_without_reply` (a customer message after the reply that no one answered and no person closed by hand: an activity row by a user after it), `not_sampled` (random ≥ `sample_rate`), `daily_cap` (non-skipped `closed` reviews today ≥ `daily_cap`); else `pending` and `ReviewConversationJob` on `agent_qa.queue` (`ai`) after `delay_minutes`. When the job runs and the conversation was reopened: `skipped` / `reopened`.
- **Backfill**: `php artisan agent:qa-backfill {workspace?} --days=7 --limit= --sync --dry-run` reviews closed, non-imported conversations updated in the last N days that have no review, queued on `agent_qa.backfill.connection` / `.queue` (`redis-long` / `ai-bulk`); no sampling or daily cap (its own `--limit`, default `backfill.max` = 500).
- **Manual**: `POST /agent/qa/conversations/{id}/review` forces a full review of the latest staff reply.
- **Unhappy return**: when a live / shadow run completes (`AgentRun::saved`) with triage sentiment in `unhappy_return.sentiments` (frustrated, angry) on a customer message sent within `unhappy_return.days` (14) of an `ai` / `ai_edited` reply that already has a QA row (i.e. the conversation had been closed in between), that reply's row is forced to a full review (`trigger: unhappy_return`, `forced: true`, `triage.unhappy_return` filled), even if it was skipped or only triaged before.

## Two passes (cost)

1. **First pass** (`triage` role, Haiku, `QaTriageAgent`): score 1-5 + playbook pick. Below `first_pass.threshold` (4) = **flagged**.
2. **Full review** (`simulation_judge` role, Sonnet, `QaReviewAgent`) for flagged ones, a random `full_review_sample_rate` (0.15) of the rest (`sampled: true`), and forced reviews. Unflagged, unsampled replies end `triaged`.

Prompts: the system prompt holds only fixed instructions, the approved brand voice and the approved playbook that applies (rules, steps, escalation). The transcript before the reply (last `max_turns` = 12, each ≤ `turn_chars` = 3,000), the reviewed reply, the AI draft a person edited (for `ai_edited`), what happened after (≤ 4 messages: thanks, silence, the same question again…) and the evidence are untrusted data blocks in the user message. The same judge prompt is used for AI and human replies, so their scores compare.

## Gaps → proposals

After a full review with `missing_instruction` / `missing_knowledge` / `missing_tool` and a gap text, `GapGrouper::attach` joins the best open group with the same failure class and playbook whose `topic` equals the review's `gap_topic`, or whose term set has Jaccard similarity ≥ `gaps.similarity` (0.34) with the gap's (deterministic stems, stop words removed: **no embeddings needed**); otherwise a new group. A group whose proposal was applied / rejected / reverted is `closed`: new gaps start a new group.

- `missing_instruction` / `missing_knowledge`: when a group reaches `gaps.proposal_threshold` (3) distinct, not-dismissed conversations it is claimed atomically (`open` → `drafting`) and `DraftQaProposalJob` (`ai`) asks the **manager** (`manager` role, `QaProposalAgent`, structured output) for ONE proposal: the playbook's live rules with ids, in-scope knowledge, the voice, and up to `gaps.max_examples` (6) cases (gap, suggested fix, customer message and staff reply as untrusted data). One validation retry. Stored through `ProposalService::createProposal(origin: 'qa')` with evidence `{conversation_ids, qa_review_ids, qa_gap_id, failure_class, gaps[{review_id, conversation_id, gap, suggested_fix}]}`; the group becomes `proposed` and its reviews get `proposal_id`. Later reviews joining the group are counted but never create a second proposal. Forbidden ops become `proposal.suggestions` as everywhere. No change proposed / only forbidden ops / two validation failures → group `failed` with `error` (not retried automatically).
- `missing_tool`: never a proposal; the groups are the **capability requests** list.
- `needed_human`: not grouped; counted per playbook in the aggregates (a playbook with many should be always-human or have clearer escalation).
- A human dismiss / "incorrect" recounts the group (it can drop below the threshold; an already drafted proposal is left as is). Clearing the verdict re-attaches.

---

## HTTP API (JSON, `manage-settings`; another workspace's rows are 404)

### `GET /agent/qa/reviews`

Query: `failure_class?`, `author_type?`, `playbook_id?`, `conversation_id?` (the inbox seeds its QA badges from this), `status?` (default triaged + reviewed), `min_overall?` / `max_overall?` (1-5, on `overall`, or the first-pass score when there was no full review), `from?` / `to?` (dates, on `replied_at`), `trigger?`, `include_dismissed?` (default false), `per_page?` (≤ 100, default 30). → `{data: Review[], meta, counts: {status: n}}` (counts exclude dismissed).

```jsonc
Review = {
  "id": 5, "conversation_id": 120, "subject": "Broken strap", "reviewed_thread_id": 900, "customer_thread_id": 899,
  "author_type": "ai_edited", "author_user_id": 3, "run_id": 77,
  "playbook": {"id": 4, "slug": "damaged_product", "name": "Damaged product"} | null, "playbook_id": 4,
  "status": "reviewed", "skip_reason": null, "trigger": "closed",
  "pass": "full",                       // full | first | null
  "sampled": false, "forced": false,
  "triage_score": 2, "triage": {"score": 2, "flagged": true, "concerns": ["..."], "playbook": "damaged_product", "reason": "...", "model": "claude-haiku-5-5", "cost_micro_usd": 90},
  "scores": {"resolved": 3, "policy_consistency": 2, "factual_grounding": null, "tone_match": 4, "clarity": 4},
  "overall": 3.25, "failure_class": "missing_instruction",
  "summary": "...", "gap": "No rule on whether cosmetic damage is covered.", "gap_topic": "warranty_cosmetic_damage", "suggested_fix": "...",
  "gap_id": 2, "proposal_id": 31, "evidence_source": "run_ledger",
  "reviewer_model": "claude-sonnet-5-5", "reviewer_prompt_version": "qa-v1",
  "cost_micro_usd": 9800, "cost_usd": 0.0098,
  "human_verdict": null, "human_note": null, "human_by": null, "human_at": null,
  "error": null, "replied_at": "...", "reviewed_at": "...", "created_at": "..."
}
```

### `GET /agent/qa/reviews/{id}`

→ `{review: Review + {reviewed_reply, customer_message, gap_group: {id, status, count, label, proposal_id} | null}}` (plain text of the two messages).

### `POST /agent/qa/reviews/{id}/verdict`

Body `{verdict: "dismissed" | "incorrect" | null, note?}` (`verdict` required, null clears) → `{review}` in the full detail shape of `GET /agent/qa/reviews/{id}` (incl. `reviewed_reply`, `customer_message`, `gap_group`).

### `POST /agent/qa/conversations/{conversation}/review` (throttle 20/min)

→ `202 {review}` (forced, `trigger: manual`; runs on `ai`). `422 {reason: "no_staff_reply"}`, `409 {reason: disabled | agent_off | ai_not_configured}`.

### `GET /agent/qa/capability-requests`

→ `{data: [{id, label, topic, playbook: {id, slug, name} | null, count, conversation_ids[], suggested_fixes[] (latest 5), last_seen_at, first_seen_at}]}`, most frequent first.

### `GET /agent/qa/gaps`

→ `{data: [{id, failure_class, status, label, topic, playbook, count, conversation_ids, proposal_id, error, cost_usd, last_seen_at, threshold}], threshold}` (`threshold` = `gaps.proposal_threshold`, also on each row and on a review's `gap_group`).

### `GET /agent/qa/aggregates`

Query `from?`, `to?` (default the last 30 days through the end of today), `playbook_id?`.

```jsonc
{
  "from": "...", "to": "...",
  "by_author_type": [{                 // always ai, human, ai_edited
    "author_type": "ai", "reviews": 40,
    "triaged": 40, "triage_avg": 4.3,  // first pass over every reviewed reply (unbiased, cheap)
    "reviewed": 9, "review_avg": 3.1,  // full reviews (flagged + sampled + forced: biased low)
    "sampled": 4, "sample_avg": 4.1,   // random-sample full reviews only (unbiased)
    "scores": {"resolved": 3.4, "policy_consistency": 3.0, "factual_grounding": 3.8, "tone_match": 4.0, "clarity": 4.2},
    "failure_classes": {"none": 3, "missing_instruction": 2, "missing_knowledge": 1, "missing_tool": 0, "needed_human": 1, "agent_error": 2, "human_error": 0}
  }],
  "failure_classes": {...},            // all authors
  "by_playbook": [{"playbook_id": 4, "playbook_slug": "damaged_product", "reviews": 12, "triage_avg": 3.9, "reviewed": 5, "review_avg": 3.0,
                   "needed_human": 2, "ai_replies": 7, "human_replies": 5, "failure_classes": {...}}],
  "needed_human_by_playbook": [{"playbook_id": 4, "playbook_slug": "damaged_product", "needed_human": 2, "reviewed": 5}],
  "daily": [{"date": "2026-11-20", "author_type": "human", "reviews": 3, "triage_avg": 4.0, "review_avg": null}],
  "gaps": [{"failure_class": "missing_tool", "status": "open", "count": 2}],
  "excluded_by_human": 1               // reviews dismissed / marked incorrect in the range
}
```

---

## Report

### `GET /agent/reports/performance`

Query `from?`, `to?` (dates; default last 30 days through end of today; max 400 days), `group?` (`day` \| `week`, ISO weeks starting Monday), `playbook_id?`. → 

```jsonc
{
  "from": "...", "to": "...", "group": "week", "playbook_id": null,
  "periods": [Row],                    // one per day / week in range, zero-filled
  "totals": Row,                       // whole range
  "playbooks": [Row + {playbook_id, playbook_slug, playbook_name}],   // whole range; playbook_id null = "(no playbook)"
  "definitions": {"metric": "sentence"}
}
Row = {
  "period_start": "2026-11-02",        // null in totals / playbooks
  "conversations_received": 2,         // null in playbook rows and when filtered by playbook
  "drafts_produced": 2, "conversations_with_draft": 2, "conversations_sent_as_is": 1,
  "automation_rate": 0.5,              // null when no draft
  "auto_sent": 0, "auto_sent_rate": 0.0,
  "handovers": 1, "handover_reasons": [{"reason": "missing_evidence", "label": "evidence the playbook requires is missing", "count": 1}],
  "feedback": {"sent_as_is": 1, "sent_edited": 1, "gmail_reply": 0, "rejected": 0},
  "edit_rate": 0.5, "avg_percent_changed": 40, "gmail_reply_rate": 0.0,
  "qa": {"by_author_type": {"ai": {"triaged": 1, "triage_avg": 5.0, "reviewed": 1, "review_avg": 4.0}, "ai_edited": {...}, "human": {...}},
         "failure_classes": {"none": 0, "missing_instruction": 0, "...": 0}},
  "cost": {"total_micro_usd": 7000, "total_usd": 0.007,
           "by_role": {"manager": 0.0031, "unattributed": 0.002, "writer": 0.001, "simulation_judge": 0.0005, "triage": 0.0002, "validator": 0.0002},
           "by_purpose": {"live": 0.0033, "manager_chat": 0.0031, "qa": 0.0006}},
  "resolved_conversations": 1,         // null in playbook rows / filtered
  "cost_per_resolved_usd": 0.007, "live_cost_per_resolved_usd": 0.0033,
  "time_to_first_draft": {"count": 2, "avg_seconds": 525, "median_seconds": 525}
}
```

Rates are fractions (0-1, 4 decimals); averages 2 decimals; USD 4 decimals.

### Metric definitions (imported history excluded everywhere)

| metric | definition (date anchor) |
|---|---|
| `conversations_received` | conversations created (not imported, not spam, not deleted) (`conversations.created_at`) |
| `drafts_produced` | live runs with decision `draft` or `auto_send` and a draft (`agent_runs.created_at`) |
| `conversations_with_draft` | distinct conversations of those runs; summed across periods for totals |
| `conversations_sent_as_is` | of those, conversations where such a run has a `sent_as_is` feedback event |
| `automation_rate` | `conversations_sent_as_is / conversations_with_draft` |
| `auto_sent` | distinct conversations with a sent AI message (`threads.origin = ai`, `sent_at` set) (`threads.sent_at`). 0 until auto-send is used |
| `auto_sent_rate` | `auto_sent / conversations_received` |
| `handovers`, `handover_reasons` | live runs with decision `handover`, grouped by reason prefix (`missing_evidence:order` → `missing_evidence`) |
| `feedback.*` | feedback events by kind (`agent_feedback_events.created_at`); `gmail_reply` = staff answered in Gmail instead of using the draft |
| `edit_rate` | `sent_edited / (sent_as_is + sent_edited)` |
| `avg_percent_changed` | mean `percent_changed` of `sent_edited` events |
| `gmail_reply_rate` | `gmail_reply / (sent_as_is + sent_edited + gmail_reply + rejected)` |
| `qa.by_author_type.*` | QA reviews (triaged + reviewed, not dismissed) by author type (`replied_at`): `triage_avg` first-pass mean, `review_avg` full-review `overall` mean |
| `qa.failure_classes` | full reviews by failure class |
| `cost.by_role` | every AI call: agent runs per pipeline step from `trace.usage` (triage / writer incl. fallback and rewrite / validator; run cost not itemised = `unattributed`), QA first pass (`triage`) and full review (`simulation_judge`), QA proposal drafting, manager chat, feedback routing (`meta.routing.cost_micro_usd`) and conflict checks (`conflicts.llm.cost_micro_usd`) (`manager`), simulation judge scores (`simulation_judge`), mining stages (extract / cluster → `history_extraction`, synthesise / voice → `manager`) |
| `cost.by_purpose` | `live`, `shadow`, `preview`, `simulation`, `test` (run purposes), `qa`, `qa_proposals`, `manager_chat`, `feedback_routing`, `conflict_check`, `simulation_judge`, `mining` |
| `resolved_conversations` | non-imported conversations now `closed` whose `updated_at` falls in the period (proxy: there is no closed-at column) |
| `cost_per_resolved_usd` | `cost.total / resolved_conversations`; `live_cost_per_resolved_usd` uses live-run cost only |
| `time_to_first_draft` | for the first drafted live run per customer message: `completed_at − coalesce(message.sent_at, created_at)`, avg and median seconds (`agent_runs.created_at`) |

Per-playbook rows attribute runs, feedback (via the run) and QA reviews (`agent_qa_reviews.playbook_id`) to the playbook; workspace-level costs (manager chat, feedback routing, conflict checks, simulation judge, mining) appear only in periods and totals. With `playbook_id`, those workspace-level costs and the conversation-level counts are left out.

### `GET /agent/reports/performance.csv`

Same query. `text/csv` download `ai-performance-{from}-to-{to}.csv`. One header row, then a `period` row per bucket, one `total` row, then a `playbook` row per playbook. Columns: `section, period_start, playbook, conversations_received, drafts_produced, conversations_with_draft, conversations_sent_as_is, automation_rate, auto_sent, auto_sent_rate, handovers, top_handover_reasons ("reason=n; ..."), sent_as_is, sent_edited, gmail_reply, rejected, edit_rate, avg_percent_changed, gmail_reply_rate, qa_ai_reviewed, qa_ai_avg, qa_ai_edited_reviewed, qa_ai_edited_avg, qa_human_reviewed, qa_human_avg, qa_ai_triage_avg, qa_human_triage_avg, failure_classes ("class=n; ..."), cost_usd, cost_by_role ("role=usd; ..."), cost_by_purpose, resolved_conversations, cost_per_resolved_usd, time_to_first_draft_avg_s, time_to_first_draft_median_s`.

The overview (`GET /agent`, docs/agent-api.md) is unchanged in shape; its aggregate rows are now plain query rows (fixes the PHPStan errors).

---

## Config (`config/agent_qa.php`)

| key | default | |
|---|---|---|
| `enabled` (`AGENT_QA_ENABLED`) | true | master switch |
| `prompt_version` | `qa-v1` | part of the uniqueness key |
| `delay_minutes` (`AGENT_QA_DELAY_MINUTES`) | 10 | close → review delay |
| `sample_rate` (`AGENT_QA_SAMPLE_RATE`) | 1.0 | share of closes reviewed |
| `daily_cap` (`AGENT_QA_DAILY_CAP`) | 60 | non-skipped `closed` reviews per workspace per day |
| `first_pass.threshold` | 4 | first-pass score below = flagged |
| `full_review_sample_rate` (`AGENT_QA_FULL_SAMPLE_RATE`) | 0.15 | unflagged replies that still get the full review |
| `turn_chars`, `max_turns` | 3000, 12 | transcript shown to the reviewers |
| `gaps.proposal_threshold` (`AGENT_QA_GAP_THRESHOLD`) | 3 | distinct conversations per group before drafting |
| `gaps.similarity` | 0.34 | Jaccard threshold |
| `gaps.max_examples` | 6 | cases shown to the manager |
| `unhappy_return.days`, `.sentiments` | 14, [frustrated, angry] | |
| `backfill.connection`, `.queue`, `.max` | redis-long, ai-bulk, 500 | |
| `queue` | ai | |

Rough cost: first pass ≈ 2-4k input tokens on Haiku (~$0.0005); full review ≈ 3-6k on Sonnet (~$0.01-0.02); a QA proposal ≈ one Opus call (~$0.05-0.15).

## Unverified

No live model call is possible in tests: review scores, failure classes, gap wording and the drafted proposals are unverified by construction. Gap grouping depends on the reviewer producing consistent topics / wording; the threshold and similarity may need tuning on real data.
