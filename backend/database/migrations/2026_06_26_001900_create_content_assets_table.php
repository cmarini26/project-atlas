<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('content_assets', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->index();
            $table->char('campaign_id', 26)->index();
            $table->char('channel_id', 26)->index();
            $table->enum('type', ['social_post', 'email', 'sms', 'blog_post', 'ad_copy', 'landing_page']);
            $table->string('title')->nullable();
            $table->text('body');
            $table->json('media')->nullable();
            $table->json('metadata')->nullable();
            $table->string('prompt_name')->nullable();
            $table->string('prompt_version')->nullable();
            $table->enum('status', ['draft', 'approved', 'scheduled', 'published', 'archived'])->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index(['campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_assets');
    }
};
