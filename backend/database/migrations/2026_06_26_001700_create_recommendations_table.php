<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('recommendations', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->index();
            $table->char('decision_id', 26)->nullable()->unique();
            $table->string('campaign_type')->nullable()->index();
            $table->string('title')->nullable();
            $table->text('summary')->nullable();
            $table->json('rationale_display')->nullable();
            $table->unsignedTinyInteger('confidence_score')->nullable();
            $table->json('expected_impact')->nullable();
            $table->enum('status', ['pending', 'viewed', 'approved', 'edited_and_approved', 'rejected'])->default('pending');
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'campaign_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendations');
    }
};
