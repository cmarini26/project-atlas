<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26)->index();
            $table->string('approvable_type');
            $table->char('approvable_id', 26);
            $table->char('user_id', 26)->index();
            $table->enum('action', ['approved', 'rejected', 'edited_and_approved']);
            $table->text('notes')->nullable();
            $table->json('edits')->nullable();
            $table->timestamp('acted_at');
            $table->timestamps();

            $table->index(['approvable_type', 'approvable_id']);
            $table->index(['company_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approvals');
    }
};
