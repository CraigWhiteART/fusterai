<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The inbox category the AI agent's triage gave a conversation (orders,
 * returns, technical, product, business, other: config/agent.php). Kept on
 * the conversation so the inbox can filter and count without joining runs.
 * Null = the agent has not looked at it (shown under "Other").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('agent_category', 32)->nullable();
            $table->index(['workspace_id', 'status', 'agent_category'], 'conversations_workspace_status_category_index');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_workspace_status_category_index');
            $table->dropColumn('agent_category');
        });
    }
};
