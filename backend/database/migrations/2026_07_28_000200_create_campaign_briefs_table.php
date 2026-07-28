<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_briefs', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->index();
            $table->string('title');
            $table->string('goal');
            $table->text('objective');
            $table->text('audience')->nullable();
            $table->text('guidance')->nullable();
            $table->string('campaign_type');
            $table->json('channel_ids');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        Schema::create('campaign_brief_source_asset', function (Blueprint $table): void {
            $table->char('campaign_brief_id', 26);
            $table->char('source_asset_id', 26);
            $table->primary(['campaign_brief_id', 'source_asset_id']);
            $table->foreign('campaign_brief_id')->references('id')->on('campaign_briefs')->cascadeOnDelete();
            $table->foreign('source_asset_id')->references('id')->on('source_assets')->cascadeOnDelete();
        });

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->char('campaign_brief_id', 26)->nullable()->after('decision_id')->index();
            $table->foreign('campaign_brief_id')->references('id')->on('campaign_briefs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropForeign(['campaign_brief_id']);
            $table->dropColumn('campaign_brief_id');
        });

        Schema::dropIfExists('campaign_brief_source_asset');
        Schema::dropIfExists('campaign_briefs');
    }
};
