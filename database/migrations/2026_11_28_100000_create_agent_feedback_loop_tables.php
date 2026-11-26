<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: feedback loop and simulation. Proposals are staged, field-level
 * change sets against the instruction artifacts; frozen cases are
 * point-in-time snapshots of a past conversation; test cases add
 * expectations; simulations re-run the pipeline over frozen cases.
 * Contract: docs/agent-proposals.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_frozen_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            // The customer message the transcript is cut at (included).
            $table->foreignId('cut_thread_id')->nullable()->constrained('threads')->nullOnDelete();
            // The first staff reply after the cut (what the human actually sent).
            $table->foreignId('human_thread_id')->nullable()->constrained('threads')->nullOnDelete();
            $table->string('subject')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->jsonb('reply_to')->nullable();
            // list<TranscriptTurn::toArray()>, up to and including the cut.
            $table->jsonb('transcript');
            // "now" for the simulated run.
            $table->timestamp('cut_at');
            // {shopify: payload|null, tracking: payload|null, shopify_snapshot_id, tracking_snapshot_id, source, notes[]}
            $table->jsonb('evidence')->nullable();
            // recorded | reconstructed | none
            $table->string('evidence_quality')->default('none');
            $table->string('evidence_hash', 64)->nullable();
            $table->timestamp('evidence_prepared_at')->nullable();
            $table->text('evidence_error')->nullable();
            $table->text('human_reply')->nullable();
            $table->string('human_reply_by')->nullable();
            $table->foreignId('human_reply_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('human_reply_at')->nullable();
            $table->string('human_reply_origin')->nullable();
            // feedback | mining | manual | qa
            $table->string('source')->default('manual');
            $table->string('playbook_slug')->nullable();
            $table->jsonb('meta')->nullable();
            $table->string('content_hash', 64);
            $table->timestamps();

            $table->unique(['workspace_id', 'content_hash']);
            $table->index(['workspace_id', 'evidence_quality']);
            $table->index(['workspace_id', 'cut_at']);
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->foreign('frozen_case_id')->references('id')->on('agent_frozen_cases')->nullOnDelete();
        });

        Schema::create('agent_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            // feedback | manager | qa | mining
            $table->string('origin');
            // draft | testing | ready | approved | rejected | applied | stale | reverted
            $table->string('status')->default('draft');
            $table->string('title');
            $table->text('rationale')->nullable();
            // Free-form provenance: conversation_ids, thread_ids, run_id, quotes...
            $table->jsonb('evidence')->nullable();
            $table->string('base_config_hash', 64);
            $table->unsignedBigInteger('gate_simulation_id')->nullable();
            // Feedback routing scope that produced it (voice | playbook | knowledge | example), or null.
            $table->string('scope')->nullable();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            $table->foreignId('feedback_event_id')->nullable()->constrained('agent_feedback_events')->nullOnDelete();
            // Ops the code refused (permissions, limits, modes, rollout): shown as plain advice.
            $table->jsonb('suggestions')->nullable();
            // ConflictChecker report.
            $table->jsonb('conflicts')->nullable();
            $table->timestamp('conflicts_checked_at')->nullable();
            // Latest preview: {status, run_id, baseline_run_id, error}
            $table->jsonb('preview')->nullable();
            // What approval wrote, so revert can undo it exactly.
            $table->jsonb('applied')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('reverted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reverted_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status', 'created_at']);
            $table->index(['workspace_id', 'origin']);
        });

        Schema::create('agent_proposal_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->constrained('agent_proposals')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            // voice | playbook | knowledge | example | test_case
            $table->string('target_type');
            // Playbook / knowledge item / example id; null for creates and voice.
            $table->unsignedBigInteger('target_id')->nullable();
            // Live version the ops were validated against (voice / playbook).
            $table->unsignedBigInteger('base_version_id')->nullable();
            $table->jsonb('ops');
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            // Human-readable diff: [{op, field, item_id, label, before, after}]
            $table->jsonb('diff')->nullable();
            $table->timestamps();

            $table->index(['proposal_id', 'position']);
        });

        Schema::create('agent_test_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('playbook_id')->nullable()->constrained('agent_playbooks')->nullOnDelete();
            $table->foreignId('frozen_case_id')->constrained('agent_frozen_cases')->cascadeOnDelete();
            $table->string('name');
            // {must_include[], must_not_include[], expected_outcome, expected_handover_reason, expected_actions[], notes}
            $table->jsonb('expectation');
            $table->boolean('pinned')->default(false);
            // feedback | mining | manual | qa
            $table->string('source')->default('manual');
            // draft | active | archived
            $table->string('status')->default('active');
            $table->foreignId('proposal_id')->nullable()->constrained('agent_proposals')->nullOnDelete();
            $table->foreignId('feedback_event_id')->nullable()->constrained('agent_feedback_events')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'status', 'playbook_id']);
            $table->index(['workspace_id', 'pinned']);
        });

        Schema::create('agent_simulations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            // batch | gate | playbook_tests
            $table->string('kind');
            // pending | running | paused | completed | cancelled | budget_exhausted | failed
            $table->string('status')->default('pending');
            $table->foreignId('proposal_id')->nullable()->constrained('agent_proposals')->nullOnDelete();
            $table->foreignId('playbook_id')->nullable()->constrained('agent_playbooks')->nullOnDelete();
            // Simulation config keys (pipeline config hash + knowledge/example fingerprint).
            $table->string('candidate_config_hash', 64);
            $table->string('baseline_config_hash', 64)->nullable();
            // InstructionSet overlay for the candidate (null = live).
            $table->jsonb('overlay')->nullable();
            $table->jsonb('selection')->nullable();
            // Ordered list of [frozen_case_id, test_case_id|null] to run.
            $table->jsonb('cases');
            $table->unsignedInteger('cursor')->default(0);
            // {cases, done, pass, fail, regressed, improved, changed, error, cached}
            $table->jsonb('totals')->nullable();
            $table->unsignedBigInteger('cost_micro_usd')->default(0);
            $table->unsignedBigInteger('estimated_cost_micro_usd')->default(0);
            $table->unsignedBigInteger('budget_micro_usd')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'kind', 'created_at']);
        });

        Schema::table('agent_proposals', function (Blueprint $table) {
            $table->foreign('gate_simulation_id')->references('id')->on('agent_simulations')->nullOnDelete();
        });

        Schema::create('agent_simulation_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('simulation_id')->constrained('agent_simulations')->cascadeOnDelete();
            $table->foreignId('frozen_case_id')->constrained('agent_frozen_cases')->cascadeOnDelete();
            $table->foreignId('test_case_id')->nullable()->constrained('agent_test_cases')->nullOnDelete();
            // Candidate run (the configuration under test).
            $table->foreignId('agent_run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            $table->foreignId('baseline_run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            $table->foreignId('rerun_run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            // Cache key parts: what config + which case content/evidence this result scored.
            $table->string('config_hash', 64)->nullable();
            $table->string('case_key', 64)->nullable();
            $table->jsonb('scores')->nullable();
            $table->jsonb('baseline_scores')->nullable();
            $table->boolean('passed')->nullable();
            $table->boolean('baseline_passed')->nullable();
            // pass | fail | regressed | improved | changed | error
            $table->string('verdict')->nullable();
            $table->text('judge_notes')->nullable();
            $table->boolean('cached')->default(false);
            $table->string('human_override')->nullable();
            $table->text('human_override_note')->nullable();
            $table->foreignId('human_override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('cost_micro_usd')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['simulation_id', 'frozen_case_id']);
            $table->index(['config_hash', 'case_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_simulation_results');
        Schema::table('agent_proposals', function (Blueprint $table) {
            $table->dropForeign(['gate_simulation_id']);
        });
        Schema::dropIfExists('agent_simulations');
        Schema::dropIfExists('agent_test_cases');
        Schema::dropIfExists('agent_proposal_changes');
        Schema::dropIfExists('agent_proposals');
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropForeign(['frozen_case_id']);
        });
        Schema::dropIfExists('agent_frozen_cases');
    }
};
