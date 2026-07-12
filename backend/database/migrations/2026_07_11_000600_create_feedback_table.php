<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 19 — Early Customer Feedback Tooling. One row per NPS-style
 * submission (1-10 score, optional free text). The comment length limit
 * (500 chars) and score range are validated at the controller, not the
 * column — this table just needs to store what was already validated.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->index();
            $table->char('user_id', 26)->index();
            $table->unsignedTinyInteger('score');
            $table->string('comment', 500)->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
