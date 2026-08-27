<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_image_generations', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->index();
            $table->char('campaign_brief_id', 26)->nullable()->index();
            // pending → ready | failed. Rows are also the rolling-window ledger
            // the per-company generation cap is enforced against.
            $table->string('status')->default('pending')->index();
            $table->text('prompt')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('media_path')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->decimal('cost_usd', 10, 4)->default(0);
            $table->string('error')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('campaign_brief_id')->references('id')->on('campaign_briefs')->nullOnDelete();
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_image_generations');
    }
};
