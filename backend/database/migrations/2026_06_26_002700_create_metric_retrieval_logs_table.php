<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('metric_retrieval_logs', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('execution_id', 26)->index();
            $table->string('provider_type', 50);
            $table->timestamp('attempted_at');
            $table->string('status'); // success | failed | skipped
            $table->text('error')->nullable();
            $table->smallInteger('response_code')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('execution_id')->references('id')->on('executions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_retrieval_logs');
    }
};
