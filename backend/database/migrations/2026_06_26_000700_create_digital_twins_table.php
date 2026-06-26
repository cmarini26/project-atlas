<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('digital_twins', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->unique();
            $table->enum('status', ['initializing', 'active', 'stale', 'archived'])->default('initializing');
            $table->unsignedTinyInteger('health_score')->default(0);
            $table->timestamp('last_observed_at')->nullable();
            $table->timestamp('last_enriched_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_twins');
    }
};
