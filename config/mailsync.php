<?php

/*
 * Mail ledger and sync (app/Domains/Mailbox/Sync, docs/mail-sync.md).
 */
return [

    // First emails:fetch run for a mailbox (no sync cursor yet): also take
    // mail from the last N days, so nothing that arrived around the cutover
    // from the old INBOX-UNSEEN fetcher is missed. Messages the old fetcher
    // already turned into threads are linked, not duplicated.
    'initial_lookback_days' => (int) env('MAIL_SYNC_INITIAL_LOOKBACK_DAYS', 3),

    // Bodies downloaded per folder per emails:fetch run; the rest wait a minute.
    'max_bodies_per_run' => (int) env('MAIL_SYNC_MAX_BODIES_PER_RUN', 200),

    // Gmail: only mail in the Inbox is work. Customer mail that is already
    // archived when it is synced is stored as a closed, imported conversation
    // (no AI draft, notification or auto-reply).
    'inbox_only' => (bool) env('MAIL_SYNC_INBOX_ONLY', true),

    // Mail older than this when it is ingested (the first-sync lookback, or
    // catching up after an outage) still becomes a conversation, but gets no
    // AI draft, automation, notification or auto-reply. 0 = no limit.
    'live_effects_max_age_hours' => (int) env('MAIL_SYNC_LIVE_EFFECTS_MAX_AGE_HOURS', 24),

    // Per-file cap for attachments stored from inbound mail. Larger files are
    // skipped and listed in the thread's meta.skipped_attachments.
    'max_attachment_bytes' => (int) env('MAIL_MAX_ATTACHMENT_BYTES', 20 * 1024 * 1024),

    // Live ledger rows that still have no ingest result after this many
    // minutes are dispatched again by emails:fetch (lost job, worker crash).
    'reingest_after_minutes' => (int) env('MAIL_SYNC_REINGEST_AFTER_MINUTES', 10),

    // History import (mail:backfill, /agent/import). See docs/mail-sync.md.
    'backfill' => [
        // Window offered by default.
        'default_months' => (int) env('MAIL_BACKFILL_MONTHS', 18),

        // Also import threads a customer started that nobody answered.
        // Unanswered threads inside active_window_days are always imported.
        'include_unanswered' => (bool) env('MAIL_BACKFILL_INCLUDE_UNANSWERED', false),

        // A thread whose newest message is the customer's and is at most this
        // many days old is imported open (it still needs a reply), not closed.
        'active_window_days' => (int) env('MAIL_BACKFILL_ACTIVE_WINDOW_DAYS', 14),

        // The job chain runs on the long-running batch queue (Horizon "ai-bulk":
        // timeout 900s, tries 1). Each job works for step_seconds, then queues
        // the next one after throttle_seconds.
        'connection' => env('MAIL_BACKFILL_QUEUE_CONNECTION', 'redis-long'),
        'queue' => env('MAIL_BACKFILL_QUEUE', 'ai-bulk'),
        'step_seconds' => (int) env('MAIL_BACKFILL_STEP_SECONDS', 120),
        'throttle_seconds' => (int) env('MAIL_BACKFILL_THROTTLE_SECONDS', 3),
        'max_slices_per_step' => (int) env('MAIL_BACKFILL_MAX_SLICES_PER_STEP', 500),

        // Metadata sweep: UID ranges per slice (each range is one FETCH of
        // envelopes and holds the per-mailbox sync lock briefly).
        'range_size' => (int) env('MAIL_BACKFILL_RANGE_SIZE', 500),
        'ranges_per_slice' => (int) env('MAIL_BACKFILL_RANGES_PER_SLICE', 4),

        // Body fetch: messages per slice (text parts only, no attachments).
        'bodies_per_slice' => (int) env('MAIL_BACKFILL_BODIES_PER_SLICE', 100),

        // Materialise: threads per slice.
        'threads_per_slice' => (int) env('MAIL_BACKFILL_THREADS_PER_SLICE', 25),

        // Errors back off 30s, 60s, 120s ... up to 15 minutes; after this many
        // in a row the run is marked failed (resume picks up where it stopped).
        'max_consecutive_errors' => (int) env('MAIL_BACKFILL_MAX_CONSECUTIVE_ERRORS', 8),

        // A running backfill with no progress for this long is re-queued by
        // `mail:backfill --recover` (scheduled every five minutes).
        'stall_minutes' => (int) env('MAIL_BACKFILL_STALL_MINUTES', 20),
    ],
];
