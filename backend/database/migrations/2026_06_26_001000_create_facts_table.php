<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('facts', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->index();
            $table->char('observation_id', 26)->nullable()->index();
            $table->string('key');
            $table->json('value');
            $table->enum('data_type', ['integer', 'float', 'string', 'boolean', 'json']);
            $table->unsignedTinyInteger('confidence')->default(50);
            $table->boolean('is_current')->default(true);
            $table->char('superseded_by_id', 26)->nullable();
            $table->timestamp('valid_from')->useCurrent();
            $table->timestamp('valid_until')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('observation_id')->references('id')->on('observations')->nullOnDelete();

            $table->index(['company_id', 'key', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facts');
    }
};
