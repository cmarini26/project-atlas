<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            // Use char(26) tokenable_id to match ULID PKs on User and other models
            $table->char('tokenable_id', 26);
            $table->string('tokenable_type');
            $table->index(['tokenable_id', 'tokenable_type'], 'personal_access_tokens_tokenable_index');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
