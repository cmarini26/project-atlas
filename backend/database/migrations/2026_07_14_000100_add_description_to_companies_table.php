<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 15 Phase 1 — Business Discovery Onboarding. A free-text
 * business description, collected in the redesigned onboarding's Company
 * step — a real, generically-useful attribute of the business, not an
 * onboarding-specific concern, so it lives on Company itself rather than
 * the narrowly-scoped onboarding_profiles table.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('industry');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('description');
        });
    }
};
