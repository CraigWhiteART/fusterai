<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8: auto-send and rollout (docs/agent-autosend.md).
 *
 *  - agent_settings: global pause (paused_until, set by the hourly breaker or
 *    the "Pause AI agent" control) and auto-send options (languages,
 *    channels, disclosure line, status after send).
 *  - agent_playbooks: who enabled auto and when; a retest hold after an
 *    approved change; the last breaker demotion.
 *  - agent_runs.auto_send: the lifecycle of an auto-send decision
 *    (scheduled / sent / cancelled + events), shown on the run payload.
 *  - agent_shadow_comparisons: shadow run vs the human's actual reply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_settings', function (Blueprint $table) {
            $table->timestamp('paused_until')->nullable();
            $table->string('paused_reason', 255)->nullable();
            $table->jsonb('auto_send')->nullable();
        });

        Schema::table('agent_playbooks', function (Blueprint $table) {
            $table->timestamp('auto_enabled_at')->nullable();
            $table->foreignId('auto_enabled_by')->nullable()->constrained('users')->nullOnDelete();
            // retesting: an approved change is being re-tested; auto-send waits.
            $table->string('auto_hold', 24)->nullable();
            $table->unsignedBigInteger('auto_hold_simulation_id')->nullable();
            $table->timestamp('demoted_at')->nullable();
            $table->string('demotion_reason', 255)->nullable();
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->jsonb('auto_send')->nullable();
        });

        Schema::create('agent_shadow_comparisons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('run_id')->unique()->constrained('agent_runs')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('playbook_id')->nullable()->constrained('agent_playbooks')->nullOnDelete();
            // The human reply it was compared with.
            $table->foreignId('human_thread_id')->nullable()->constrained('threads')->nullOnDelete();
            // ok | failed | skipped (sampled out / daily cap / nothing to compare)
            $table->string('status', 16);
            $table->string('skip_reason', 48)->nullable();
            // Gate said auto_send apart from the workspace being in shadow mode.
            $table->boolean('would_auto_send')->default(false);
            // ... and also ignoring the playbook mode and rollout (a candidate for auto).
            $table->boolean('eligible_if_auto')->default(false);
            $table->string('ai_decision', 16)->nullable();
            $table->boolean('agreed')->nullable();
            // Mean of the judge's 1-5 scores (lower = worse disagreement).
            $table->decimal('score', 4, 2)->nullable();
            $table->jsonb('scores')->nullable();
            $table->unsignedBigInteger('cost_micro_usd')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['workspace_id', 'playbook_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_shadow_comparisons');

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn('auto_send');
        });

        Schema::table('agent_playbooks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('auto_enabled_by');
            $table->dropColumn(['auto_enabled_at', 'auto_hold', 'auto_hold_simulation_id', 'demoted_at', 'demotion_reason']);
        });

        Schema::table('agent_settings', function (Blueprint $table) {
            $table->dropColumn(['paused_until', 'paused_reason', 'auto_send']);
        });
    }
};
