<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 15 Phase 1 — Business Discovery Onboarding. One row per
 * company, narrowly scoped to onboarding-collected data that doesn't
 * belong on any existing entity: business goals and marketing
 * preferences. Deliberately does NOT touch Observation/Fact/Knowledge —
 * this phase persists onboarding data only; teaching that data to the
 * Business Brain is future-phase work (see
 * docs/plans/Milestone-15-Business-Discovery-Onboarding-Plan.md).
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_profiles', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->unique();
            $table->json('business_goals');
            $table->enum('marketing_frequency', ['daily', 'weekly', 'monthly', 'promotions_only', 'rarely'])->nullable();
            $table->enum('marketing_owner', ['me', 'team', 'agency', 'freelancer', 'nobody'])->nullable();
            $table->boolean('is_seasonal')->nullable();
            $table->json('seasonal_months')->nullable();
            $table->enum('primary_cta', ['call', 'fill_out_form', 'book', 'visit_location', 'buy_online', 'attend_event', 'request_quote'])->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_profiles');
    }
};
