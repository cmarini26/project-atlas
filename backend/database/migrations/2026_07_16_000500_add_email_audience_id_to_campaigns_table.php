<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            // Distinct from the existing free-text `target_audience` column
            // (an AI-generated description like "collectors aged 25-45") —
            // this is a structured reference to a real, addressable list of
            // contacts. Nullable: only meaningful for a campaign that
            // actually targets the email channel, and even then selecting
            // one is optional in this slice (a campaign can exist without
            // ever choosing an audience).
            $table->char('email_audience_id', 26)->nullable()->after('recommendation_id')->index();
            $table->foreign('email_audience_id')->references('id')->on('email_audiences')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropForeign(['email_audience_id']);
            $table->dropColumn('email_audience_id');
        });
    }
};
