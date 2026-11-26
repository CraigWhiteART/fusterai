<?php

use App\Domains\Agent\Services\LegacyImporter;
use App\Domains\Agent\Services\LegacyTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Removes what is left of Modules/CommerceAssist in the database (plan
 * Phase 11): intents, generations, approved responses, replay runs, live
 * facts and the history-learn tables, plus its `modules` row. The settings
 * and Shopify / tracking snapshot tables were renamed to commerce_* earlier
 * (2026_11_27_000001) and stay.
 *
 * Guarded: the import (LegacyImporter, idempotent) is run once more, then
 * the tables are dropped ONLY if every approved response, live fact and
 * per-workspace intent set has its agent_* copy. Otherwise nothing is
 * dropped, a CRITICAL log line lists what is missing per workspace, and the
 * migration still completes (the app does not read these tables any more).
 * Retry after fixing: `php artisan agent:import-legacy --drop-old-tables`.
 *
 * down() is schema-only: it recreates the seven tables EMPTY (same columns
 * as the original module migrations) so the earlier migrations can roll
 * back; the dropped rows are not restored (their content lives on in
 * agent_examples / agent_knowledge_items / agent_playbooks). Irreversible
 * for data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (LegacyTables::dropped()) {
            return;
        }

        app(LegacyImporter::class)->run(embed: false);
        LegacyTables::dropIfImported();
    }

    public function down(): void
    {
        if (! Schema::hasTable('commerce_assist_intents')) {
            Schema::create('commerce_assist_intents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->string('slug');
                $table->string('parent_slug')->nullable();
                $table->string('name');
                $table->text('rules');
                $table->jsonb('data_requirements')->nullable();
                $table->boolean('always_human')->default(false);
                $table->boolean('auto_send_allowed')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->unique(['workspace_id', 'slug']);
            });
        }

        if (! Schema::hasTable('commerce_assist_generations')) {
            Schema::create('commerce_assist_generations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_thread_id')->nullable()->constrained('threads')->nullOnDelete();
                $table->foreignId('reply_thread_id')->nullable()->constrained('threads')->nullOnDelete();
                $table->foreignId('ai_suggestion_id')->nullable()->constrained('ai_suggestions')->nullOnDelete();
                $table->unsignedBigInteger('shopify_snapshot_id')->nullable();
                // Same constraint name as the original (2026_09_06_000002), so that
                // migration's down() can drop it; the table is renamed by then.
                $table->foreignId('tracking_snapshot_id')->nullable()
                    ->constrained(Schema::hasTable('commerce_tracking_snapshots') ? 'commerce_tracking_snapshots' : 'commerce_assist_tracking_snapshots', indexName: 'commerce_assist_generations_tracking_snapshot_id_foreign')
                    ->nullOnDelete();
                $table->text('ai_draft');
                $table->text('final_response')->nullable();
                $table->unsignedTinyInteger('percent_changed')->nullable();
                $table->boolean('sent_unchanged')->nullable();
                $table->string('intent')->nullable();
                $table->string('subtype')->nullable();
                $table->string('sentiment')->nullable();
                $table->string('model')->nullable();
                $table->jsonb('examples_retrieved')->nullable();
                $table->jsonb('kb_retrieved')->nullable();
                $table->jsonb('sources')->nullable();
                $table->jsonb('unsupported_claims')->nullable();
                $table->boolean('validator_passed')->default(true);
                $table->decimal('confidence', 4, 3)->nullable();
                $table->boolean('safe_to_send')->default(false);
                $table->boolean('requires_human')->default(true);
                $table->string('status')->default('draft');
                $table->boolean('nominated_as_example')->default(false);
                $table->boolean('added_as_example')->default(false);
                $table->timestamps();
                $table->index(['conversation_id', 'created_at']);
                $table->index(['workspace_id', 'intent']);
            });
        }

        if (! Schema::hasTable('commerce_assist_approved_responses')) {
            Schema::create('commerce_assist_approved_responses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('thread_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('generation_id')->nullable()->constrained('commerce_assist_generations')->nullOnDelete();
                $table->text('customer_message');
                $table->text('final_reply');
                $table->string('intent')->nullable();
                $table->string('subtype')->nullable();
                $table->string('sentiment')->nullable();
                $table->string('country')->nullable();
                $table->jsonb('tags')->nullable();
                if (config('database.default') === 'pgsql') {
                    $table->vector('embedding', dimensions: 1536)->nullable();
                } else {
                    $table->json('embedding')->nullable();
                }
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('source')->default('manual');
                $table->timestamp('indexed_at')->nullable();
                $table->timestamps();
                $table->index(['workspace_id', 'intent']);
            });
        }

        if (! Schema::hasTable('commerce_assist_replay_runs')) {
            Schema::create('commerce_assist_replay_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
                $table->foreignId('generation_id')->nullable()->constrained('commerce_assist_generations')->nullOnDelete();
                $table->text('original_reply')->nullable();
                $table->text('current_ai_reply')->nullable();
                $table->boolean('passed')->nullable();
                $table->jsonb('sources')->nullable();
                $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('commerce_assist_live_facts')) {
            Schema::create('commerce_assist_live_facts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('source_conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('title')->nullable();
                $table->text('body');
                $table->jsonb('intent_slugs')->nullable();
                $table->jsonb('product_keywords')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('retired_at')->nullable();
                $table->timestamp('refresh_started_at')->nullable();
                $table->timestamp('refresh_completed_at')->nullable();
                $table->jsonb('refreshed_conversation_ids')->nullable();
                $table->timestamps();
                $table->index(['workspace_id', 'retired_at']);
            });
        }

        if (! Schema::hasTable('commerce_assist_history_scans')) {
            Schema::create('commerce_assist_history_scans', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status')->default('queued');
                $table->unsignedInteger('total_count')->default(0);
                $table->unsignedInteger('scanned_count')->default(0);
                $table->unsignedInteger('proposed_count')->default(0);
                $table->unsignedInteger('skipped_count')->default(0);
                $table->text('error')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->index(['workspace_id', 'status']);
            });
        }

        if (! Schema::hasTable('commerce_assist_history_learnings')) {
            Schema::create('commerce_assist_history_learnings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('scan_id')->nullable()->constrained('commerce_assist_history_scans')->nullOnDelete();
                $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('thread_id')->nullable()->constrained()->nullOnDelete();
                $table->string('kind');
                $table->string('status')->default('pending');
                $table->string('title')->nullable();
                $table->text('body')->nullable();
                $table->text('customer_message')->nullable();
                $table->text('final_reply')->nullable();
                $table->text('source_customer_message')->nullable();
                $table->text('source_final_reply')->nullable();
                $table->string('subject')->nullable();
                $table->string('intent')->nullable();
                $table->string('subtype')->nullable();
                $table->jsonb('product_keywords')->nullable();
                $table->boolean('time_sensitive')->default(false);
                $table->timestamp('expires_at')->nullable();
                $table->text('rationale')->nullable();
                $table->string('accepted_type')->nullable();
                $table->unsignedBigInteger('accepted_id')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
                $table->index(['workspace_id', 'status']);
                $table->index(['workspace_id', 'thread_id']);
                $table->index(['workspace_id', 'kind', 'status']);
            });
        }
    }
};
