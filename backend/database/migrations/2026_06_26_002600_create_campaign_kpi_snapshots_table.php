<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_kpi_snapshots', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->index();
            $table->char('campaign_id', 26)->index();
            $table->string('snapshot_type')->default('interim'); // interim | final
            $table->timestamp('snapshotted_at');
            $table->json('channels_included');
            $table->json('expected_impact')->nullable();
            $table->json('actual_kpis');
            $table->string('performance_rating'); // exceeded | met | below | insufficient_data
            $table->timestamp('created_at')->useCurrent();

            $table->index(['campaign_id', 'snapshot_type']);
            $table->index(['company_id', 'snapshotted_at']);

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('campaign_id')->references('id')->on('campaigns')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_kpi_snapshots');
    }
};
