<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // imap_config / smtp_config are encrypted by the Mailbox model
        // (Crypt::encryptString) so they must be stored as text, not jsonb.
        // Postgres rejects the ciphertext with SQLSTATE 22P02.
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE mailboxes ALTER COLUMN imap_config TYPE text USING imap_config::text');
        DB::statement('ALTER TABLE mailboxes ALTER COLUMN smtp_config TYPE text USING smtp_config::text');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE mailboxes ALTER COLUMN imap_config TYPE jsonb USING imap_config::jsonb');
        DB::statement('ALTER TABLE mailboxes ALTER COLUMN smtp_config TYPE jsonb USING smtp_config::jsonb');
    }
};
