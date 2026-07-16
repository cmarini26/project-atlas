<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        // Pure pivot — no company_id column. Cross-company association
        // (an audience from company A gaining a contact from company B) is
        // impossible to reach through EmailAudienceService (it throws
        // ContactBelongsToDifferentCompanyException), the same enforcement
        // style MarketingPresenceService::link() already uses; a DB-level
        // cross-table company match isn't expressible portably without
        // triggers, so this is an application-layer guarantee, documented
        // here rather than silently assumed.
        Schema::create('email_audience_members', function (Blueprint $table): void {
            // No surrogate `id` — Eloquent's default belongsToMany/attach()
            // pivot insert only writes the two FK columns + timestamps, so
            // a required `id` primary key here would need a pivot model
            // just to populate it. A composite primary key is both the
            // standard Eloquent pivot-table shape and exactly the
            // uniqueness constraint membership needs.
            $table->char('email_audience_id', 26);
            $table->char('email_contact_id', 26);
            $table->timestamps();

            $table->primary(['email_audience_id', 'email_contact_id']);
            $table->index('email_contact_id');
            $table->foreign('email_audience_id')->references('id')->on('email_audiences')->cascadeOnDelete();
            $table->foreign('email_contact_id')->references('id')->on('email_contacts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_audience_members');
    }
};
