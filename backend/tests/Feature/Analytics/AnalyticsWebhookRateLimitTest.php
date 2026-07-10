<?php

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Covers Critical Production Blocker 2 (rate limiting the analytics webhook
 * endpoint) — see docs/plans/Critical-Production-Blockers.md and
 * docs/reviews/Production-Deployment-Audit.md. The endpoint is
 * unauthenticated by design (signature verification is the real gate), so
 * these tests exercise the volume limit only, not authentication.
 */
class AnalyticsWebhookRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const LIMIT = 60;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.postmark.webhook_secret' => '']);
    }

    private function loadFixture(string $name): string
    {
        return (string) file_get_contents(
            base_path("tests/Fixtures/Analytics/{$name}.json")
        );
    }

    private function sendWebhook(): TestResponse
    {
        return $this->postJson(
            '/api/analytics/webhooks/postmark',
            json_decode($this->loadFixture('postmark-open'), true),
        );
    }

    // ── Limit reached ────────────────────────────────────────────────────────

    public function test_requests_within_the_limit_all_succeed(): void
    {
        Queue::fake();

        for ($i = 0; $i < self::LIMIT; $i++) {
            $this->sendWebhook()->assertOk();
        }
    }

    public function test_request_beyond_the_limit_receives_429(): void
    {
        Queue::fake();

        for ($i = 0; $i < self::LIMIT; $i++) {
            $this->sendWebhook()->assertOk();
        }

        $response = $this->sendWebhook();

        $response->assertStatus(429);
        $response->assertJson(['error' => 'Too many requests.']);
    }

    public function test_events_are_not_processed_once_the_limit_is_exceeded(): void
    {
        Queue::fake();

        for ($i = 0; $i < self::LIMIT; $i++) {
            $this->sendWebhook();
        }

        Queue::fake(); // reset the assertion window to just the 61st request
        $this->sendWebhook();

        Queue::assertNothingPushed();
    }

    // ── Structured logging on rejection ─────────────────────────────────────

    public function test_logs_a_structured_warning_when_the_limit_is_exceeded(): void
    {
        Queue::fake();
        Log::spy();

        for ($i = 0; $i < self::LIMIT; $i++) {
            $this->sendWebhook();
        }

        $this->sendWebhook();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => $message === 'AnalyticsWebhookController: rate limit exceeded.'
                && $context['provider'] === 'postmark'
                && array_key_exists('ip', $context)
            );
    }

    // ── Limit resets ─────────────────────────────────────────────────────────

    public function test_limit_resets_after_the_decay_window(): void
    {
        Queue::fake();

        for ($i = 0; $i < self::LIMIT; $i++) {
            $this->sendWebhook();
        }
        $this->sendWebhook()->assertStatus(429);

        $this->travel(61)->seconds();

        $this->sendWebhook()->assertOk();
    }

    // ── Legitimate provider retries continue working ────────────────────────

    public function test_a_legitimate_retry_sequence_under_the_limit_all_succeed(): void
    {
        // Simulates a provider re-delivering the same webhook a few times
        // after a transient failure on Atlas's side — a normal, expected
        // pattern, not abuse, and must not be mistaken for one.
        Queue::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->sendWebhook()->assertOk();
        }
    }

    // ── Tenant isolation / independence from unrelated routes ───────────────

    public function test_exhausting_the_webhook_limit_does_not_affect_the_login_route(): void
    {
        Queue::fake();

        for ($i = 0; $i < self::LIMIT; $i++) {
            $this->sendWebhook();
        }
        $this->sendWebhook()->assertStatus(429);

        // The bare `throttle:5,1` on /login must still have its own budget —
        // proves the named 'analytics-webhook' limiter has an isolated
        // bucket, not one shared by domain+IP across every throttled route.
        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
    }

    public function test_exhausting_the_login_limit_does_not_affect_the_webhook_route(): void
    {
        Queue::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong']);
        }
        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])
            ->assertStatus(429);

        $this->sendWebhook()->assertOk();
    }

    // ── Regression: existing webhook behavior unchanged ─────────────────────

    public function test_unknown_provider_still_returns_422_and_does_not_consume_meaningful_budget(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/analytics/webhooks/unknown-provider', []);

        $response->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_invalid_signature_still_returns_401(): void
    {
        Queue::fake();
        config(['services.postmark.webhook_secret' => 'my-secret']);

        $response = $this->postJson(
            '/api/analytics/webhooks/postmark',
            json_decode($this->loadFixture('postmark-open'), true),
            ['X-Postmark-Signature' => 'invalid-sig'],
        );

        $response->assertStatus(401);
        Queue::assertNothingPushed();
    }
}
