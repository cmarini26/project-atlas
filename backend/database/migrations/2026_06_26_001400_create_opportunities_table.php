<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('opportunities', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->index();
            $table->string('subject_type')->nullable();
            $table->char('subject_id', 26)->nullable();
            $table->enum('type', ['featured_item', 'urgency', 'new_arrival', 're_engagement', 'seasonal', 'milestone']);
            $table->string('title');
            $table->text('description');
            $table->unsignedTinyInteger('relevance_score')->default(0);
            $table->unsignedTinyInteger('timing_score')->default(0);
            $table->unsignedTinyInteger('confidence_score')->default(0);
            $table->unsignedTinyInteger('urgency_score')->default(0);
            $table->unsignedTinyInteger('composite_score')->default(0);
            $table->boolean('ai_detected')->default(false);
            $table->enum('status', ['open', 'selected', 'dismissed', 'expired'])->default('open');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('detected_at');
            $table->timestamps();

            $table->index(['company_id', 'status', 'composite_score']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunities');
    }
};
