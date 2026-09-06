<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

    public function down(): void
    {
        Schema::dropIfExists('commerce_assist_history_learnings');
        Schema::dropIfExists('commerce_assist_history_scans');
    }
};
