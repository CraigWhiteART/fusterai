<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_assist_settings', function (Blueprint $table) {
            $table->string('shopify_client_id')->nullable();
            $table->text('shopify_client_secret')->nullable();
            $table->timestamp('shopify_access_token_expires_at')->nullable();
            $table->text('shopify_granted_scopes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('commerce_assist_settings', function (Blueprint $table) {
            $table->dropColumn([
                'shopify_client_id',
                'shopify_client_secret',
                'shopify_access_token_expires_at',
                'shopify_granted_scopes',
            ]);
        });
    }
};
