<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('channel_credentials', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->index();
            $table->string('channel_type'); // facebook|instagram|linkedin|x|email|sms|blog|landing_page
            $table->string('provider_type')->nullable(); // mailchimp|klaviyo|postmark|twilio|vonage
            $table->text('credentials'); // encrypted JSON
            $table->string('status')->default('active'); // active|expired|error|revoked
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'channel_type']);
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_credentials');
    }
};
