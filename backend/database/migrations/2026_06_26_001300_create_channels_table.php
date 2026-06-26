<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->nullable()->index();
            $table->enum('type', ['facebook', 'instagram', 'linkedin', 'x', 'email', 'sms', 'blog', 'landing_page']);
            $table->string('name');
            $table->text('config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
