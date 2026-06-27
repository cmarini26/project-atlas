<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('execution_metrics', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->index();
            $table->char('execution_id', 26)->unique();
            $table->char('campaign_id', 26)->index();
            $table->string('channel_type', 50);
            $table->string('provider_type', 50);
            $table->string('platform_id', 255);
            $table->timestamp('retrieved_at')->nullable();
            $table->timestamp('window_closes_at')->nullable();
            $table->boolean('is_final')->default(false);
            $table->json('raw')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'is_final']);
            $table->index(['company_id', 'channel_type', 'retrieved_at']);

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('execution_id')->references('id')->on('executions')->cascadeOnDelete();
            $table->foreign('campaign_id')->references('id')->on('campaigns')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_metrics');
    }
};
