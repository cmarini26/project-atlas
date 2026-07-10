<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * Covers Critical Production Blocker 3 (HTTPS enforcement + security
 * headers) — see docs/plans/Critical-Production-Blockers.md and
 * docs/reviews/Production-Deployment-Audit.md. Asserts the headers land on
 * every kind of response surface the app serves: a plain Inertia page, a
 * JSON API response, and the Filament admin panel (which builds its own
 * middleware stack rather than reusing the 'web' group).
 */
class SecurityHeadersTest extends TestCase
{
    public function test_headers_are_present_on_an_inertia_web_response(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Content-Security-Policy', "frame-ancestors 'none'; object-src 'none'; base-uri 'self'");
    }

    public function test_headers_are_present_on_a_json_api_response(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_headers_are_present_on_the_filament_admin_login_page(): void
    {
        $response = $this->get('/admin/login');

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_hsts_is_absent_over_a_plain_http_request(): void
    {
        // APP_URL is https in this environment, so requests default to a
        // secure scheme unless the URL explicitly overrides it — force http
        // here to exercise the genuinely-insecure path.
        $response = $this->get('http://localhost/login');

        $response->assertOk();
        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_present_over_a_secure_request(): void
    {
        $response = $this->get('https://localhost/login');

        $response->assertOk();
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
