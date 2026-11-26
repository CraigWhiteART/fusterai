<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per mailbox + folder sync cursor. Replaces mailbox_imap_processed_uids.
 *
 * last_uid moves up (new mail); backfill_lowest_uid moves down (history).
 * Both are only advanced after a range has been written to mail_messages, so a
 * crash mid-run resumes from the last finished range.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_sync_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->constrained()->cascadeOnDelete();
            $table->string('folder', 512);

            $table->unsignedBigInteger('uidvalidity')->nullable();
            $table->unsignedBigInteger('uidnext')->nullable();
            $table->unsignedBigInteger('last_uid')->default(0);
            // First UID owned by syncNew (live mail). Rows below it belong to the
            // backfill and must never be treated as new mail.
            $table->unsignedBigInteger('live_from_uid')->nullable();

            // Backfill cursor: lowest UID already swept (exclusive upper bound of
            // the next range), the UID to stop at, and the target date.
            $table->unsignedBigInteger('backfill_lowest_uid')->nullable();
            $table->unsignedBigInteger('backfill_stop_uid')->nullable();
            $table->date('backfill_since')->nullable();
            $table->timestamp('backfill_completed_at')->nullable();

            $table->string('status', 16)->default('idle'); // idle|syncing|backfilling|failed
            $table->unsignedBigInteger('synced_count')->default(0);
            $table->unsignedBigInteger('backfilled_count')->default(0);
            $table->unsignedInteger('uidvalidity_resets')->default(0);
            $table->unsignedInteger('consecutive_failures')->default(0);

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_backfilled_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();

            $table->unique(['mailbox_id', 'folder']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_sync_states');
    }
};
