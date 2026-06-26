<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_items', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('catalog_id', 26)->index();
            $table->char('company_id', 26)->index();
            $table->string('external_id')->nullable();
            $table->string('canonical_url')->nullable();
            $table->string('source_url')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'featured', 'sold', 'expired', 'archived'])->default('active');
            $table->decimal('price', 10, 2)->nullable();
            $table->json('media')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('promoted_at')->nullable();
            $table->timestamp('featured_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['external_id', 'company_id']);
            $table->index(['company_id', 'status']);
            $table->index(['catalog_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_items');
    }
};
