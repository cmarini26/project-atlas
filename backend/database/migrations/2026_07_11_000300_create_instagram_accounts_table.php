<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 12 Phase 1 — Instagram Observation (Beta). One row per company's
 * connected Instagram account (single-account-per-company for the beta —
 * multiple accounts are explicitly out of scope). Holds the latest known
 * profile snapshot; the snapshot itself is also recorded as an Observation
 * and processed into Facts through the existing Observe -> Understand
 * pipeline. This table exists for fast, typed access to "what does Atlas
 * currently know about this company's Instagram account" without querying
 * Facts — the Facts remain the source of truth for the Business Brain.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_accounts', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->index();
            $table->char('integration_id', 26)->unique();
            $table->string('account_id');
            $table->string('username');
            $table->string('display_name')->nullable();
            $table->text('profile_picture_url')->nullable();
            $table->text('bio')->nullable();
            $table->string('website')->nullable();
            $table->unsignedInteger('follower_count')->nullable();
            $table->unsignedInteger('following_count')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('integration_id')->references('id')->on('integrations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_accounts');
    }
};
