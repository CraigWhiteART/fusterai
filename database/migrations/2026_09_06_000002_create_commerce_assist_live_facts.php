<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

    public function down(): void
    {
        Schema::dropIfExists('commerce_assist_live_facts');
    }
};
