<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('decisions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->index();
            $table->char('opportunity_id', 26)->unique();
            $table->enum('campaign_type', ['featured_item', 'urgency_promotion', 're_engagement', 'seasonal']);
            $table->json('channel_ids');
            $table->json('rationale');
            $table->unsignedTinyInteger('confidence_score')->default(0);
            $table->text('expected_outcome')->nullable();
            $table->json('expected_impact')->nullable();
            $table->enum('status', ['pending', 'recommended', 'approved', 'rejected', 'executed', 'cancelled'])->default('pending');
            $table->string('prompt_version')->default('1.0');
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decisions');
    }
};
