<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QA reviews of closed conversations (docs/agent-qa.md).
 *
 *  - agent_qa_reviews: one review of one staff reply (AI, AI edited by a
 *    human, or fully human), unique per (conversation, reviewed thread,
 *    reviewer prompt version). Cheap first pass + full review for flagged /
 *    sampled ones. Skipped closes are stored too (status skipped + reason).
 *  - agent_qa_gaps: gaps grouped by (failure class, playbook, similar text).
 *    missing_instruction / missing_knowledge groups become ONE proposal at a
 *    threshold; missing_tool groups are the capability-requests list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_qa_gaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            // missing_instruction | missing_knowledge | missing_tool
            $table->string('failure_class', 32);
            $table->foreignId('playbook_id')->nullable()->constrained('agent_playbooks')->nullOnDelete();
            // Reviewer's snake_case topic of the first gap, and the representative text.
            $table->string('topic', 120)->nullable();
            $table->text('label');
            // Normalised term set used for similarity (works with embeddings off).
            $table->jsonb('terms');
            $table->unsignedInteger('review_count')->default(0);
            $table->jsonb('conversation_ids')->nullable();
            // open | drafting | proposed | failed | closed
            $table->string('status', 16)->default('open');
            $table->foreignId('proposal_id')->nullable()->constrained('agent_proposals')->nullOnDelete();
            $table->unsignedBigInteger('cost_micro_usd')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('proposed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'failure_class', 'status']);
        });

        Schema::create('agent_qa_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            // The staff reply being judged; null only for skipped closes with no reply.
            $table->foreignId('reviewed_thread_id')->nullable()->constrained('threads')->cascadeOnDelete();
            // The customer message that reply answered.
            $table->foreignId('customer_thread_id')->nullable()->constrained('threads')->nullOnDelete();
            // ai | human | ai_edited
            $table->string('author_type', 16)->nullable();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            $table->foreignId('playbook_id')->nullable()->constrained('agent_playbooks')->nullOnDelete();
            $table->string('playbook_slug')->nullable();
            // pending | triaged (first pass only) | reviewed (full review) | skipped | failed
            $table->string('status', 16)->default('pending');
            $table->string('skip_reason', 48)->nullable();
            // closed | backfill | unhappy_return | manual
            $table->string('trigger', 24)->default('closed');
            // Full review because of a random sample (not because the first pass flagged it).
            $table->boolean('sampled')->default(false);
            // Full review forced (unhappy return, manual).
            $table->boolean('forced')->default(false);
            // First pass: {score, flagged, reason, playbook, model, cost_micro_usd}
            $table->jsonb('triage')->nullable();
            $table->unsignedTinyInteger('triage_score')->nullable();
            // Full review: {resolved, policy_consistency, factual_grounding, tone_match, clarity} (1-5; grounding null when not judgeable)
            $table->jsonb('scores')->nullable();
            $table->decimal('overall', 4, 2)->nullable();
            // none | missing_instruction | missing_knowledge | missing_tool | needed_human | agent_error | human_error
            $table->string('failure_class', 32)->nullable();
            $table->text('summary')->nullable();
            $table->text('gap')->nullable();
            $table->string('gap_topic', 120)->nullable();
            $table->text('suggested_fix')->nullable();
            $table->foreignId('gap_id')->nullable()->constrained('agent_qa_gaps')->nullOnDelete();
            $table->foreignId('proposal_id')->nullable()->constrained('agent_proposals')->nullOnDelete();
            // run_ledger | recorded_snapshot | none
            $table->string('evidence_source', 24)->nullable();
            $table->string('reviewer_model')->nullable();
            $table->string('reviewer_prompt_version', 32);
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedBigInteger('triage_cost_micro_usd')->default(0);
            $table->unsignedBigInteger('review_cost_micro_usd')->default(0);
            $table->unsignedBigInteger('cost_micro_usd')->default(0);
            // Human disagreement: dismissed | incorrect (excluded from aggregates and gap counts).
            $table->string('human_verdict', 16)->nullable();
            $table->text('human_note')->nullable();
            $table->foreignId('human_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('human_at')->nullable();
            $table->text('error')->nullable();
            // When the reviewed reply was sent (report buckets use it).
            $table->timestamp('replied_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'reviewed_thread_id', 'reviewer_prompt_version'], 'agent_qa_reviews_unique_review');
            $table->index(['workspace_id', 'status', 'created_at']);
            $table->index(['workspace_id', 'failure_class']);
            $table->index(['workspace_id', 'author_type', 'replied_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_qa_reviews');
        Schema::dropIfExists('agent_qa_gaps');
    }
};
