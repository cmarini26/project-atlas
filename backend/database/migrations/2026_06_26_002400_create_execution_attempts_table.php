<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('execution_attempts', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('execution_id', 26)->index();
            $table->smallInteger('attempt_number');
            $table->timestamp('attempted_at');
            $table->string('status'); // completed|failed
            $table->text('error')->nullable();
            $table->json('response')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('execution_id')->references('id')->on('executions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_attempts');
    }
};
