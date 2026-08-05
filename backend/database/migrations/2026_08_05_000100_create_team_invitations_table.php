<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('team_invitations', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->index();
            $table->string('email');
            $table->string('normalized_email');
            $table->enum('role', ['admin', 'member', 'viewer']);
            $table->char('invited_by', 26);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'normalized_email']);
            $table->index(['company_id', 'accepted_at', 'revoked_at']);
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('invited_by')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_invitations');
    }
};
