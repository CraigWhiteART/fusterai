<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * History mining (docs/agent-mining.md).
 *
 *  - agent_mining_runs: one run (options, stage cursor, counters, token and
 *    cost totals per stage, status, last error).
 *  - agent_mining_conversations: the run's selected conversations and their
 *    extraction checkpoint.
 *  - agent_mining_extractions: per-conversation extraction, unique by
 *    (conversation, content hash, extractor version), shared by every run so
 *    a re-run skips finished work and a new extractor version re-extracts.
 *  - agent_mining_situations: the run's clusters, coverage and synthesis.
 *  - agent_mining_cache: model results of the cluster / synthesis / voice
 *    calls keyed by input hash, so a re-run on unchanged data costs nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_mining_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // {since, until, max_conversations, budget_usd, sample_strategy}
            $table->jsonb('options');
            // queued | running | paused | completed | failed | cancelled
            $table->string('status', 16)->default('queued');
            // select | extract | cluster | synthesise | voice | emit | done
            $table->string('stage', 16)->default('select');
            $table->jsonb('counters')->nullable();
            // {stage: {calls, cache_hits, tokens_in, tokens_out, cost_micro_usd}}
            $table->jsonb('usage')->nullable();
            // Run-level results: merge mapping, voice synthesis, inconsistencies, emitted proposals.
            $table->jsonb('results')->nullable();
            $table->unsignedBigInteger('tokens_in')->default(0);
            $table->unsignedBigInteger('tokens_out')->default(0);
            $table->unsignedBigInteger('cost_micro_usd')->default(0);
            $table->unsignedBigInteger('budget_micro_usd');
            // Why the run is paused: user | budget.
            $table->string('pause_reason', 16)->nullable();
            $table->text('message')->nullable();
            $table->string('job_token', 64)->nullable();
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('consecutive_errors')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['status', 'heartbeat_at']);
        });

        // One unfinished mining run per workspace.
        DB::statement(
            'CREATE UNIQUE INDEX agent_mining_runs_one_active_per_workspace '
            ."ON agent_mining_runs (workspace_id) WHERE status IN ('queued', 'running', 'paused')"
        );

        Schema::create('agent_mining_extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('content_hash', 64);
            $table->string('extractor_version', 32);
            // ok | not_support | macro (copied from macro_of_id without a model call)
            $table->string('status', 16);
            $table->boolean('is_support')->default(true);
            $table->string('situation', 64)->nullable();
            $table->string('language', 8)->nullable();
            $table->string('outcome', 32)->nullable();
            // Scrubbed structured extraction (no raw mail text).
            $table->jsonb('data');
            // Normalised hash of the single human reply (macro detection).
            $table->string('macro_hash', 64)->nullable();
            $table->foreignId('macro_of_id')->nullable()->constrained('agent_mining_extractions')->nullOnDelete();
            // Staff identity keys of the human replies (u:{user_id} or e:{sha1(email)}).
            $table->jsonb('staff_keys')->nullable();
            $table->timestamp('first_at')->nullable();
            $table->timestamp('last_at')->nullable();
            $table->timestamp('last_staff_at')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedBigInteger('cost_micro_usd')->default(0);
            $table->unsignedBigInteger('run_id')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'content_hash', 'extractor_version'], 'agent_mining_extractions_key');
            $table->index(['workspace_id', 'extractor_version', 'macro_hash']);
        });

        Schema::create('agent_mining_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('agent_mining_runs')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('bucket', 7);
            $table->timestamp('last_at')->nullable();
            // pending | done | failed
            $table->string('status', 16)->default('pending');
            // new | reused | macro | not_support (how the extraction was obtained)
            $table->string('outcome', 16)->nullable();
            $table->foreignId('extraction_id')->nullable()->constrained('agent_mining_extractions')->nullOnDelete();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'conversation_id']);
            $table->index(['run_id', 'status', 'last_at']);
        });

        Schema::create('agent_mining_situations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('agent_mining_runs')->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('playbook_id')->nullable()->constrained('agent_playbooks')->nullOnDelete();
            // Raw labels merged into this situation, with counts.
            $table->jsonb('labels');
            $table->jsonb('extraction_ids');
            $table->unsignedInteger('conversation_count')->default(0);
            // pending | synthesised | failed | too_small | long_tail
            $table->string('status', 16)->default('pending');
            // Scrubbed synthesis output (playbook changes, knowledge, inconsistencies, test cases).
            $table->jsonb('synthesis')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'key']);
        });

        Schema::create('agent_mining_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            // merge | synthesis | voice
            $table->string('kind', 16);
            $table->string('input_hash', 64);
            $table->jsonb('output');
            $table->string('model')->nullable();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedBigInteger('cost_micro_usd')->default(0);
            $table->timestamps();

            $table->unique(['workspace_id', 'kind', 'input_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_mining_cache');
        Schema::dropIfExists('agent_mining_situations');
        Schema::dropIfExists('agent_mining_conversations');
        Schema::dropIfExists('agent_mining_extractions');
        Schema::dropIfExists('agent_mining_runs');
    }
};
