<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('observations', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->index();
            $table->char('integration_id', 26)->nullable()->index();
            $table->enum('source_type', ['crawl', 'feed', 'api', 'manual', 'internal']);
            $table->string('source_identifier');
            $table->longText('raw_payload')->nullable();
            $table->string('raw_payload_ref')->nullable();
            $table->enum('status', ['pending', 'processing', 'processed', 'failed', 'retrying'])->default('pending');
            $table->timestamp('observed_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['integration_id', 'observed_at']);
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('integration_id')->references('id')->on('integrations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observations');
    }
};
