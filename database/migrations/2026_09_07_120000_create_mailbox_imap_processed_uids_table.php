<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_imap_processed_uids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('uid');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['mailbox_id', 'uid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_imap_processed_uids');
    }
};
