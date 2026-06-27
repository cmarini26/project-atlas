<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_entries', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->index();
            $table->enum('type', ['pattern', 'insight', 'preference', 'performance', 'context', 'learning']);
            $table->string('subject');
            $table->text('body');
            $table->json('structured')->nullable();
            $table->json('source_fact_ids')->nullable();
            $table->unsignedTinyInteger('confidence')->default(50);
            $table->boolean('is_active')->default(true);
            $table->timestamp('generated_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies');

            $table->index(['company_id', 'type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_entries');
    }
};
