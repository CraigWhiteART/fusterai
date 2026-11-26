<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger of every message seen in a mailbox (inbound, sent from Gmail, sent
 * from fusterai, archived). One row per message, keyed by Gmail's X-GM-MSGID
 * when the server is Gmail, otherwise by the RFC Message-ID.
 *
 * X-GM-MSGID / X-GM-THRID are unsigned 64-bit, so they are numeric(20,0):
 * PHP ints are signed 64-bit and would overflow above 2^63-1. Eloquent reads
 * them back as strings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->constrained()->cascadeOnDelete();

            // Gmail identity (null on non-Gmail servers).
            $table->decimal('gm_msgid', 20, 0)->nullable();
            $table->decimal('gm_thrid', 20, 0)->nullable();

            // Where the message was seen. Updated if UIDVALIDITY changes.
            $table->string('folder', 512);
            $table->unsignedBigInteger('uidvalidity');
            $table->unsignedBigInteger('uid');

            // RFC 5322 identity and threading, normalised without angle brackets.
            $table->string('message_id', 998)->nullable();
            $table->string('in_reply_to', 998)->nullable();
            $table->jsonb('references')->nullable();

            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();
            $table->jsonb('reply_to')->nullable();
            $table->jsonb('to')->nullable();
            $table->jsonb('cc')->nullable();
            $table->text('subject')->nullable();

            $table->timestamp('sent_at')->nullable();       // Date header
            $table->timestamp('internal_date')->nullable(); // IMAP INTERNALDATE

            $table->string('direction', 16)->default('inbound'); // inbound|outbound|internal
            $table->string('customer_email')->nullable();         // Reply-To wins for contact forms
            $table->jsonb('labels')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->boolean('has_attachments')->nullable(); // null until the body is fetched

            $table->longText('body_html')->nullable();
            $table->longText('body_text')->nullable();
            $table->longText('body_clean')->nullable();
            $table->timestamp('body_fetched_at')->nullable();

            $table->string('classification', 16)->nullable(); // support|bulk|auto|notification|unknown

            $table->foreignId('thread_id')->nullable()->constrained('threads')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();

            $table->jsonb('headers')->nullable();
            $table->timestamps();

            // Plain unique: PostgreSQL treats NULLs as distinct, so non-Gmail rows
            // (gm_msgid null) never collide, and ON CONFLICT (mailbox_id, gm_msgid)
            // can infer this index (it could not infer a partial one).
            $table->unique(['mailbox_id', 'gm_msgid']);

            $table->index('gm_thrid');
            $table->index('message_id');
            $table->index(['mailbox_id', 'sent_at']);
            $table->index(['mailbox_id', 'folder', 'uidvalidity', 'uid']);
            $table->index(['mailbox_id', 'customer_email']);
            $table->index('conversation_id');
        });

        // Fallback identity for non-Gmail servers only. Partial, because on Gmail
        // the same Message-ID can legitimately exist under two X-GM-MSGIDs.
        DB::statement(
            'CREATE UNIQUE INDEX mail_messages_mailbox_message_id_fallback_unique '
            .'ON mail_messages (mailbox_id, message_id) '
            .'WHERE gm_msgid IS NULL AND message_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_messages');
    }
};
