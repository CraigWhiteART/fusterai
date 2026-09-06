<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_assist_settings', function (Blueprint $table) {
            $table->string('tracking_provider')->default('none');
            $table->text('tracking_api_key')->nullable();
            // Track123 keys lookups by the myshopify subdomain; blank falls back
            // to the subdomain of the configured Shopify shop domain.
            $table->string('tracking_store_uuid')->nullable();
        });

        Schema::create('commerce_assist_tracking_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('lookup_key')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('carrier_code')->nullable();
            $table->string('carrier_name')->nullable();
            $table->string('last_mile_carrier')->nullable();
            $table->string('last_mile_tracking_number')->nullable();
            $table->string('status')->default('unknown');
            $table->string('sub_status')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->unsignedInteger('days_since_last_scan')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'fetched_at']);
            $table->index(['workspace_id', 'tracking_number']);
        });

        Schema::table('commerce_assist_generations', function (Blueprint $table) {
            $table->foreignId('tracking_snapshot_id')->nullable()->after('shopify_snapshot_id')
                ->constrained('commerce_assist_tracking_snapshots')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('commerce_assist_generations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tracking_snapshot_id');
        });

        Schema::dropIfExists('commerce_assist_tracking_snapshots');

        Schema::table('commerce_assist_settings', function (Blueprint $table) {
            $table->dropColumn(['tracking_provider', 'tracking_api_key', 'tracking_store_uuid']);
        });
    }
};
