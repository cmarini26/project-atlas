<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_connection_is_established(): void
    {
        // If connection fails, this throws — which is the failure we want to surface.
        $result = DB::selectOne('SELECT 1 AS connected');

        $this->assertEquals(1, $result->connected);
    }

    public function test_migrations_table_exists_after_migration_runs(): void
    {
        $this->assertTrue(Schema::hasTable('migrations'));
    }

    public function test_users_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('users'));
    }
}
