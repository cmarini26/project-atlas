<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('catalogs', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->unique();
            $table->string('name')->default('Main Catalog');
            $table->enum('type', ['inventory', 'services', 'menu', 'listings', 'mixed'])->default('inventory');
            $table->json('item_schema')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogs');
    }
};
