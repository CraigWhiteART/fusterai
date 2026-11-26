# Mail ledger and sync

Code: `app/Domains/Mailbox/Sync`. Tables: `mail_messages`, `mail_sync_states`.

## Shape

- `MailSource` (contract) reads a mailbox: `syncFolders()`, `folderState()`, `fetchEnvelopes()`, `fetchBodies()`, `firstUidOnOrAfter()`. It is read-only: EXAMINE, never SELECT; BODY.PEEK, never BODY; no STORE.
- `ImapMailSource` implements it on webklex/php-imap's socket and LOGIN, but sends commands itself (`ImapProtocol::sendRequest()` + `nextLine()`) and parses the bytes with `ImapWireParser`. webklex 6.2's FETCH decoder splits the last quoted item of a list, so quoted `X-GM-LABELS` come back broken. A test pins that behaviour.
- `FakeMailSource` serves `.eml` fixtures (`tests/Fixtures/mail`, with `manifest.json` for UIDs, Gmail ids and labels). It can inject a failure and simulate a UIDVALIDITY change.
- `MessageParser` turns RFC 822 into ledger fields. It reads headers with our own `HeaderBlock` and decodes MIME with webklex `Message::fromString()`. `QuoteStripper` fills `body_clean`.
- `ThreadResolver` returns a `ThreadDecision` (direction, customer, conversation, how it matched). It never creates anything.
- `MailSyncService` has `syncNew()` (UIDs above `last_uid`, with bodies) and `backfillMetadata()` (walks UIDs down to a date, envelopes only). Each UID range is written in one transaction with its checkpoint.

## Live path

- `emails:fetch` (every minute, `withoutOverlapping(15)`, plus a per-mailbox cache lock) calls `syncNew()` for each active IMAP mailbox and queues one `IngestMailMessageJob` per new row on `email-inbound`. A mailbox that fails is logged and the others still run. The first run takes `mailsync.initial_lookback_days` (default 3) so nothing around the cutover is missed. Live rows (UID at or above `live_from_uid`) with no `ingested_at` after 10 minutes are queued again (lost job, crashed worker), for up to 48 hours.
- `MailIngestService` (behind the job) is idempotent per ledger row: it locks the row, re-reads it, and writes `thread_id` / `conversation_id` / `ingested_at` / `ingest_outcome` in the same transaction as the thread. `threads.mail_message_id` is unique as a backstop. Ingests are serialised per mailbox with a transaction-scoped advisory lock.
- Outcomes: `created`; `linked` (the Sent copy of a reply fusterai sent, matched by `X-FusterAI-Thread` or Message-ID, or a message the old fetcher had already imported); `skipped_automated` (classifier says bulk / auto-reply / notification: no conversation, which is the auto-reply loop protection); `skipped_internal`; `skipped_no_customer`.
- Inbound mail: customer from the resolver (Reply-To for contact forms), conversation reused or created (`conversations.gm_thrid` set), status Open, snooze cleared. Then, in order: a blocked sender's conversation is marked spam and nothing else runs; otherwise hooks, broadcasts, `RunAutomationRulesJob` (`conversation.created` or `conversation.replied`), AI jobs, the assigned-agent notification, and the auto-reply for a new conversation (not when the sender set `X-Auto-Response-Suppress`).
- Staff mail written in Gmail: a thread with `origin = gmail`, `customer_id` null and `user_id` set when the From address is a user's address (otherwise null; the UI shows the sender name from `meta.from_name`). It never reopens the conversation and starts no AI, automation or auto-reply. If it starts a new conversation, the recipient is the customer and the status is Pending.
- `body` keeps the raw HTML (sanitised in the browser), `body_plain` the `QuoteStripper` text. `sent_at` is the Date header; the thread's `created_at` (which orders threads) and the conversation's `last_reply_at` use it, unless it disagrees with INTERNALDATE by more than a day.
- Attachments are read again from the server (the ledger keeps no attachment bytes), capped by `mailsync.max_attachment_bytes` (20 MB). Inline raster images are stored with `inline = true` and their `cid:` references rewritten to `/attachments/{id}`, served by `AttachmentController` to staff who can see the conversation.
- `new IngestMailMessageJob($id, historical: true)` suppresses every side effect, creates conversations closed and `imported`, and marks threads `origin = import` (for the backfill).
- Replies sent by fusterai: `Message-ID: thread-{id}-{uuid}@fusterai` (stored in `threads.message_id`), `X-FusterAI-Thread: {id}`, and `In-Reply-To` / `References` from the customer's last inbound ledger message (`ReplyHeaders`), falling back to `<conversation-N@fusterai>`.
- Mailbox aliases (`mailboxes.aliases`, edited on the mailbox settings page) are extra addresses that count as "us".

## Folders

- On Gmail, with `X-GM-EXT-1` advertised and a folder carrying `\All`, the sync uses that one folder. Its name is localised. It holds inbox, sent and archived mail, but not Spam or Trash.
- Otherwise the sync uses INBOX plus the `\Sent` folder, falling back to a folder named like "Sent". Gmail ids are null, and rows are de-duplicated on `(mailbox_id, message_id)`.
- If Gmail's All Mail has "Show in IMAP" turned off, the sync falls back to INBOX + Sent but still has the Gmail ids.

## Cursor rules

- The first `syncNew` starts at UIDNEXT-1, so only mail from now on counts as live. `configure(initialLookbackDays: N)` starts N days back instead. `live_from_uid` marks where live mail begins, and the backfill sweeps below it.
- When UIDVALIDITY changes, live sync restarts at the first UID on or after `last_synced_at - 1 day`. The backfill sweep restarts from the top and moves old rows to their new UIDs. The unique keys stop duplicates.
- FETCH ranges are always `lo:hi`, never `n:*`, because `UID FETCH n:*` returns the newest message even when n is above every UID.

## Backfill (history import)

Code: `app/Domains/Mailbox/Sync/Backfill`. Tables: `mail_backfill_runs` (one per import: window, options, stage, counters, status, last error), `mail_backfill_threads` (the filter's decision per thread, plus per-thread checkpoints). `mail_messages.backfill_run_id` and `conversations.backfill_run_id` record what a run ingested and created. Settings: `config/mailsync.php` → `backfill`.

- Start it from `/agent/import` (admins, `manage-settings` gate) or `php artisan mail:backfill {mailbox} --months=18 [--dry-run] [--include-unanswered] [--sync]`. `--dry-run` runs stages A and B only and prints the counts. Only one unfinished run per mailbox (partial unique index).
- `RunMailBackfillJob` runs on `ai-bulk` / `redis-long`. Each job works for `step_seconds` (120 s), saving after every slice, then queues the next job `throttle_seconds` later. Errors back off 30 s, 60 s ... up to 15 min, and are stored in `last_error`. After `max_consecutive_errors` the run is `failed`; Resume carries on from the checkpoint. Pause takes effect after the current slice. Every resume gets a new `job_token`, and queued steps with an older token exit. `mail:backfill --recover` (every five minutes) re-queues a run with no heartbeat for `stall_minutes`.
- Stages:
  - **A. metadata**: `backfillMetadata()` down to the start of the window, a few UID ranges per slice. It holds the per-mailbox `MailSyncLock` that `emails:fetch` also takes, because both move the cursor in `mail_sync_states`. If the lock is busy, the slice waits 15 s; live sync skips at most one minute.
  - **B. filter**: `ThreadGrouper` groups ledger rows by `gm_thrid` (without Gmail ids: the References graph, then the same normalised subject from the same customer). `ThreadFilter` keeps two-way threads only (a human customer message and one of ours). It drops drafts and chats, internal mail, bulk mail / auto-replies / notifications, mail we sent that nobody answered, and unanswered threads (unless `--include-unanswered`). Unanswered threads from inside `active_window_days` are always kept. Rows at or above `live_from_uid` belong to live sync and are never imported. The stage is one transaction and is recomputed from scratch if interrupted.
  - **C. bodies**: `hydrateBodies(textOnly: true)` for the kept threads, newest first. Messages over 256 KB are fetched as `BODYSTRUCTURE`, then the header and text sections only (`BODY.PEEK[1.1]` ...). Attachments are recorded in `attachment_meta` (name, type, size) and never downloaded.
  - **D. materialise**: each kept thread's messages, oldest first, go through `MailIngestService::ingest(historical: true)`, the same path live mail takes. The result is one conversation per (Gmail thread, customer): closed, `imported`, dated by the mail, threads `origin = import`, staff mail attributed to the matching user. No hooks, notifications, broadcasts, automation, AI, auto-reply, activity log or per-save search sync, and no attachment files. A thread whose newest message is the customer's and is inside `active_window_days` gets status open and is not marked imported. History that belongs to a conversation live sync already created is added to it, and its status is left alone. If a customer later writes again on an imported conversation, live ingest reopens it and clears `imported`.
  - **index**: the run's conversations are pushed to Scout in batches of 500.
- **Undo** (`undoing` → `undone`):
  - Deletes the conversations the run created, unless one has since got a live message or a staff note. Then it keeps the conversation and deletes only the run's threads.
  - Deletes the threads the run added to other conversations.
  - Resets the ledger rows it ingested, so a later run can import them again.
  - Customers created by the run are kept.
- Imported history is left out of the reports (`ReportsService`), the dashboard trend and recent list, and unread dots. Surveys are never sent for it (`SendSurveyJob`). SLA and routing never see it, because they hang off hooks that historical mode does not fire.

## Verifying against the real Gmail account

The probe is read-only. It sends CAPABILITY, LIST, EXAMINE, UID SEARCH and UID FETCH with BODY.PEEK or FLAGS, then LOGOUT:

```
docker compose -f docker-compose.test.yml run --rm --no-deps --entrypoint php \
  -e IMAP_USERNAME=support@example.com -e IMAP_PASSWORD='app password' \
  test tests/Manual/gmail-probe.php
```

It prints JSON with the capabilities, the folders and their roles, the folder state, and the newest envelopes with `gm_msgid`, `gm_thrid` and labels. It also prints `flags_unchanged`, which compares FLAGS before and after the fetch. It exits 0 with `PASS` when the Gmail extensions, All Mail and the read-only fetch are all confirmed.
