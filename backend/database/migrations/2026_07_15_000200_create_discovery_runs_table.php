<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('discovery_runs', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->index();
            $table->enum('stage', [
                'discovering', 'analyzing', 'understanding', 'recommending',
                'completed', 'completed_with_errors',
            ])->default('discovering');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'started_at']);
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_runs');
    }
};
