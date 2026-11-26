<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partial approval (docs/agent-proposals.md, "Excluding changes"): what a
 * reviewer left out of a proposal before approving it. Each entry keeps the
 * excluded ops so they can be restored, and is copied into `applied`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_proposals', function (Blueprint $table) {
            $table->jsonb('excluded')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agent_proposals', function (Blueprint $table) {
            $table->dropColumn('excluded');
        });
    }
};
