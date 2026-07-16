<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        // An immutable record of who a specific Execution was intended to
        // reach, captured from audience membership at snapshot time — never
        // re-derived from live membership later, and never mutated once
        // written (see EmailAudienceService::snapshotRecipientsForExecution()).
        // Deliberately minimal: `status`/`skipped_reason`/`provider_message_id`
        // exist so a later slice can record real per-recipient delivery
        // outcomes without a schema change, but nothing in this slice writes
        // anything other than `pending`/`skipped` — this is not a full event
        // ledger.
        Schema::create('email_recipient_snapshots', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->index();
            $table->char('campaign_id', 26)->index();
            $table->char('execution_id', 26)->index();
            // Nullable and informational only — traces back to the source
            // contact for audit purposes, but the snapshot's own `email`/
            // `display_name` columns (not this FK) are the source of truth
            // for "which address was used at send time," so the snapshot
            // stays meaningful even if the contact is later archived.
            $table->char('email_contact_id', 26)->nullable()->index();
            $table->string('email');
            $table->string('display_name')->nullable();
            $table->enum('status', ['pending', 'sent', 'skipped', 'failed'])->default('pending');
            $table->string('skipped_reason')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->timestamps();

            // One row per unique email per execution — the DB-level
            // backstop for "duplicate normalized addresses are not
            // duplicated in the snapshot."
            $table->unique(['execution_id', 'email']);
            $table->index(['company_id', 'campaign_id']);
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('campaign_id')->references('id')->on('campaigns')->cascadeOnDelete();
            $table->foreign('execution_id')->references('id')->on('executions')->cascadeOnDelete();
            $table->foreign('email_contact_id')->references('id')->on('email_contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_recipient_snapshots');
    }
};
