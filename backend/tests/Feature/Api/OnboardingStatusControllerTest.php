<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingStatusControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/onboarding/status')->assertUnauthorized();
    }

    public function test_returns_nulls_when_user_has_no_membership(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/onboarding/status')
            ->assertOk()
            ->assertJson([
                'twin_status' => null,
                'integration_status' => null,
                'sync_started' => false,
                'fact_count' => 0,
                'opportunity_count' => 0,
                'recommendation_count' => 0,
                'first_recommendation_id' => null,
            ]);
    }

    public function test_returns_integration_status_active_before_first_sync(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
        Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'config' => ['url' => 'https://acme.com'],
            'status' => 'active',
            // last_run_at intentionally absent — sync not yet started
        ]);

        $this->actingAs($user)
            ->getJson('/api/onboarding/status')
            ->assertOk()
            ->assertJson([
                'integration_status' => 'active',
                'sync_started' => false,
                'fact_count' => 0,
            ]);
    }

    public function test_returns_sync_started_true_after_first_run(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
        Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'config' => ['url' => 'https://acme.com'],
            'status' => 'active',
            'last_run_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/onboarding/status')
            ->assertOk()
            ->assertJson([
                'integration_status' => 'active',
                'sync_started' => true,
            ]);
    }

    public function test_returns_error_status_when_integration_failed(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
        Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'config' => ['url' => 'https://bad-url.example'],
            'status' => 'error',
            'last_error' => 'Connection refused',
        ]);

        $this->actingAs($user)
            ->getJson('/api/onboarding/status')
            ->assertOk()
            ->assertJson([
                'integration_status' => 'error',
                'sync_started' => false,
            ]);
    }
}
