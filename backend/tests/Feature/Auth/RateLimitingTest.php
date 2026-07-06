<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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

    public function test_onboarding_website_submit_is_rate_limited(): void
    {
        // Each submit can queue a crawl + a 5-call AI pipeline run — real
        // spend — so the endpoint is capped at 3 requests per minute.
        Bus::fake();

        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'My Co', 'slug' => 'my-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user)
                ->post('/onboarding/integration', ['website_url' => 'https://example.com'])
                ->assertRedirect();
        }

        $this->actingAs($user)
            ->post('/onboarding/integration', ['website_url' => 'https://example.com'])
            ->assertStatus(429);
    }
}
