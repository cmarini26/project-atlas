<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('email_contacts', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->index();
            $table->string('email');
            // Lowercased + trimmed form of `email` — the actual identity key
            // for deduplication. See EmailContact::normalizeEmail().
            $table->string('normalized_email');
            $table->string('display_name')->nullable();
            $table->enum('source', ['manual', 'import', 'api'])->default('manual');
            $table->enum('consent_status', ['unknown', 'confirmed', 'declined'])->default('unknown');
            // 'archived' is a soft, reversible disable — mirrors
            // MarketingChannel's status-flip convention (never a hard
            // delete), not Eloquent SoftDeletes. Re-adding an archived
            // contact's email reactivates this same row (see
            // EmailAudienceService::addOrReactivateContact()) rather than
            // creating a second row, which is what the unique constraint
            // below enforces regardless of status.
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->timestamps();

            // One row per (company, normalized email) — including archived
            // rows. This is a deliberate choice: it means "recreating" a
            // contact for an email that was previously archived reactivates
            // the same row instead of ever having two rows race for the
            // same identity, and it works identically on every DB driver
            // (a conditional/partial unique index would be Postgres-only).
            $table->unique(['company_id', 'normalized_email']);
            $table->index(['company_id', 'status']);
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_contacts');
    }
};
