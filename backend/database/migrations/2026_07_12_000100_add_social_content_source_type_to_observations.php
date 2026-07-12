<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the 'social_content' observation source type — Milestone 12 Phase 2
 * (Instagram Content Intelligence). Mirrors
 * 2026_07_11_000200_add_social_source_type_to_observations.php exactly: the
 * base create_observations_table migration was updated to include
 * 'social_content' for fresh databases (including the sqlite test
 * database). This migration only rewrites the enum check constraint on
 * existing PostgreSQL databases.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE observations DROP CONSTRAINT IF EXISTS observations_source_type_check');
        DB::statement("ALTER TABLE observations ADD CONSTRAINT observations_source_type_check CHECK (source_type::text = ANY (ARRAY['crawl'::text, 'feed'::text, 'api'::text, 'manual'::text, 'internal'::text, 'social'::text, 'social_content'::text]))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("DELETE FROM observations WHERE source_type = 'social_content'");
        DB::statement('ALTER TABLE observations DROP CONSTRAINT IF EXISTS observations_source_type_check');
        DB::statement("ALTER TABLE observations ADD CONSTRAINT observations_source_type_check CHECK (source_type::text = ANY (ARRAY['crawl'::text, 'feed'::text, 'api'::text, 'manual'::text, 'internal'::text, 'social'::text]))");
    }
};
