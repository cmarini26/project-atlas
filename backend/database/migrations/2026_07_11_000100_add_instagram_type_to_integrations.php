<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the 'instagram' integration type — Milestone 12 Phase 1 (Instagram
 * Observation). Mirrors 2026_07_05_000100_add_retrying_status_to_observations.php:
 * the base create_integrations_table migration was updated to include
 * 'instagram' for fresh databases (including the sqlite test database).
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

        DB::statement('ALTER TABLE integrations DROP CONSTRAINT IF EXISTS integrations_type_check');
        DB::statement("ALTER TABLE integrations ADD CONSTRAINT integrations_type_check CHECK (type::text = ANY (ARRAY['website_crawl'::text, 'rss_feed'::text, 'api'::text, 'csv_upload'::text, 'manual'::text, 'instagram'::text]))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("DELETE FROM integrations WHERE type = 'instagram'");
        DB::statement('ALTER TABLE integrations DROP CONSTRAINT IF EXISTS integrations_type_check');
        DB::statement("ALTER TABLE integrations ADD CONSTRAINT integrations_type_check CHECK (type::text = ANY (ARRAY['website_crawl'::text, 'rss_feed'::text, 'api'::text, 'csv_upload'::text, 'manual'::text]))");
    }
};
