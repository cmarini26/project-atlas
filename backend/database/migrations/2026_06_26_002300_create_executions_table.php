<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('executions', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->index();
            $table->char('campaign_id', 26)->index();
            $table->char('content_asset_id', 26)->unique(); // one execution per asset
            $table->char('channel_id', 26)->index();
            $table->string('status')->default('queued'); // queued|executing|completed|failed|cancelled
            $table->timestamp('scheduled_at')->nullable(); // null = publish immediately
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->char('idempotency_key', 26)->unique();
            $table->json('result')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('campaign_id')->references('id')->on('campaigns')->cascadeOnDelete();
            $table->foreign('content_asset_id')->references('id')->on('content_assets')->cascadeOnDelete();
            $table->foreign('channel_id')->references('id')->on('channels')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('executions');
    }
};
