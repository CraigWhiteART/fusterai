<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // config is encrypted by the Channel model (Crypt::encryptString) so it
        // must be stored as text, not jsonb.
        Schema::table('channels', function (Blueprint $table) {
            $table->text('config')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Postgres cannot cast text to jsonb implicitly. Encrypted values are not
        // JSON, so rolling back with rows present fails loudly (as it should).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE channels ALTER COLUMN config TYPE jsonb USING config::jsonb');

            return;
        }

        Schema::table('channels', function (Blueprint $table) {
            $table->jsonb('config')->nullable()->change();
        });
    }
};
