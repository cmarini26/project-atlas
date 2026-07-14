<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('discovery_connector_attempts', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('discovery_run_id', 26)->index();
            $table->char('company_id', 26)->index();
            $table->char('marketing_channel_id', 26)->index();
            $table->char('integration_id', 26)->nullable()->index();
            $table->string('connector_type');
            $table->enum('status', ['pending', 'running', 'succeeded', 'failed', 'skipped_no_credentials'])
                ->default('pending');
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->char('observation_id', 26)->nullable()->index();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['discovery_run_id', 'status']);
            $table->foreign('discovery_run_id')->references('id')->on('discovery_runs')->cascadeOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('marketing_channel_id')->references('id')->on('marketing_channels')->cascadeOnDelete();
            $table->foreign('integration_id')->references('id')->on('integrations')->nullOnDelete();
            $table->foreign('observation_id')->references('id')->on('observations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_connector_attempts');
    }
};
