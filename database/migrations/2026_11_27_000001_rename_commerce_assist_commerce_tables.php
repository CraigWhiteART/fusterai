<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Shopify settings and the Shopify/tracking snapshots now belong to
 * App\Domains\Commerce. Rename the tables in place so existing rows (and the
 * encrypted credentials) survive. Idempotent: safe to run on a fresh install
 * (the module migrations create the old names first) and to re-run.
 */
return new class extends Migration
{
    private const RENAMES = [
        'commerce_assist_settings' => 'commerce_settings',
        'commerce_assist_shopify_snapshots' => 'commerce_shopify_snapshots',
        'commerce_assist_tracking_snapshots' => 'commerce_tracking_snapshots',
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $from => $to) {
            if (Schema::hasTable($from) && ! Schema::hasTable($to)) {
                Schema::rename($from, $to);
            }
        }
    }

    public function down(): void
    {
        foreach (self::RENAMES as $from => $to) {
            if (Schema::hasTable($to) && ! Schema::hasTable($from)) {
                Schema::rename($to, $from);
            }
        }
    }
};
