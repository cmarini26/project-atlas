<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 13 Phase 1 — Marketing Health MVP. One row per company per
 * dimension, current-value-with-supersession (is_current / superseded_by_id),
 * mirroring the Fact table's own pattern exactly.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_health_scores', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->index();
            $table->enum('dimension', [
                'website',
                'social_activity',
                'campaign_consistency',
                'brand_consistency',
                'content_diversity',
                'cta_strength',
                'presence_coverage',
            ]);
            $table->unsignedTinyInteger('score');
            $table->unsignedTinyInteger('confidence');
            $table->json('evidence');
            $table->timestamp('computed_at');
            $table->boolean('is_current')->default(true);
            $table->char('superseded_by_id', 26)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'dimension', 'is_current']);
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            // No FK on superseded_by_id (self-referencing) — Fact.superseded_by_id
            // uses the same plain-column, application-level-only convention.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_health_scores');
    }
};
