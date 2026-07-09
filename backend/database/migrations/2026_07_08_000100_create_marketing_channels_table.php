<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_channels', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->index();
            $table->char('channel_id', 26)->nullable()->index();
            $table->enum('type', [
                'website', 'email', 'instagram', 'facebook', 'linkedin', 'x',
                'youtube', 'tiktok', 'google_business_profile', 'events', 'print', 'other',
            ]);
            $table->string('display_name');
            $table->string('handle_or_url')->nullable();
            $table->enum('status', ['active', 'occasional', 'planned', 'inactive'])->default('active');
            $table->enum('importance', ['primary', 'secondary', 'experimental'])->default('secondary');
            $table->json('objective'); // array, min 1 item — validated in the service layer, not a DB constraint
            $table->text('audience')->nullable();
            $table->enum('posting_frequency', ['daily', 'weekly', 'biweekly', 'monthly', 'quarterly', 'rarely', 'unknown'])
                ->default('unknown');
            $table->text('notes')->nullable();
            $table->boolean('is_connected')->default(false);
            $table->boolean('supports_publishing')->default(false);
            $table->boolean('supports_analytics')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'importance']);
            $table->index(['company_id', 'type']);
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('channel_id')->references('id')->on('channels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_channels');
    }
};
