<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wires the mail ledger (mail_messages) into conversations and threads.
 *
 * threads.message_id is indexed but not unique: the same Message-ID can
 * legitimately appear twice (e.g. one message delivered to two aliases), and
 * idempotency is enforced per ledger row instead (threads.mail_message_id is
 * unique; PostgreSQL treats NULLs as distinct, so threads not created from
 * the ledger never collide).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            // RFC 5322 Message-ID without angle brackets (inbound or outbound).
            $table->string('message_id', 998)->nullable()->index();
            // Date the message was actually sent (Date header), when it came by mail.
            $table->timestamp('sent_at')->nullable();
            // Where the message was written: fusterai | gmail | import | ai.
            $table->string('origin', 16)->default('fusterai');
            $table->foreignId('mail_message_id')->nullable()->unique()->constrained('mail_messages')->nullOnDelete();
        });

        Schema::table('conversations', function (Blueprint $table) {
            // X-GM-THRID: unsigned 64-bit, so numeric(20,0) like mail_messages.
            $table->decimal('gm_thrid', 20, 0)->nullable()->index();
            $table->boolean('imported')->default(false);
            $table->string('handover_reason')->nullable();
        });

        Schema::table('mailboxes', function (Blueprint $table) {
            // Extra addresses that count as "us" (Gmail "Send mail as" aliases).
            $table->jsonb('aliases')->nullable();
        });

        Schema::table('attachments', function (Blueprint $table) {
            // Inline image referenced from the HTML body (cid:), not a download.
            $table->boolean('inline')->default(false);
        });

        Schema::table('mail_messages', function (Blueprint $table) {
            // Set once IngestMailMessageJob has looked at the row (threaded or skipped).
            $table->timestamp('ingested_at')->nullable();
            $table->string('ingest_outcome', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->dropColumn(['ingested_at', 'ingest_outcome']);
        });

        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn('inline');
        });

        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn('aliases');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['gm_thrid']);
            $table->dropColumn(['gm_thrid', 'imported', 'handover_reason']);
        });

        Schema::table('threads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mail_message_id');
            $table->dropIndex(['message_id']);
            $table->dropColumn(['message_id', 'sent_at', 'origin']);
        });
    }
};
