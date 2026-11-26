<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Drops tables nothing reads any more (plan Phase 11):
 *
 *  - spatie/laravel-permission (permissions, roles, model_has_*,
 *    role_has_permissions): roles are the users.role string; the package
 *    was removed.
 *  - laravel/ai agent_conversations / agent_conversation_messages: only
 *    used by laravel/ai's RemembersConversations trait, which no agent uses
 *    (the manager chat keeps its own agent_manager_* tables).
 *  - personal_access_tokens: Sanctum's table; Sanctum is not installed
 *    (API auth is Passport, oauth_* tables).
 *  - mailbox_imap_processed_uids: the old INBOX UNSEEN fetcher's UID list,
 *    replaced by mail_messages + mail_sync_states.
 *
 * Also drops the never-read mailboxes.ai_config column.
 *
 * down() recreates the (empty) tables and the column with their original
 * definitions; dropped rows are not restored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');

        Schema::dropIfExists('agent_conversation_messages');
        Schema::dropIfExists('agent_conversations');

        Schema::dropIfExists('personal_access_tokens');

        Schema::dropIfExists('mailbox_imap_processed_uids');

        if (Schema::hasColumn('mailboxes', 'ai_config')) {
            Schema::table('mailboxes', function (Blueprint $table) {
                $table->dropColumn('ai_config');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('mailboxes', 'ai_config')) {
            Schema::table('mailboxes', function (Blueprint $table) {
                $table->jsonb('ai_config')->nullable();
            });
        }

        if (! Schema::hasTable('mailbox_imap_processed_uids')) {
            Schema::create('mailbox_imap_processed_uids', function (Blueprint $table) {
                $table->id();
                $table->foreignId('mailbox_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('uid');
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['mailbox_id', 'uid']);
            });
        }

        if (! Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->morphs('tokenable');
                $table->text('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('agent_conversations')) {
            Schema::create('agent_conversations', function (Blueprint $table) {
                $table->string('id', 36)->primary();
                $table->foreignId('user_id')->nullable();
                $table->string('title');
                $table->timestamps();

                $table->index(['user_id', 'updated_at']);
            });
        }

        if (! Schema::hasTable('agent_conversation_messages')) {
            Schema::create('agent_conversation_messages', function (Blueprint $table) {
                $table->string('id', 36)->primary();
                $table->string('conversation_id', 36)->index();
                $table->foreignId('user_id')->nullable();
                $table->string('agent');
                $table->string('role', 25);
                $table->text('content');
                $table->text('attachments');
                $table->text('tool_calls');
                $table->text('tool_results');
                $table->text('usage');
                $table->text('meta');
                $table->timestamps();

                $table->index(['conversation_id', 'user_id', 'updated_at'], 'conversation_index');
                $table->index(['user_id']);
            });
        }

        if (! Schema::hasTable('permissions')) {
            Schema::create('permissions', static function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();

                $table->unique(['name', 'guard_name']);
            });

            Schema::create('roles', static function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();

                $table->unique(['name', 'guard_name']);
            });

            Schema::create('model_has_permissions', static function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
                $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
                $table->primary(['permission_id', 'model_id', 'model_type'], 'model_has_permissions_permission_model_type_primary');
            });

            Schema::create('model_has_roles', static function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
                $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
                $table->primary(['role_id', 'model_id', 'model_type'], 'model_has_roles_role_model_type_primary');
            });

            Schema::create('role_has_permissions', static function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
                $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
                $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
            });
        }
    }
};
