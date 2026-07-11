<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->index();
            $table->enum('type', ['website_crawl', 'rss_feed', 'api', 'csv_upload', 'manual', 'instagram']);
            $table->string('name');
            $table->text('config');
            $table->enum('status', ['active', 'paused', 'error', 'disconnected'])->default('active');
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('last_successful_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index('next_run_at');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
