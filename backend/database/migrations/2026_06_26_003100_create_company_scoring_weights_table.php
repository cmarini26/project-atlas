<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('company_scoring_weights', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->index();
            $table->json('weights');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_current')->default(true);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'is_current']);

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_scoring_weights');
    }
};
