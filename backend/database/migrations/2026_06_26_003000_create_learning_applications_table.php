<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('learning_applications', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->index();
            $table->char('learning_id', 26)->index();
            $table->json('effects');
            $table->timestamp('rolled_back_at')->nullable();
            $table->text('rollback_reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'learning_id']);

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('learning_id')->references('id')->on('learnings')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_applications');
    }
};
