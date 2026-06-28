<?php

namespace Tests\Feature\Health;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthEndpointsTest extends TestCase
{
    // --- /api/health ---

    public function test_health_returns_200(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJsonFragment(['status' => 'ok']);
    }

    public function test_health_returns_version(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJsonStructure(['status', 'version']);
    }

    public function test_health_requires_no_authentication(): void
    {
        $response = $this->getJson('/api/health');

        // Must not redirect to login or return 401/403
        $response->assertOk();
    }

    // --- /api/live ---

    public function test_live_returns_200(): void
    {
        $response = $this->getJson('/api/live');

        $response->assertOk()
            ->assertJsonFragment(['status' => 'ok']);
    }

    public function test_live_requires_no_authentication(): void
    {
        $response = $this->getJson('/api/live');

        $response->assertOk();
    }

    // --- /api/ready ---

    public function test_ready_returns_200_when_all_checks_pass(): void
    {
        $response = $this->getJson('/api/ready');

        // In test env, DB and cache use in-memory SQLite + array driver — should pass
        $response->assertOk()
            ->assertJsonFragment(['status' => 'ok']);
    }

    public function test_ready_returns_checks_structure(): void
    {
        $response = $this->getJson('/api/ready');

        $response->assertJsonStructure([
            'status',
            'checks' => [
                'database',
                'cache',
                'queue',
            ],
        ]);
    }

    public function test_ready_requires_no_authentication(): void
    {
        $response = $this->getJson('/api/ready');

        // Ready check succeeds or returns 503 (degraded) — never 401/403
        $this->assertContains($response->status(), [200, 503]);
    }

    public function test_ready_returns_503_when_database_is_down(): void
    {
        DB::shouldReceive('select')->andThrow(new \RuntimeException('Connection refused'));

        $response = $this->getJson('/api/ready');

        $response->assertStatus(503)
            ->assertJsonFragment(['status' => 'degraded']);

        $body = $response->json();
        $this->assertSame('error', $body['checks']['database']['status']);
    }

    public function test_ready_returns_503_when_cache_is_down(): void
    {
        Cache::shouldReceive('put')->andThrow(new \RuntimeException('Redis down'));
        Cache::shouldReceive('forget')->andReturn(true);

        $response = $this->getJson('/api/ready');

        $response->assertStatus(503)
            ->assertJsonFragment(['status' => 'degraded']);

        $body = $response->json();
        $this->assertSame('error', $body['checks']['cache']['status']);
    }

    public function test_database_check_passes_in_test_environment(): void
    {
        $response = $this->getJson('/api/ready');
        $body = $response->json();

        $this->assertSame('ok', $body['checks']['database']['status']);
    }

    public function test_cache_check_passes_in_test_environment(): void
    {
        $response = $this->getJson('/api/ready');
        $body = $response->json();

        $this->assertSame('ok', $body['checks']['cache']['status']);
    }
}
