<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_attempts_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', [
                'email' => 'attacker@example.com',
                'password' => 'wrong-password',
            ])->assertRedirect();
        }

        $this->post('/login', [
            'email' => 'attacker@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_registration_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            // Invalid payloads still count against the limiter.
            $this->post('/register', ['email' => "user{$i}"])->assertRedirect();
        }

        $this->post('/register', ['email' => 'user6'])->assertStatus(429);
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/forgot-password', ['email' => 'nobody@example.com'])->assertRedirect();
        }

        $this->post('/forgot-password', ['email' => 'nobody@example.com'])->assertStatus(429);
    }

    // The old website-submit throttle test was removed along with the route
    // it covered: Milestone 15 Phase 1's redesigned onboarding never queues a
    // crawl or any connector from onboarding itself (see
    // docs/specs/Business-Discovery-Onboarding.md) — no onboarding endpoint
    // carries the "real spend" risk that throttle existed to prevent.
    // Discovery (a future phase) is where that concern reappears.
}
