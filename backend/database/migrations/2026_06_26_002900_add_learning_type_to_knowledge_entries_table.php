<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE knowledge_entries DROP CONSTRAINT IF EXISTS knowledge_entries_type_check');
            DB::statement("ALTER TABLE knowledge_entries ADD CONSTRAINT knowledge_entries_type_check CHECK (type IN ('pattern','insight','preference','performance','context','learning'))");
        }
        // SQLite: no enforcement — TEXT column accepts any value
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE knowledge_entries DROP CONSTRAINT IF EXISTS knowledge_entries_type_check');
            DB::statement("ALTER TABLE knowledge_entries ADD CONSTRAINT knowledge_entries_type_check CHECK (type IN ('pattern','insight','preference','performance','context'))");
        }
    }
};
