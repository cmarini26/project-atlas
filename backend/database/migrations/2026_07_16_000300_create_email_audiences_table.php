<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('email_audiences', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->index();
            $table->string('name');
            // Deliberately no provider-specific identifier here (e.g. a
            // Postmark list ID) — an audience is Atlas's own concept of a
            // named group of contacts, resolved to recipients at send time,
            // not a mirror of any one provider's list/segment model.
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->timestamps();

            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'status']);
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_audiences');
    }
};
