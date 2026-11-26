<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Live chat: the visitor widget subscribes to a public Reverb channel, so the
 * channel name must be unguessable. Each chat conversation gets a random
 * token, returned only to the visitor that started it; agent replies are
 * broadcast on `livechat.{chat_token}` instead of `livechat.{conversation_id}`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('chat_token', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique(['chat_token']);
            $table->dropColumn('chat_token');
        });
    }
};
