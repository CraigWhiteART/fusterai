# AI agent: from deployed to live

One ordered checklist for taking the AI support agent from "the code is
deployed" to "it answers customers". Each step links the document that has
the details; this page only says what to do and what to check. Do the steps
in order: each one relies on the one before.

Nothing the agent does reaches a customer until you switch it on. After the
deploy every workspace is in mode **off** (or **draft** if the old Commerce
Assist module was active), every Shopify action policy is **never**, and no
playbook is on **auto**.

> **Read the last section first.** It lists everything in this build that has
> never been run against a live service, what to watch for, and the switch to
> fall back to.

---

## 1. Deploy

The rework lives on the `agent-rework` branch. `php artisan fusterai:update`
pulls `main`, so deploy this branch by hand the first time.

1. **Back up the database** (`pg_dump`). Step 4 below drops the old Commerce
   Assist tables once their rows are copied.
2. `git pull` the branch, then `composer install --no-dev --optimize-autoloader`
   and `npm ci && npm run build`.
3. **Check `.env` before migrating** (all variables are listed in
   `.env.example`):
   - `REDIS_QUEUE_RETRY_AFTER` must be **above 330** (default 360) and
     `REDIS_LONG_QUEUE_RETRY_AFTER` above 900 (default 960). This is the trap:
     an old `.env` with `REDIS_QUEUE_RETRY_AFTER=90` makes Redis hand a
     still-running AI job (up to 300 s) to a second worker, so drafts and
     mining steps run twice. See `config/queue.php` and
     [configuration.md](configuration.md#queue-monitor-horizon).
   - `QUEUE_CONNECTION=redis`, `APP_ENV=production` (Horizon picks its
     supervisors by `APP_ENV`).
4. `php artisan migrate --force`. In order, this:
   - moves the Commerce Assist migrations into core (same names, so nothing
     re-runs), renames `commerce_assist_settings` and the Shopify / tracking
     snapshot tables to `commerce_*` (your Shopify credentials are kept);
   - creates the agent, mail-ledger, mining, QA, auto-send and action tables;
   - imports the old intents → playbooks, approved responses → examples, live
     facts → knowledge (`agent:import-legacy`; unscoped facts become
     **needs scope**);
   - drops the leftover dead tables (Spatie roles, `agent_conversations*`,
     `personal_access_tokens`, `mailbox_imap_processed_uids`,
     `mailboxes.ai_config`);
   - drops the old `commerce_assist_*` tables **only if every row was
     imported**. If not, nothing is dropped and the log has a `CRITICAL`
     line "Commerce Assist tables NOT dropped" listing what is missing per
     workspace. Fix it, then run
     `php artisan agent:import-legacy --drop-old-tables`.

   Afterwards: `grep CRITICAL storage/logs/laravel.log` should find nothing
   from the migration.
5. `php artisan config:cache && php artisan route:cache`.
6. **Restart the long-running processes** so they load the new code and
   config: `php artisan horizon:terminate` (Supervisor / the `horizon`
   container restarts it), restart Reverb, restart the scheduler.
7. **Queues.** Horizon now runs one more supervisor: **`ai-bulk` on the
   `redis-long` connection** (1 process, timeout 900 s, 1 try) for history
   backfill, mining, simulations and QA backfill
   ([config/horizon.php](../config/horizon.php)). Open `/horizon` and check
   that `email-inbound`, `email-outbound`, `ai`, `ai-bulk`, `notifications`,
   `webhooks` and `default` are all running.
8. **Scheduler.** `schedule:work` (the `scheduler` container) or a cron
   `* * * * * php artisan schedule:run` must run. `php artisan schedule:list`
   should show: `emails:fetch` (every minute), `mail:backfill --recover` and
   `agent:mine --recover` (every 5 min), `agent:autosend-breakers` and
   `agent:actions-expire` (every 15 min), `commerce:refresh-shopify-tokens`
   (hourly; the old name `commerce-assist:refresh-shopify-tokens` still works),
   `horizon:snapshot`, `notifications:digest`, and the SLA breach check if
   that module is active.

---

## 2. AI key, models, embeddings

[ai-setup.md](ai-setup.md)

1. **Settings → AI Config**: provider Anthropic, API key, **Test**.
2. **Models by role** (same page): leave the recommended defaults unless you
   have a reason (triage / history extraction Haiku, writer and manager Opus,
   validator and simulation judge Sonnet: the validator deliberately uses a
   different model from the writer).
3. **Embeddings (optional).** Without them retrieval is Postgres full-text
   only, which works. To add semantic search, set an OpenAI (or compatible)
   key under **Embeddings** and press **Test embeddings**.
4. **Auto-suggest replies** (Feature toggles) controls whether drafting starts
   by itself on new mail. "AI Suggest" in a conversation always works (with
   the agent off it makes a one-off draft that is never auto-sent).

---

## 3. Gmail: probe, aliases, first live sync

[mail-sync.md](mail-sync.md)

1. In Gmail settings → Labels, make sure **All Mail** has "Show in IMAP"
   ticked.
2. **Read-only probe** (sends no STORE, uses EXAMINE and BODY.PEEK, so it
   cannot mark anything read):

   ```
   docker compose exec -e IMAP_USERNAME=support@example.com -e IMAP_PASSWORD='app password' \
     laravel.test php tests/Manual/gmail-probe.php
   ```

   It must end with `PASS` (Gmail extensions, All Mail found, flags
   unchanged). If it does not, stop: the sync falls back to INBOX + Sent
   without Gmail thread ids, and threading is weaker.
3. **Mailbox aliases**: on the mailbox settings page add every address that
   is "us" (e.g. the shop's other addresses, the Gmail account name). Mail
   from these is treated as staff mail.
4. **First live sync**: with the scheduler running, `emails:fetch` runs every
   minute. The first run also takes the last 3 days
   (`MAIL_SYNC_INITIAL_LOOKBACK_DAYS`). Check: new mail appears; a reply you
   send from Gmail shows up as a staff reply in the right conversation; a
   customer's reply to an app-sent reply stays in the same conversation; and
   nothing in Gmail was marked read.

---

## 4. History import

[mail-sync.md → Backfill](mail-sync.md#backfill-history-import) · screen `/agent/import`

1. **Dry run**: on `/agent/import` (or
   `php artisan mail:backfill {mailbox} --months=18 --dry-run`). It sweeps
   metadata and runs the thread filter only, and prints how many threads it
   would import. Compare a date range against Gmail.
2. **Import.** It runs on `ai-bulk`, checkpoints every slice, can be paused /
   resumed, and is re-queued by `mail:backfill --recover` if a worker dies.
   Imported conversations are closed and marked `imported`; no notifications,
   auto-replies, automation or AI run for them, and reports ignore them.
   **Undo** removes what the run created.

---

## 5. Learn from history (mining)

[agent-mining.md](agent-mining.md) · screen `/agent/mining`

1. **Small run first**: estimate, then run with a short window, e.g.
   `php artisan agent:mine --estimate --months=2 --max=150 --budget=2`, then
   the same without `--estimate` (or the screen). The budget cap stops the
   run; reruns skip finished work.
2. **Review the proposals** on `/agent/proposals`: each playbook / knowledge /
   voice change shows its evidence threads. Exclude individual changes or ops
   you do not want (partial approval), approve the rest
   ([agent-proposals.md](agent-proposals.md)). Scope any knowledge marked
   **needs scope** (Agent → Knowledge).
3. **Full run** (default 18 months, 3000 conversations, budget
   `AGENT_MINING_BUDGET_USD`), then review again.

---

## 6. Shadow, then draft

[agent-autosend.md → Shadow mode](agent-autosend.md#shadow-mode) · screens `/agent/settings`, `/agent/shadow`

1. **Agent → Settings → mode Shadow.** The agent reads every new email and
   records what it would have done; nobody sees anything, nothing is sent.
   When staff answer, the simulation judge compares the two (sampled and
   capped: `AGENT_SHADOW_SAMPLE_RATE`, `AGENT_SHADOW_DAILY_CAP`).
2. After a week of normal mail, **read the shadow report** (`/agent/shadow`):
   agreement per playbook and the worst disagreements.
3. **Mode Draft.** Drafts appear in the inbox with why (playbook, rules,
   sources, validator). Send / edit / "I'll handle it" as usual.

---

## 7. Teach it

[agent-proposals.md](agent-proposals.md) · [agent-manager.md](agent-manager.md) · [agent-qa.md](agent-qa.md)

1. **Feedback under each draft** ("don't say X", "be warmer"): the manager
   routes it (one-off / voice / playbook / knowledge / example) and stages a
   proposal with a diff, a preview re-run of the conversation and, when there
   are test cases, a regression gate. Approve on `/agent/proposals`.
2. **Manager chat** (`/agent/manager`) for policy changes ("warranty is now
   12 months"): it stages proposals across playbooks; it never changes action
   permissions or limits.
3. **QA** (`/agent/qa`) reviews closed conversations and groups gaps into
   proposals. **Reports** (`/agent/reports`) show automation rate, edit rate,
   QA scores, handover reasons, auto-sends and Shopify actions.

---

## 8. Test cases and simulation

[agent-proposals.md → Regression gate and simulations](agent-proposals.md#regression-gate-and-simulations) · screen `/agent/simulations`

1. Save good conversations as **test cases** (from a conversation, the
   simulations screen, or automatically on approve). Give each playbook you
   might automate at least 5.
2. Run the **playbook tests** (playbook page) and a **batch simulation** over
   past tickets. Old tickets are judged on policy and tone more reliably than
   on exact facts (their Shopify / tracking evidence is reconstructed).

---

## 9. Optional: auto-send one playbook

[agent-autosend.md → Go-live checklist](agent-autosend.md#go-live-checklist-for-the-owner)

Follow that checklist. In short: one low-risk playbook with 5+ passing test
cases; workspace mode **Live** (confirmation dialog); a long wait before
sending (e.g. 1800 s), a low daily cap; playbook to **Auto at 10%**; watch
every auto-reply (countdown, Cancel, Edit instead, This was wrong); raise
slowly. **Pause AI agent** (Agent → Settings) stops all automatic sending at
once and turns waiting replies back into drafts; the **kill switch** stops
the agent and every Shopify action.

---

## 10. Optional: Shopify actions

[agent-actions.md](agent-actions.md) · screens `/agent/actions` (approvals), `/agent/actions/policies`, `/agent/actions/audit`

1. Add the scopes listed in the **Dev-store test script** to the Shopify app,
   release the version and approve it on a **dev store** first; Agent →
   Connections → Test connection.
2. Run the whole dev-store test script (refund approved twice executes once,
   a timeout reconciles, under-cap runs unattended, over-cap queues, a
   mismatched sender hands over).
3. **Decide the 4PX rule** (address change / cancel after the order went to
   the warehouse) before allowing those unattended.
4. Set policies: start with **approval** for everything. **Always**
   (unattended) needs the workspace in Live, a daily cap
   (`caps.actions_per_day`, default 0 = nothing unattended), per-action limits
   and a sender-email match. A reply that depends on an action waits for it;
   if the action fails or needs approval, the reply becomes a draft.

---

## Never run against a live service

Everything below is covered by tests with fakes (`Http::fake`, laravel/ai
fakes, `.eml` fixtures), but none of it has talked to the real service in
this build. Watch the first real uses.

| What | What to watch for | Fallback |
|---|---|---|
| **Live Claude API**: every agent role on `claude-*-5-5` model ids | 4xx from Anthropic (model id, unknown parameter), empty drafts, `triage_error` / `writer_error` / `validator_error` handovers (Agent run trace, `storage/logs`) | Override the model per role in Settings → AI Config; the pipeline hands over instead of drafting on any error |
| **Writer tool loop with (adaptive) thinking**: multi-step tool calls (`search_knowledge`, `lookup_order`, `propose_action`, `submit_reply`) with thinking blocks carried between steps | 400 errors on the second step, runs that end in `writer_error` or the structured `writer_fallback` step, missing `submit_reply` | `AGENT_WRITER_MODE=structured`: one structured call over the prefetched order / tracking evidence and retrieved knowledge, no tools (so no Shopify actions are proposed in this mode). `agent_runs.config.writer_mode` shows which mode a run used |
| **Structured-output schemas** (triage, validator, judge, mining, QA, manager) via native `output_config` | 400 "schema" errors, `triage_error`, mining extract failures | Per-role model override; mining can be paused / resumed |
| **Gmail X-GM extensions** (`X-GM-MSGID`, `X-GM-THRID`, `X-GM-LABELS`, All Mail) | The probe in step 3 not saying `PASS`; conversations split or merged wrongly | The sync falls back to INBOX + Sent and Message-ID / References threading on its own |
| **Gmail IMAP volume limits** during backfill (Gmail throttles or drops large downloads per day) | Backfill `last_error` with connection / bandwidth errors, runs moving to `failed` | It backs off (30 s … 15 min) and resumes from its checkpoint; slow it with `MAIL_BACKFILL_THROTTLE_SECONDS` / smaller `MAIL_BACKFILL_BODIES_PER_SLICE`, or import fewer months |
| **Gmail SMTP keeping our Message-ID** | App-sent replies not linked to their Sent copy (duplicates as `origin = gmail`), customer replies opening new conversations | Matching also uses the `X-FusterAI-Thread` header and In-Reply-To / References from the customer's last message |
| **Shopify mutations** (refund, cancel, address, discount, replacement, hold, note/tag) on API 2026-10 | `userErrors`, executions ending `failed` / `unknown`, reconcile not finding the marker | Keep every policy on **approval** (or **never**); the dev-store script first. Unknown outcomes are never retried blind: they hand over |
| **Shopify protected customer data** (order email redacted outside dev stores until Shopify approves the app) | Every money action going to approval with `sender_email_mismatch`; order details withheld from drafts (`order_email_mismatch` handover) | Request protected-customer-data access for the app; until then actions stay approval-only |
| **Carrier APIs** (Track123, AfterShip) | Order panel tracking empty or erroring; `missing_evidence` handovers | Agent → Connections: tracking provider **none** (drafts then rely on Shopify fulfilment data only) |
| **Reverb events in a browser** (draft ready, auto-send countdown, action cards, proposals, mining / simulation progress, live chat on `livechat.{chat_token}`) | Screens that only update after a reload; live chat widget not receiving agent replies | Reload; the screens also poll or load state over HTTP. Check `REVERB_*` keys match the built assets |
| **Every screen visually** (inbox draft panel, Agent screens, approvals, policies, reports, shadow report, import, mining) | Layout or wording problems; nothing in this build was looked at in a browser | — |

The model-quality items (draft quality, mining synthesis, manager proposals,
QA scores) are unverified by construction: tests fake the model and only
prove the guardrails. See the "Unverified" sections of
[agent-manager.md](agent-manager.md), [agent-qa.md](agent-qa.md) and
[agent-actions.md](agent-actions.md#not-verified-treated-conservatively).
