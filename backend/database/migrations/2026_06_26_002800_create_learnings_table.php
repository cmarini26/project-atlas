<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('learnings', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->index();
            $table->string('source_type'); // approval | rejection | execution_result | edit | manual
            $table->char('source_id', 26);
            $table->string('subject_type'); // campaign | content_asset | opportunity_type | channel | catalog_item
            $table->char('subject_id', 26)->nullable();
            $table->string('signal');
            $table->json('value');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'applied_at']);
            $table->index(['company_id', 'signal']);

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learnings');
    }
};
