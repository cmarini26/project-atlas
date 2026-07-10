<?php

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Covers Critical Production Blocker 7's core requirement: HTTPS detection,
 * HSTS, client IP resolution, and IP-keyed rate limiting must all behave
 * correctly — and only — when a request genuinely comes from a configured,
 * trusted proxy. See docs/plans/Critical-Production-Blockers.md and
 * docs/deployment/Production-Topology.md.
 *
 * TrustProxies::at()/flushState() set/clear the same static state
 * bootstrap/app.php configures from TRUSTED_PROXIES at boot — using it
 * directly here lets each test exercise a different trusted-proxy
 * configuration without re-booting the application.
 */
class TrustedProxyBehaviorTest extends TestCase
{
    use RefreshDatabase;

    private const TRUSTED_PROXY_IP = '203.0.113.9';

    private const REAL_CLIENT_IP = '198.51.100.42';

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    public function test_https_forwarded_by_a_trusted_proxy_is_treated_as_secure(): void
    {
        TrustProxies::at(self::TRUSTED_PROXY_IP);

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => self::TRUSTED_PROXY_IP,
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->get('http://localhost/login');

        $response->assertOk();
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_https_claimed_by_an_untrusted_proxy_is_ignored(): void
    {
        TrustProxies::at(self::TRUSTED_PROXY_IP);

        // Comes from a DIFFERENT IP than the one configured as trusted —
        // the forwarded header must be ignored, exactly the scenario a
        // hardcoded wildcard trust would have gotten wrong.
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '192.0.2.100',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->get('http://localhost/login');

        $response->assertOk();
        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_client_ip_resolves_to_the_real_client_behind_a_trusted_proxy(): void
    {
        TrustProxies::at(self::TRUSTED_PROXY_IP);

        $capturedIp = null;
        Route::get('/__test-client-ip', function (Request $request) use (&$capturedIp) {
            $capturedIp = $request->ip();

            return response()->noContent();
        });

        $this->withServerVariables([
            'REMOTE_ADDR' => self::TRUSTED_PROXY_IP,
            'HTTP_X_FORWARDED_FOR' => self::REAL_CLIENT_IP,
        ])->get('/__test-client-ip');

        $this->assertSame(self::REAL_CLIENT_IP, $capturedIp);
    }

    public function test_client_ip_is_not_spoofable_from_an_untrusted_proxy(): void
    {
        TrustProxies::at(self::TRUSTED_PROXY_IP);

        $capturedIp = null;
        Route::get('/__test-client-ip', function (Request $request) use (&$capturedIp) {
            $capturedIp = $request->ip();

            return response()->noContent();
        });

        $untrustedRemoteAddr = '192.0.2.100';

        $this->withServerVariables([
            'REMOTE_ADDR' => $untrustedRemoteAddr,
            'HTTP_X_FORWARDED_FOR' => self::REAL_CLIENT_IP,
        ])->get('/__test-client-ip');

        // The forwarded header is ignored — resolves to the direct
        // connection's own address, not the (untrusted) claimed one.
        $this->assertSame($untrustedRemoteAddr, $capturedIp);
    }

    public function test_rate_limiting_keys_by_the_real_client_ip_behind_a_trusted_proxy(): void
    {
        TrustProxies::at(self::TRUSTED_PROXY_IP);
        config(['services.postmark.webhook_secret' => '']);

        $fixture = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/Analytics/postmark-open.json')),
            true,
        );

        $sendAs = fn (string $clientIp) => $this->withServerVariables([
            'REMOTE_ADDR' => self::TRUSTED_PROXY_IP,
            'HTTP_X_FORWARDED_FOR' => $clientIp,
        ])->postJson('/api/analytics/webhooks/postmark', $fixture);

        // Two different real clients behind the same trusted proxy each get
        // their own 60/minute bucket — proving the limiter keys off the
        // resolved client IP, not the shared proxy IP every request arrives
        // from at the TCP level.
        for ($i = 0; $i < 60; $i++) {
            $sendAs(self::REAL_CLIENT_IP)->assertOk();
        }
        $sendAs(self::REAL_CLIENT_IP)->assertStatus(429);

        // A different client behind the same proxy is unaffected.
        $sendAs('198.51.100.77')->assertOk();
    }
}
