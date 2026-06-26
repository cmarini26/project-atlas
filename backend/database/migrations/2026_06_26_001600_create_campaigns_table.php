<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->index();
            $table->char('decision_id', 26)->nullable()->index();
            $table->char('recommendation_id', 26)->nullable()->index();
            $table->string('campaign_type')->nullable()->index();
            $table->string('title');
            $table->text('strategy')->nullable();
            $table->text('target_audience')->nullable();
            $table->text('positioning')->nullable();
            $table->string('call_to_action')->nullable();
            $table->enum('status', ['draft', 'approved', 'scheduled', 'executing', 'completed', 'cancelled', 'archived'])->default('draft');
            $table->timestamp('scheduled_start_at')->nullable();
            $table->timestamp('scheduled_end_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'campaign_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
