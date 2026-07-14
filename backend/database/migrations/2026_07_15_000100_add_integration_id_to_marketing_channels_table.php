<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_channels', function (Blueprint $table): void {
            $table->char('integration_id', 26)->nullable()->after('channel_id')->index();
        });

        // SQLite has no native ALTER TABLE ADD CONSTRAINT — Laravel's SQLite
        // grammar works around this by rebuilding the whole table from its
        // Doctrine-introspected column list, which does not preserve the
        // raw CHECK constraints backing enum() columns elsewhere on this
        // table (see MarketingChannelMigrationTest). Skipping the FK there
        // avoids that destructive rebuild; Postgres (staging/production,
        // and this project's local dev DB) gets the real, enforced FK.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('marketing_channels', function (Blueprint $table): void {
                $table->foreign('integration_id')->references('id')->on('integrations')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('marketing_channels', function (Blueprint $table): void {
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->dropForeign(['integration_id']);
            }
            $table->dropColumn('integration_id');
        });
    }
};
