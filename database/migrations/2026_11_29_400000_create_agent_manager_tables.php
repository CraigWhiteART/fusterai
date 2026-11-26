<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manager chat ("Ask the manager", docs/agent-manager.md).
 *
 *  - agent_manager_chats: one chat per owner; the scope restrictions in
 *    force for every turn ("don't touch refund rules"), running totals.
 *  - agent_manager_messages: user requests and the manager's answers, with
 *    a summary of the tools it used, what it cited, the proposals it staged,
 *    the changes it was not allowed to stage, and token / cost usage.
 *
 * laravel/ai's agent_conversations tables are not used: they are keyed by
 * user only (no workspace), carry no cost / citation / proposal columns and
 * are slated for removal (plan Phase 11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_manager_chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            // {exclude_playbook_ids[], exclude_targets[], only_playbook_ids[]|null, only_targets[]|null, sources[]}
            $table->jsonb('restrictions')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->unsignedBigInteger('tokens_in')->default(0);
            $table->unsignedBigInteger('tokens_out')->default(0);
            $table->unsignedBigInteger('cost_micro_usd')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'user_id', 'updated_at']);
        });

        Schema::create('agent_manager_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained('agent_manager_chats')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // user | assistant
            $table->string('role', 16);
            // pending | running | completed | failed | capped (assistant); completed (user)
            $table->string('status', 16)->default('completed');
            $table->text('content')->nullable();
            $table->foreignId('reply_to_id')->nullable()->constrained('agent_manager_messages')->nullOnDelete();
            // [{tool, arguments, summary, ok, refused}]
            $table->jsonb('tool_calls')->nullable();
            // [{type, id, label, source}]
            $table->jsonb('citations')->nullable();
            $table->jsonb('proposal_ids')->nullable();
            // Ops ProposalService set aside (permissions, limits, modes, rollout) + scope refusals.
            $table->jsonb('suggestions')->nullable();
            // Restrictions in force for this turn.
            $table->jsonb('restrictions')->nullable();
            // {steps, step_cap, estimated_input_tokens, token_budget, budget_reached, deadline_reached}
            $table->jsonb('limits')->nullable();
            $table->string('model')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedInteger('tokens_cache_read')->default(0);
            $table->unsignedInteger('tokens_cache_write')->default(0);
            $table->unsignedInteger('tokens_reasoning')->default(0);
            $table->unsignedBigInteger('cost_micro_usd')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['chat_id', 'id']);
            $table->index(['workspace_id', 'role', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_manager_messages');
        Schema::dropIfExists('agent_manager_chats');
    }
};
