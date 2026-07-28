<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('source_assets', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->index();
            $table->char('observation_id', 26)->nullable()->index();
            $table->string('type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('source_url')->nullable();
            $table->string('media_path')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status')->default('processing')->index();
            $table->text('processing_error')->nullable();
            $table->string('content_fingerprint', 64);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('observation_id')->references('id')->on('observations')->nullOnDelete();
            $table->unique(['company_id', 'content_fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_assets');
    }
};
