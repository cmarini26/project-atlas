<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the 'retrying' observation status — used when the AI provider is
 * temporarily overloaded and processing will be retried, so the onboarding
 * UI can show "waiting for the AI provider" instead of a permanent failure.
 *
 * The base create_observations_table migration was updated to include
 * 'retrying' for fresh databases (including the sqlite test database).
 * This migration only rewrites the enum check constraint on existing
 * PostgreSQL databases.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE observations DROP CONSTRAINT IF EXISTS observations_status_check');
        DB::statement("ALTER TABLE observations ADD CONSTRAINT observations_status_check CHECK (status::text = ANY (ARRAY['pending'::text, 'processing'::text, 'processed'::text, 'failed'::text, 'retrying'::text]))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("UPDATE observations SET status = 'failed' WHERE status = 'retrying'");
        DB::statement('ALTER TABLE observations DROP CONSTRAINT IF EXISTS observations_status_check');
        DB::statement("ALTER TABLE observations ADD CONSTRAINT observations_status_check CHECK (status::text = ANY (ARRAY['pending'::text, 'processing'::text, 'processed'::text, 'failed'::text]))");
    }
};
