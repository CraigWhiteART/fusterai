<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agent core (Phase 3). Instruction artifacts (voice, playbooks) are
 * immutable version rows plus a live pointer; "restore" writes a new version
 * copied from an old one. Runs record everything needed to reproduce them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        if ($pgsql) {
            Schema::ensureVectorExtensionExists();
        }

        Schema::create('agent_voice_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('personality');
            // Each list is [{id, text}] with ids stable across versions.
            $table->jsonb('do_phrases')->nullable();
            $table->jsonb('dont_phrases')->nullable();
            $table->jsonb('greetings')->nullable();
            $table->jsonb('sign_offs')->nullable();
            $table->unsignedInteger('max_words')->nullable();
            $table->text('length_guidance')->nullable();
            $table->boolean('is_starter')->default(false);
            $table->text('change_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_from_id')->nullable()->constrained('agent_voice_versions')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'version']);
        });

        Schema::create('agent_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            // off | shadow | draft | live
            $table->string('mode')->default('off');
            $table->boolean('kill_switch')->default(false);
            $table->foreignId('live_voice_version_id')->nullable()->constrained('agent_voice_versions')->nullOnDelete();
            // Autonomy fields: stored now, read by the policy gate from Phase 8.
            // {auto_send_per_day, auto_send_per_customer_per_day, actions_per_day}
            $table->jsonb('caps')->nullable();
            $table->unsignedInteger('auto_send_delay_seconds')->default(300);
            // Extra link domains a draft may contain, on top of the store and carrier domains.
            $table->jsonb('link_allowlist')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_playbooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            // off | draft | auto. Auto is stored but not honoured until Phase 8.
            $table->string('mode')->default('draft');
            $table->unsignedTinyInteger('rollout_percent')->default(0);
            $table->boolean('always_human')->default(false);
            $table->integer('priority')->default(0);
            $table->unsignedBigInteger('live_version_id')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'slug']);
        });

        Schema::create('agent_playbook_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playbook_id')->constrained('agent_playbooks')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('when_text');
            // [{id, if, then}] with ids stable across versions.
            $table->jsonb('rules')->nullable();
            // [{id, text}]
            $table->jsonb('steps')->nullable();
            // Action keys the writer may propose under this playbook.
            $table->jsonb('allowed_actions')->nullable();
            // Evidence the playbook cannot answer without: subset of [order, tracking].
            $table->jsonb('required_evidence')->nullable();
            $table->text('escalation')->nullable();
            $table->text('change_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_from_id')->nullable()->constrained('agent_playbook_versions')->nullOnDelete();
            $table->timestamps();

            $table->unique(['playbook_id', 'version']);
        });

        Schema::table('agent_playbooks', function (Blueprint $table) {
            $table->foreign('live_version_id')->references('id')->on('agent_playbook_versions')->nullOnDelete();
        });

        Schema::create('agent_knowledge_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            // fact | policy | kb_chunk | snippet
            $table->string('kind');
            $table->string('title')->nullable();
            $table->text('body');
            // Required: {playbooks: [slug], products: [kw], countries: [code], global: bool}.
            // An item applies everywhere only when global is explicitly true.
            $table->jsonb('scope');
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            // active | needs_scope | draft | retired
            $table->string('status')->default('active');
            $table->foreignId('supersedes_id')->nullable()->constrained('agent_knowledge_items')->nullOnDelete();
            $table->foreignId('source_document_id')->nullable()->constrained('kb_documents')->cascadeOnDelete();
            $table->unsignedInteger('chunk_index')->nullable();
            // Provenance for migrated rows (e.g. live_fact + its old id) so re-runs are idempotent.
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->string('embedding_model')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->unique(['source_document_id', 'chunk_index']);
            $table->unique(['workspace_id', 'source_type', 'source_id']);
        });

        Schema::create('agent_examples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('thread_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('legacy_approved_response_id')->nullable()->unique();
            $table->text('customer_message');
            $table->text('reply');
            $table->string('playbook_slug')->nullable();
            $table->string('sentiment')->nullable();
            $table->string('country')->nullable();
            $table->jsonb('tags')->nullable();
            $table->string('source')->default('manual');
            // active | retired
            $table->string('status')->default('active');
            $table->string('embedding_model')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'playbook_slug']);
        });

        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            // live | shadow | preview | simulation | test
            $table->string('purpose');
            // Nullable: preview/simulation runs may have no live conversation.
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            // Phase 5 adds agent_frozen_cases; no FK until that table exists.
            $table->unsignedBigInteger('frozen_case_id')->nullable()->index();
            $table->foreignId('customer_thread_id')->nullable()->constrained('threads')->nullOnDelete();
            $table->string('idempotency_key')->unique();
            // running | completed | failed
            $table->string('status')->default('running');
            // Version ids, models, prompt template version, overlay flag, hash.
            $table->jsonb('config');
            $table->string('config_hash', 64)->index();
            $table->jsonb('triage')->nullable();
            $table->foreignId('playbook_id')->nullable()->constrained('agent_playbooks')->nullOnDelete();
            $table->foreignId('playbook_version_id')->nullable()->constrained('agent_playbook_versions')->nullOnDelete();
            $table->jsonb('evidence')->nullable();
            $table->text('draft')->nullable();
            $table->text('internal_note')->nullable();
            $table->jsonb('claims')->nullable();
            $table->jsonb('rules_used')->nullable();
            $table->jsonb('proposed_actions')->nullable();
            $table->jsonb('validator')->nullable();
            // draft | handover (auto_send from Phase 8)
            $table->string('decision')->nullable();
            $table->string('handover_reason')->nullable();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedInteger('tokens_cache_read')->default(0);
            $table->unsignedInteger('tokens_cache_write')->default(0);
            $table->unsignedInteger('tokens_reasoning')->default(0);
            $table->unsignedBigInteger('cost_micro_usd')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->jsonb('trace')->nullable();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('ai_suggestion_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['workspace_id', 'purpose', 'created_at']);
        });

        Schema::create('agent_feedback_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            // One event per sent thread: re-firing thread.created never double-counts.
            $table->foreignId('thread_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // sent_as_is | sent_edited | gmail_reply | rejected | comment
            $table->string('kind');
            $table->text('draft_before')->nullable();
            $table->text('final_text')->nullable();
            $table->unsignedTinyInteger('percent_changed')->nullable();
            $table->text('text')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'kind', 'created_at']);
        });

        Schema::table('ai_suggestions', function (Blueprint $table) {
            $table->foreignId('agent_run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->foreign('ai_suggestion_id')->references('id')->on('ai_suggestions')->nullOnDelete();
        });

        if ($pgsql) {
            $config = (string) config('ai.rag.fts_config', 'english');
            $config = preg_match('/^[a-z_]+$/', $config) === 1 ? $config : 'simple';

            Schema::table('agent_knowledge_items', function (Blueprint $table) {
                $table->vector('embedding', dimensions: 1536)->nullable();
            });
            Schema::table('agent_examples', function (Blueprint $table) {
                $table->vector('embedding', dimensions: 1536)->nullable();
            });

            DB::statement("ALTER TABLE agent_knowledge_items ADD COLUMN tsv tsvector GENERATED ALWAYS AS (to_tsvector('{$config}'::regconfig, coalesce(title, '') || ' ' || coalesce(body, ''))) STORED");
            DB::statement('CREATE INDEX agent_knowledge_items_tsv_index ON agent_knowledge_items USING GIN (tsv)');
            DB::statement("ALTER TABLE agent_examples ADD COLUMN tsv tsvector GENERATED ALWAYS AS (to_tsvector('{$config}'::regconfig, coalesce(customer_message, '') || ' ' || coalesce(reply, ''))) STORED");
            DB::statement('CREATE INDEX agent_examples_tsv_index ON agent_examples USING GIN (tsv)');
        } else {
            Schema::table('agent_knowledge_items', function (Blueprint $table) {
                $table->json('embedding')->nullable();
            });
            Schema::table('agent_examples', function (Blueprint $table) {
                $table->json('embedding')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropForeign(['ai_suggestion_id']);
        });
        Schema::table('ai_suggestions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agent_run_id');
        });
        Schema::dropIfExists('agent_feedback_events');
        Schema::dropIfExists('agent_runs');
        Schema::dropIfExists('agent_examples');
        Schema::dropIfExists('agent_knowledge_items');
        Schema::table('agent_playbooks', function (Blueprint $table) {
            $table->dropForeign(['live_version_id']);
        });
        Schema::dropIfExists('agent_playbook_versions');
        Schema::dropIfExists('agent_playbooks');
        Schema::dropIfExists('agent_settings');
        Schema::dropIfExists('agent_voice_versions');
    }
};
