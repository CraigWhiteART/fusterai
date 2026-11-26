<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * History backfill (docs/mail-sync.md, "Backfill").
 *
 *  - mail_backfill_runs: one row per import of a mailbox's history, with its
 *    stage cursor, counters and status.
 *  - mail_backfill_threads: the thread filter's decision for every thread the
 *    run looked at (kept / dropped / skipped and why), plus the per-thread
 *    checkpoints of the body-fetch and materialise stages.
 *  - mail_messages.backfill_run_id / conversations.backfill_run_id: what a run
 *    ingested and created, so it can be undone without touching live mail.
 *  - mail_messages.attachment_meta: name / type / size of attachments, kept
 *    for history instead of the attachment bytes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_backfill_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mailbox_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedSmallInteger('months');
            $table->date('since');
            $table->jsonb('options')->nullable();

            // queued | running | paused | completed | failed | cancelled | undoing | undone
            $table->string('status', 16)->default('queued');
            // metadata | filter | bodies | materialise | index | done
            $table->string('stage', 16)->default('metadata');
            $table->jsonb('counters')->nullable();
            // The job chain that currently owns the run; any other queued step drops itself.
            $table->string('job_token', 64)->nullable();

            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('consecutive_errors')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['status', 'heartbeat_at']);
        });

        // At most one unfinished backfill per mailbox.
        DB::statement(
            'CREATE UNIQUE INDEX mail_backfill_runs_one_active_per_mailbox '
            ."ON mail_backfill_runs (mailbox_id) WHERE status IN ('queued', 'running', 'paused', 'undoing')"
        );

        Schema::create('mail_backfill_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('mail_backfill_runs')->cascadeOnDelete();
            $table->string('thread_key', 64);
            $table->decimal('gm_thrid', 20, 0)->nullable();

            // kept | dropped | skipped
            $table->string('decision', 16);
            $table->string('reason', 32);
            $table->boolean('active')->default(false);

            $table->jsonb('message_ids');
            $table->unsignedInteger('message_count');
            $table->unsignedInteger('inbound_count')->default(0);
            $table->unsignedInteger('outbound_count')->default(0);
            $table->string('customer_email')->nullable();
            $table->text('subject')->nullable();
            $table->timestamp('first_at')->nullable();
            $table->timestamp('last_at')->nullable();

            $table->timestamp('bodies_fetched_at')->nullable();
            $table->timestamp('materialised_at')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'thread_key']);
            $table->index(['run_id', 'decision', 'reason']);
            $table->index(['run_id', 'decision', 'last_at']);
        });

        Schema::table('mail_messages', function (Blueprint $table) {
            $table->jsonb('attachment_meta')->nullable();
            $table->foreignId('backfill_run_id')->nullable()->constrained('mail_backfill_runs')->nullOnDelete();
            $table->index('backfill_run_id');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('backfill_run_id')->nullable()->constrained('mail_backfill_runs')->nullOnDelete();
            $table->index('backfill_run_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('backfill_run_id');
        });

        Schema::table('mail_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('backfill_run_id');
            $table->dropColumn('attachment_meta');
        });

        Schema::dropIfExists('mail_backfill_threads');
        Schema::dropIfExists('mail_backfill_runs');
    }
};
