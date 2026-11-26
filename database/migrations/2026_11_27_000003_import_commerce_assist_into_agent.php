<?php

use App\Domains\Agent\Services\LegacyImporter;
use Illuminate\Database\Migrations\Migration;

/**
 * Data migration: seed agent settings, a starter voice and one playbook per
 * Commerce Assist intent for every workspace, and copy approved responses,
 * live facts and chunked KB documents into the agent tables. Idempotent (see
 * LegacyImporter); re-run any time with `php artisan agent:import-legacy`.
 * No embeddings are computed here (no network calls during migrate): chunks
 * are keyword-searchable at once and embedded when re-indexed.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(LegacyImporter::class)->run(embed: false);
    }

    public function down(): void
    {
        // Imported rows live in agent_* tables, dropped by the previous migration's down().
    }
};
