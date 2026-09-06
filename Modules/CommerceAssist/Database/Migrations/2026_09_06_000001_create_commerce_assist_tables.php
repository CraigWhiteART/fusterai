<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_assist_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete()->unique();
            $table->string('shopify_shop_domain')->nullable();
            $table->text('shopify_access_token')->nullable();
            $table->string('shopify_api_version')->default('2025-01');
            $table->unsignedInteger('tracking_stale_days')->default(14);
            $table->unsignedInteger('example_edit_threshold')->default(25);
            $table->jsonb('preorder_tags')->nullable();
            $table->boolean('draft_only')->default(true);
            $table->timestamps();
        });

        Schema::create('commerce_assist_shopify_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('customer_email')->nullable();
            $table->string('shopify_customer_id')->nullable();
            $table->string('shopify_order_id')->nullable();
            $table->string('order_number')->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'fetched_at']);
        });

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

        Schema::create('commerce_assist_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_thread_id')->nullable()->constrained('threads')->nullOnDelete();
            $table->foreignId('reply_thread_id')->nullable()->constrained('threads')->nullOnDelete();
            $table->foreignId('ai_suggestion_id')->nullable()->constrained('ai_suggestions')->nullOnDelete();
            $table->unsignedBigInteger('shopify_snapshot_id')->nullable();
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
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source')->default('manual');
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'intent']);
        });

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

        if (config('database.default') === 'pgsql') {
            Schema::ensureVectorExtensionExists();

            Schema::table('commerce_assist_approved_responses', function (Blueprint $table) {
                $table->vector('embedding', dimensions: 1536)->nullable()->after('tags');
            });
        } else {
            Schema::table('commerce_assist_approved_responses', function (Blueprint $table) {
                $table->json('embedding')->nullable()->after('tags');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_assist_replay_runs');
        Schema::dropIfExists('commerce_assist_approved_responses');
        Schema::dropIfExists('commerce_assist_generations');
        Schema::dropIfExists('commerce_assist_intents');
        Schema::dropIfExists('commerce_assist_shopify_snapshots');
        Schema::dropIfExists('commerce_assist_settings');
    }
};
