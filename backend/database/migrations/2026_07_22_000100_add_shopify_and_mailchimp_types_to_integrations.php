<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE integrations DROP CONSTRAINT IF EXISTS integrations_type_check');
        DB::statement("ALTER TABLE integrations ADD CONSTRAINT integrations_type_check CHECK (type::text = ANY (ARRAY['website_crawl'::text, 'rss_feed'::text, 'api'::text, 'csv_upload'::text, 'manual'::text, 'instagram'::text, 'shopify'::text, 'mailchimp'::text]))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("DELETE FROM integrations WHERE type IN ('shopify', 'mailchimp')");
        DB::statement('ALTER TABLE integrations DROP CONSTRAINT IF EXISTS integrations_type_check');
        DB::statement("ALTER TABLE integrations ADD CONSTRAINT integrations_type_check CHECK (type::text = ANY (ARRAY['website_crawl'::text, 'rss_feed'::text, 'api'::text, 'csv_upload'::text, 'manual'::text, 'instagram'::text]))");
    }
};
